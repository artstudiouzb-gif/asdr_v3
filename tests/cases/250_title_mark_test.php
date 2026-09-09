<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Core\DesignSettings;
use App\Core\LocalGoogleFonts;
use App\Core\TitleMarkup;

// Выделение слова в заголовке звёздочками. Разметка живёт внутри самой строки,
// поэтому у неё две обязанности: не пускать HTML внутрь заголовка и не
// протекать звёздочками туда, где разметки быть не может (атрибуты, оглавление).

test('Звёздочки превращаются в span, а HTML внутри заголовка остаётся текстом', function () {
    assert_same(
        'Стратегия <span class="tx-mark">развития</span> региона',
        TitleMarkup::html('Стратегия *развития* региона')
    );

    // Экранирование идёт ПЕРВЫМ, поэтому тег из заголовка не становится
    // разметкой — ни сам по себе, ни при попытке спрятаться в выделении.
    assert_same('&lt;b&gt;жирно&lt;/b&gt;', TitleMarkup::html('<b>жирно</b>'));
    assert_not_contains('<img', TitleMarkup::html('*<img src=x onerror=alert(1)>*'));

    // Кавычка в заголовке не должна закрывать атрибут в шаблоне.
    assert_contains('&quot;', TitleMarkup::html('Указ "О мерах"'));
});

test('Одинокая звёздочка и знак умножения остаются текстом', function () {
    assert_same('Пункт 5 * 3', TitleMarkup::html('Пункт 5 * 3'));
    assert_same('Итог * без пары', TitleMarkup::html('Итог * без пары'));

    // Пробел сразу за звёздочкой — не открытие выделения: «5 * 3 = 15 * 5»
    // иначе красило бы середину выражения.
    assert_not_contains('tx-mark', TitleMarkup::html('5 * 3 = 15 * 5'));

    // Пустое выделение тоже не разметка.
    assert_same('**', TitleMarkup::html('**'));
});

test('plain() снимает звёздочки, не добавляя разметки', function () {
    assert_same('Стратегия развития', TitleMarkup::plain('Стратегия *развития*'));
    // Ничего не экранирует: результат уходит в htmlspecialchars по месту
    // или в неразметочный контекст (RSS, aria-label, оглавление).
    assert_same('Указ "О мерах"', TitleMarkup::plain('Указ "О мерах"'));
    assert_true(TitleMarkup::has('a *b*'));
    assert_true(!TitleMarkup::has('a b'));
});

test('Заголовок секции выделение показывает, а оглавление — нет', function () {
    $rendered = BlockRenderer::render([
        'id' => 51,
        'type' => 'text',
        'data' => json_encode(['title' => 'Итоги *2026* года', 'content' => 'Текст'], JSON_UNESCAPED_UNICODE),
    ]);
    assert_contains('<span class="tx-mark">2026</span>', (string) $rendered['html']);

    // Пункт оглавления — текст ссылки: звёздочек в нём быть не должно.
    BlockRenderer::renderPage([
        ['id' => 51, 'type' => 'text', 'data' => json_encode(['title' => 'Итоги *2026* года', 'content' => 'Текст'], JSON_UNESCAPED_UNICODE)],
    ]);
    $sections = BlockRenderer::pageSections();
    assert_same('Итоги 2026 года', $sections[0]['label']);
});

test('Вид выделения приходит из «Дизайна» классом на body', function () {
    $base = DesignSettings::PRESETS['modern']['values'];

    // Умолчание — акцентный цвет: настройки ещё нет, а разметка уже может быть.
    assert_contains('design-mark-accent', DesignSettings::bodyClasses($base));
    assert_contains('design-mark-script', DesignSettings::bodyClasses(['title_mark' => 'script'] + $base));
    assert_contains('design-mark-off', DesignSettings::bodyClasses(['title_mark' => 'off'] + $base));
    // Чужое значение не уходит в класс.
    assert_contains('design-mark-accent', DesignSettings::bodyClasses(['title_mark' => 'evil"'] + $base));

    $theme = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');
    foreach (array_keys(DesignSettings::OPTIONS['title_mark']['choices']) as $choice) {
        if ($choice === 'off') {
            continue; // «Без выделения» — это отсутствие правил, а не правило.
        }
        assert_contains('body.design-mark-' . $choice . ' .tx-mark', $theme);
    }
});

test('Рукописный шрифт — отдельный каталог с полной кириллицей', function () {
    // Рукописное семейство не должно предлагаться для текста сайта.
    foreach (array_keys(DesignSettings::SCRIPT_FONTS) as $slug) {
        assert_true(
            !isset(DesignSettings::GOOGLE_FONTS[$slug]),
            'рукописный шрифт не место в каталоге основного текста: ' . $slug
        );
    }

    // Установщик умеет скачивать оба каталога — иначе выбор шрифта в форме
    // сохранялся бы, а файлов на сервере не появлялось.
    $catalog = DesignSettings::fontCatalog();
    foreach (array_keys(DesignSettings::SCRIPT_FONTS) as $slug) {
        assert_true(isset($catalog[$slug]), 'нет в общем каталоге установки: ' . $slug);
    }

    // Формат запроса обязателен для проверки покрытия подмножеств.
    foreach (DesignSettings::SCRIPT_FONTS as $slug => $font) {
        assert_true(
            preg_match('/^[A-Za-z+0-9]+:wght@[0-9;]+$/', $font[2]) === 1,
            'запрос к Google Fonts не разберётся проверкой покрытия: ' . $slug
        );
    }

    // Шрифт не выбран — роль повторяет заголовочный стек, а не пропадает.
    assert_same('', DesignSettings::scriptFontStack());
    assert_contains('--font-script', (string) file_get_contents(APP_ROOT . '/app/Core/SiteThemeCss.php'));

    // Никаких внешних запросов: подключается только локальный файл.
    $local = (string) file_get_contents(APP_ROOT . '/app/Core/LocalGoogleFonts.php');
    assert_contains("Setting::get('design_font_script'", $local);
    assert_true(class_exists(LocalGoogleFonts::class));
});

