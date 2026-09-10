<?php

declare(strict_types=1);

use App\Core\EarlyHints;
use App\Core\PublicResponseCache;
use App\Core\SpeculationRules;

/**
 * Упреждающая загрузка страниц и ранняя подсказка о критических ресурсах.
 *
 * Оба приёма работают «в сторону браузера» и потому отказывают тихо: правило
 * с опечаткой браузер просто игнорирует, а preload по адресу, которого нет в
 * разметке, не ускоряет ничего и вдобавок качает файл второй раз. Заметить
 * это по виду страницы нельзя — только по заголовкам и вкладке «Сеть».
 */

test('Упреждать нельзя приватные адреса, и список у них общий с кешем', function (): void {
    $patterns = SpeculationRules::excludedPatterns(['ru', 'uz'], 'ru');

    // Второго списка приватных путей в проекте нет: правила читают тот же,
    // что и общий кеш. Разъехавшись, они дали бы упреждающий запрос в
    // админку или на выдачу файла.
    foreach (PublicResponseCache::privatePaths() as $private) {
        assert_true(in_array($private, $patterns, true), $private . ': нужен шаблон на сам путь');
        // URLPattern не считает «/admin» совпадением с «/admin/*», поэтому у
        // пути-раздела два шаблона. У пути-файла вложенных адресов не бывает,
        // и второй шаблон был бы мусором в разметке каждой страницы.
        assert_same(
            !str_contains($private, '.'),
            in_array($private . '/*', $patterns, true),
            $private . ': шаблон вложенных адресов нужен только разделу'
        );
    }

    // Языковой префикс — по списку активных языков, кроме основного: его
    // адреса на сайте печатаются без префикса, ссылок на «/ru/...» в разметке
    // не бывает.
    assert_true(in_array('/uz/admin/*', $patterns, true), 'языковой префикс учтён');
    assert_false(in_array('/ru/admin', $patterns, true), 'основной язык не удваивает список');
    // До разрешения языка запрос к файлу и статике не доходит.
    assert_false(in_array('/uz/sitemap.xml', $patterns, true), 'файлу префикс не нужен');
    assert_false(in_array('/uz/assets/*', $patterns, true), 'статике префикс не нужен');

    // Переключатель письменности ставит cookie: упреждающий запрос сменил бы
    // её без ведома посетителя.
    assert_true(in_array('/script/*', $patterns, true), '/script/{code} не упреждается');
    // Случайная цель отдаётся с no-store и меняется на каждый запрос.
    assert_true(in_array('/goals/*', $patterns, true), '/goals/random не упреждается');
    // Машинные ответы и файлы: браузер по ним не «переходит».
    foreach (['/sitemap.xml', '/rss.xml', '/robots.txt', '/uploads/*', '/assets/*'] as $machine) {
        assert_true(in_array($machine, $patterns, true), $machine . ' не упреждается');
    }

    // Обычная страница под исключение не попадает — иначе приём не работал бы
    // вовсе, и понять это было бы можно только замером.
    assert_false(in_array('/o-agentstve', $patterns, true), 'обычная страница упреждается');
});

test('Пререндер идёт по нажатию, префетч — по наведению', function (): void {
    $prefetchOnly = SpeculationRules::rules('prefetch', ['ru']);
    assert_true(isset($prefetchOnly['prefetch']), 'префетч объявлен');
    assert_false(isset($prefetchOnly['prerender']), 'в режиме префетча пререндера нет');
    assert_same('moderate', $prefetchOnly['prefetch'][0]['eagerness']);

    $full = SpeculationRules::rules('prerender', ['ru']);
    // Пререндер — это запрос плюс отрисовка в скрытой вкладке. По наведению
    // курсора он превратил бы каждое движение мыши в сборку страницы на
    // shared-хостинге, поэтому ждёт явного намерения — нажатия кнопки.
    assert_same('conservative', $full['prerender'][0]['eagerness']);
    assert_same('moderate', $full['prefetch'][0]['eagerness']);

    assert_same([], SpeculationRules::rules('off', ['ru']), 'выключено — правил нет');
});

test('Правила ограничены своим origin и не трогают файлы и внешние ссылки', function (): void {
    $where = SpeculationRules::rules('prerender', ['ru'])['prefetch'][0]['where'];

    // Относительный шаблон — это тот же origin. Чужие домены упреждать нельзя
    // ни по трафику, ни по приватности.
    assert_same('/*', $where['and'][0]['href_matches']);

    $selector = $where['and'][2]['not']['selector_matches'];
    foreach (['[download]', '[target="_blank"]', '[rel~="nofollow"]', '[data-no-speculation]'] as $needle) {
        assert_contains($needle, $selector);
    }
});