test('Проявление заголовков: класс на body, правила в теме и только через JS', function (): void {
    $base = ['catalog_layout' => 'list', 'sidebar_position' => 'fixed', 'card_style' => 'flat',
        'detail_layout' => 'sidebar', 'scroll_top' => 'on', 'title_mark' => 'accent'];

    // Умолчание — без анимации: включать её всем без спроса нельзя.
    assert_not_contains('design-title-', DesignSettings::bodyClasses($base));
    assert_contains('design-title-fade', DesignSettings::bodyClasses(['title_reveal' => 'fade'] + $base));
    assert_contains('design-title-wipe', DesignSettings::bodyClasses(['title_reveal' => 'wipe'] + $base));
    // Значение вне набора — подделанная форма, а не «ближайшее допустимое».
    assert_not_contains('design-title-', DesignSettings::bodyClasses(['title_reveal' => 'evil"'] + $base));

    $theme = theme_css();
    foreach (['fade', 'wipe'] as $choice) {
        assert_contains('body.design-title-' . $choice . ' .is-title-reveal', $theme);
    }
    // Заливка текста снимается только там, где текст можно закрасить фоном:
    // иначе заголовок стал бы невидимым вместо бледного.
    assert_contains('@supports ((-webkit-background-clip: text) or (background-clip: text))', $theme);

    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/frontend.js');
    // Бледное состояние вешает только скрипт — иначе без JS заголовок
    // оставался бы бледным навсегда.
    assert_contains("classList.add('is-title-reveal')", $js);
    assert_contains("classList.remove('is-title-reveal')", $js);
    // …и не вешает при «меньше движения» и в режимах контраста.
    assert_contains('asdrReduceMotion', $js);
    assert_contains("getAttribute('data-a11y-contrast')", $js);
});

test('Скелетон-заглушка видима на обеих темах и без мёртвых близнецов', function (): void {
    $frontend = (string) file_get_contents(APP_ROOT . '/public/assets/css/frontend.css');

    // Белый перелив поверх светлой поверхности не виден вовсе — подмешиваем
    // цвет текста, тогда приём работает и на светлой теме, и на тёмной.
    assert_contains('.skeleton::before', $frontend);
    assert_not_contains('rgba(255,255,255,.18)', $frontend);
    assert_contains('color-mix(in srgb, var(--text-main, #0f172a) 12%, transparent)', $frontend);

    // Второй набор правил под ту же задачу никем не использовался: класс без
    // потребителя читается как рабочий приём и живёт годами.
    foreach (glob(APP_ROOT . '/public/assets/css/*.css') ?: [] as $file) {
        if (str_contains($file, '.min.')) {
            continue;
        }
        assert_not_contains('media-skeleton', (string) file_get_contents($file), basename($file));
    }
});

test('Заголовок: черта даёт принудительный перенос строки, а не текст', function (): void {
    $markup = \App\Core\TitleMarkup::class;

    assert_same(
        'Агентство стратегического<br>развития и реформ',
        $markup::html('Агентство стратегического | развития и реформ')
    );
    // Пробелы вокруг черты не обязательны, а две черты подряд — это один
    // перенос: редактор ставит черту там, где ему удобно её видеть.
    assert_same('а<br>б', $markup::html('а|б'));
    assert_same('а<br>б', $markup::html('а || б'));
    // Черта с краю переносить нечего — пустая строка сверху читалась бы как
    // лишний отступ.
    assert_same('край', $markup::html('| край'));
    assert_same('край', $markup::html('край |'));
    // Разметка выделения и перенос уживаются в одной строке.
    assert_same(
        'Стратегия <span class="tx-mark">развития</span><br>региона',
        $markup::html('Стратегия *развития* | региона')
    );

    // Тег в заголовке по-прежнему остаётся текстом: правило «поля-заголовки
    // не принимают HTML» важнее удобства записи, потому перенос и сделан
    // чертой, а не <br>.
    assert_contains('&lt;br&gt;', $markup::html('а <br> б'));

    // Где разметка недопустима (title=, meta, поиск), перенос становится
    // пробелом: склейка «АгентствоРазвития» — уже другое слово.
    assert_same('Агентство развития', $markup::plain('Агентство | развития'));
    assert_same('а б', $markup::plain('а|б'));
    assert_true($markup::hasBreak('а | б'));
    assert_false($markup::hasBreak('а б'));

    // Косая черта разметкой не является: «24/7» — обычный текст.
    assert_same('24/7 работа', $markup::html('24/7 работа'));
});

test('Заголовок: разметку строки объясняет подсказка у каждого поля заголовка', function (): void {
    $field = \App\Core\BlockData\Field::text('Заголовок, показываемый на сайте')->named('title_field');
    assert_same(\App\Core\BlockData\Field::TITLE_MARKUP_HINT, $field->hint);
    assert_contains('|', $field->hint);

    // Своя подсказка поля важнее общей: там объясняют что-то более частное.
    $own = \App\Core\BlockData\Field::text('Заголовок', '', 'Своя подсказка')->named('title_field');
    assert_same('Своя подсказка', $own->hint);

    // Обложка — не блок, её форма своя, и подсказку туда надо ставить руками.
    $heroForm = (string) file_get_contents(APP_ROOT . '/app/Views/admin/heroes/slide_form.php');
    assert_contains('TITLE_MARKUP_HINT', $heroForm);
});