test('Разметка правил не несёт nonce — иначе ETag страницы перестал бы совпадать', function (): void {
    $footer = (string) file_get_contents(APP_ROOT . '/app/Views/site/_footer.php');
    assert_contains('SpeculationRules::scriptHtml()', $footer);

    // Nonce меняется каждый запрос, а ETag считается от готового тела: с ним
    // «304» не совпал бы никогда, и вместо пустого ответа посетитель получал
    // бы страницу целиком. Поэтому тип разрешён в CSP отдельным источником.
    $csp = \App\Core\SecurityHeaders::publicCsp('n0nce', []);
    assert_contains("'inline-speculation-rules'", $csp);

    $html = SpeculationRules::scriptHtml();
    if ($html !== '') {
        assert_contains('<script type="speculationrules">', $html);
        assert_not_contains('nonce', $html);
    }
});

test('Скрипты, обращающиеся к посетителю, ждут показа страницы', function (): void {
    $themeInit = (string) file_get_contents(APP_ROOT . '/public/assets/js/theme-init.js');
    assert_contains('asdrWhenActivated', $themeInit);
    assert_contains('prerenderingchange', $themeInit);

    // Пререндер выполняет страницу заранее и в скрытой вкладке. Таймер
    // «предложить подписку через 15 секунд» истёк бы там, и карточка встретила
    // бы посетителя уже открытой; счётчик посчитал бы визит, которого не было.
    foreach (['push.js', 'consent.js'] as $script) {
        $source = (string) file_get_contents(APP_ROOT . '/public/assets/js/' . $script);
        assert_contains('asdrWhenActivated', $source, $script . ': работа ждёт активации документа');
    }
});

test('Ранняя подсказка печатается только для навигации по сайту', function (): void {
    assert_true(EarlyHints::isDocumentRequest('/news', 'GET', 'text/html,application/xhtml+xml'));
    assert_true(EarlyHints::isDocumentRequest('/', 'HEAD', 'text/html'));

    // Подзапрос за картинкой или скриптом просит не text/html: подсказка про
    // таблицу стилей ему не адресована, а заголовки едут в каждом ответе.
    assert_false(EarlyHints::isDocumentRequest('/captcha.png', 'GET', 'image/avif,image/webp,*/*'));
    // Изменяющий запрос ответом-документом не заканчивается.
    assert_false(EarlyHints::isDocumentRequest('/news', 'POST', 'text/html'));
    // У админки свой CSS: подсказка про публичный бандл увела бы её канал на
    // ненужный файл.
    assert_false(EarlyHints::isDocumentRequest('/admin/pages', 'GET', 'text/html'));
    // Машинные ответы.
    assert_false(EarlyHints::isDocumentRequest('/sitemap.xml', 'GET', 'text/html'));
});

test('Адрес в подсказке совпадает с адресом в разметке', function (): void {
    // Прежде здесь стоял литерал «/assets/css/public.min.css» — без «?v=» и
    // без префикса CDN, — а разметка печатала адрес через Asset::url(). Это
    // два разных ресурса: preload не засчитывался, и бандл качался дважды.
    $index = (string) file_get_contents(APP_ROOT . '/public/index.php');
    assert_contains('EarlyHints::send()', $index);
    assert_not_contains("header('Link: </assets/css/public.min.css>", $index);

    $links = EarlyHints::links();
    if ($links === []) {
        skip_test('нет собранного бандла — сверять нечего');
        return;
    }

    assert_contains('rel=preload; as=style', $links[0]);
    assert_contains(\App\Core\Asset::url(\App\Core\FrontendAssets::styles()[0]), $links[0]);

    // Шрифты берутся из того же списка, что печатает шапка: два списка
    // разъехались бы при первой смене шрифта, и подсказка тянула бы файл,
    // которым ничего не набрано.
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    assert_contains('FrontendAssets::bundledFontPreloads()', $header);
    foreach (\App\Core\FrontendAssets::bundledFontPreloads() as $font) {
        $matched = array_filter($links, static fn (string $l): bool => str_contains($l, '<' . $font . '>'));
        assert_true($matched !== [], $font . ': шрифт назван в подсказке');
        assert_contains('crossorigin', implode('', $matched));
    }

    // Больше трёх подсказок — уже не подсказка, а конкуренция за канал.
    assert_true(count($links) <= 3, 'подсказка короткая: ' . count($links));
});
