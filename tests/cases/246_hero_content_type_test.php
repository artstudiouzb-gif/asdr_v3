<?php

declare(strict_types=1);

use App\Core\BlockData\HeroBlockNormalizer;
use App\Core\BlockTypeRegistry;
use App\Core\Hero\HeroPresets;
use App\Core\Hero\HeroRenderer;
use App\Core\Hero\HeroSettings;
use App\Core\Hero\HeroSlideData;

/**
 * Обложка как отдельный тип контента.
 *
 * Проверяем то, что нельзя увидеть на скриншоте: контракт настроек, порядок
 * слоёв в разметке, наличие кадра-замены у каждого видео и то, что цветовая
 * схема обложки не красит вложенные компоненты.
 */

/** Слайд для рендера: строка hero_slides с уже разобранным data. */
function hero_test_slide(array $data, int $id = 1): array
{
    return [
        'id' => $id,
        'hero_id' => 1,
        'title' => (string) ($data['title'] ?? ''),
        'sort_order' => 0,
        'is_active' => 1,
        'data' => HeroSlideData::withDefaults($data),
    ];
}

test('Настройки обложки: неизвестные значения заменяются умолчаниями', function () {
    $settings = HeroSettings::normalize([
        'width' => 'wide-please',
        'height' => 'custom',
        'height_value' => '5000',
        'height_unit' => 'px',
        'scheme' => 'rainbow',
        'content_scheme' => 'auto',
        'overlay' => 'gradient',
        'overlay_opacity' => '150',
        'nav_indicator' => 'carousel-dots-3d',
        'transition' => 'explode',
        'autoplay' => '1',
        'autoplay_interval' => '99',
    ]);

    assert_same('full', $settings['width'], 'чужая ширина заменяется умолчанием');
    assert_same('2400px', $settings['height_value'], 'высота ограничена сверху');
    assert_same('navy', $settings['scheme']);
    assert_same(100, $settings['overlay_opacity'], 'плотность затемнения не больше 100');
    assert_same('counter_progress', $settings['nav_indicator']);
    assert_same('fade_slide', $settings['transition']);
    assert_true($settings['autoplay'], 'галочка автопрокрутки читается');
    assert_same(30, $settings['autoplay_interval'], 'интервал ограничен сверху');
});

test('Настройки обложки: чтение старой записи дополняется умолчаниями', function () {
    // Запись, сделанная до появления половины ключей, плюс мусор, которого в
    // контракте нет: шаблон не должен получить ни null, ни лишнего.
    $settings = HeroSettings::withDefaults(['scheme' => 'light', 'evil_key' => '<script>']);

    assert_same('light', $settings['scheme']);
    assert_same(6, $settings['autoplay_interval'], 'недостающий ключ приходит с умолчанием');
    assert_true(!array_key_exists('evil_key', $settings), 'ключей вне контракта в настройках нет');
    assert_same(
        array_keys(HeroSettings::defaults()),
        array_keys($settings),
        'набор ключей совпадает с контрактом'
    );
});

test('Слайд: ссылка на YouTube превращается в идентификатор ролика', function () {
    foreach ([
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'https://youtu.be/dQw4w9WgXcQ',
        'https://www.youtube.com/embed/dQw4w9WgXcQ',
    ] as $url) {
        $slide = HeroSlideData::normalize(['youtube_url' => $url]);
        assert_same('dQw4w9WgXcQ', $slide['youtube_id'], $url . ' — идентификатор выделен');
        assert_same('youtube', $slide['media_type'], 'заполненное медиа само выбирает тип фона');
    }

    $broken = HeroSlideData::normalize(['youtube_url' => 'https://example.com/not-a-video']);
    assert_same('', $broken['youtube_id'], 'из чужой ссылки идентификатор не берётся');
    assert_same('none', $broken['media_type']);
});

test('Слайд: небезопасные адреса не сохраняются', function () {
    $slide = HeroSlideData::normalize([
        'image' => 'javascript:alert(1)',
        'video_url' => 'https://evil.example/x.mp4',
        'link_url' => ' javascript:alert(2) ',
        'cta_url' => ' /about ',
    ]);

    assert_same('', $slide['image']);
    assert_same('', $slide['link_url']);
    assert_same('/about', $slide['cta_url'], 'обычная ссылка проходит');
});

test('Слайд: пустое оформление означает «как у обложки», а не «выключено»', function () {
    $slide = HeroSlideData::normalize(['title' => 'Заголовок']);

    assert_same('', $slide['overlay'], 'затемнение наследуется от обложки');
    assert_same(-1, $slide['overlay_opacity'], 'плотность наследуется, а 0 остаётся значимым');
    assert_same('', $slide['content_scheme'], 'цвет текста наследуется от обложки');

    // Явное «нет» отличается от «как у обложки».
    $off = HeroSlideData::normalize(['title' => 'Т', 'overlay' => 'none', 'overlay_opacity' => '0']);
    assert_same('none', $off['overlay']);
    assert_same(0, $off['overlay_opacity']);
});

test('Слайд: кадр-замена берётся по приоритету, пустой слайд не считается слайдом', function () {
    $withPoster = HeroSlideData::withDefaults(['poster' => '/uploads/public/p.jpg', 'image' => '/uploads/public/i.jpg']);
    assert_same('/uploads/public/p.jpg', HeroSlideData::fallbackImage($withPoster), 'постер важнее картинки');

    $imageOnly = HeroSlideData::withDefaults(['image' => '/uploads/public/i.jpg']);
    assert_same('/uploads/public/i.jpg', HeroSlideData::fallbackImage($imageOnly));

    assert_true(HeroSlideData::isEmpty(HeroSlideData::defaults()), 'незаполненный слайд пуст');
    assert_true(
        !HeroSlideData::isEmpty(HeroSlideData::withDefaults(['title' => 'Есть заголовок'])),
        'слайд с одним заголовком уже не пуст'
    );
});

test('Пресеты: задают свои ключи и не трогают остальные настройки', function () {
    $current = HeroSettings::withDefaults([
        'autoplay_interval' => 9,
        'height_mobile' => 'compact',
        'scheme' => 'light',
    ]);
    $applied = HeroPresets::apply('navy', $current);

    assert_same('navy', $applied['scheme'], 'пресет ставит свою схему');
    assert_same(9, $applied['autoplay_interval'], 'ручная настройка вне пресета сохранена');
    assert_same('compact', $applied['height_mobile'], 'мобильная высота не сброшена');

    // Все шесть пресетов из задания на месте и дают валидные настройки.
    foreach (['editorial', 'government', 'navy', 'full_image', 'video', 'minimal'] as $key) {
        assert_true(HeroPresets::has($key), 'пресет ' . $key . ' объявлен');
        $result = HeroPresets::apply($key, HeroSettings::defaults());
        assert_same(
            array_keys(HeroSettings::defaults()),
            array_keys($result),
            'пресет ' . $key . ' не ломает контракт настроек'
        );
    }

    assert_same(
        HeroSettings::defaults(),
        HeroPresets::apply('unknown-preset', HeroSettings::defaults()),
        'неизвестный пресет ничего не меняет'
    );
});

test('Разметка обложки: порядок слоёв — фон, затемнение, контент, навигация', function () {
    $settings = HeroSettings::withDefaults(['overlay' => 'gradient', 'nav_indicator' => 'counter_progress']);
    $rendered = HeroRenderer::render(
        ['id' => 1, 'name' => 'Тест'],
        [
            hero_test_slide(['title' => 'Первый', 'media_type' => 'image', 'image' => '/uploads/public/a.jpg'], 1),
            hero_test_slide(['title' => 'Второй', 'media_type' => 'image', 'image' => '/uploads/public/b.jpg'], 2),
        ],
        $settings,
        7
    );
    $html = $rendered['html'];

    $order = [
        'hero__media' => strpos($html, 'hero__media'),
        'hero__overlay' => strpos($html, 'hero__overlay'),
        'hero__inner' => strpos($html, 'hero__inner'),
        'hero__nav' => strpos($html, 'hero__nav'),
    ];
    foreach ($order as $layer => $position) {
        assert_true($position !== false, 'слой ' . $layer . ' есть в разметке');
    }
    assert_true($order['hero__media'] < $order['hero__overlay'], 'затемнение после фона');
    assert_true($order['hero__overlay'] < $order['hero__inner'], 'контент после затемнения');
    assert_true($order['hero__inner'] < $order['hero__nav'], 'навигация после контента');

    // Инлайн-стилей в блоках быть не должно: оформление уходит в scoped CSS.
    assert_true(strpos($html, ' style="') === false, 'инлайн-стилей в разметке нет');
    assert_true(strpos($rendered['css'], '#block-7 .hero{') !== false, 'переменные обложки заданы scoped-стилями');
});

test('Разметка обложки: навигация, счётчик и доступность', function () {
    $rendered = HeroRenderer::render(
        ['id' => 1, 'name' => 'Тест'],
        [
            hero_test_slide(['title' => 'Раз'], 1),
            hero_test_slide(['title' => 'Два'], 2),
            hero_test_slide(['title' => 'Три'], 3),
        ],
        HeroSettings::withDefaults(['autoplay' => true, 'autoplay_interval' => 7]),
        3
    );
    $html = $rendered['html'];

    assert_true(strpos($html, 'data-hero-prev') !== false, 'стрелка «назад»');
    assert_true(strpos($html, 'data-hero-next') !== false, 'стрелка «вперёд»');
    assert_true(strpos($html, 'data-hero-progress') !== false, 'полоса прогресса');
    assert_true(strpos($html, '>03<') !== false, 'общее число слайдов показано');
    assert_true(strpos($html, 'data-hero-autoplay="7000"') !== false, 'интервал автопрокрутки в миллисекундах');
    assert_true(strpos($html, 'data-hero-toggle') !== false, 'кнопка остановки автопрокрутки (WCAG 2.2.2)');
    assert_true(strpos($html, 'data-hero-swipe') !== false, 'свайп включён');
    assert_true(strpos($html, 'aria-roledescription="Карусель"') !== false, 'роль карусели объявлена');
    assert_true(substr_count($html, 'aria-label="Предыдущий слайд"') === 1, 'у стрелки есть подпись для диктора');
    assert_true(strpos($html, 'aria-live="polite"') !== false, 'смена слайда объявляется диктору');
    assert_true(substr_count($html, 'aria-hidden="false"') === 1, 'открыт ровно один слайд');
    assert_true(substr_count($html, ' inert') === 2, 'скрытые слайды выключены из порядка табуляции');
});

test('Разметка обложки: один слайд не превращается в карусель', function () {
    $rendered = HeroRenderer::render(
        ['id' => 1, 'name' => 'Тест'],
        [hero_test_slide(['title' => 'Единственный'], 1)],
        HeroSettings::defaults(),
        4
    );

    assert_true(strpos($rendered['html'], 'hero__nav') === false, 'навигации у одиночного слайда нет');
    assert_true(strpos($rendered['html'], 'hero--carousel') === false, 'класс карусели не выставлен');
});

test('Видео и YouTube: кадр-замена всегда лежит под фоном', function () {
    $video = HeroRenderer::render(
        ['id' => 1, 'name' => 'Видео'],
        [hero_test_slide([
            'title' => 'С видео',
            'media_type' => 'video',
            'video_url' => '/uploads/public/hero.mp4',
            'poster' => '/uploads/public/poster.jpg',
        ], 1)],
        HeroSettings::defaults(),
        5
    );
    $html = $video['html'];

    assert_true(strpos($html, 'hero__fallback') !== false, 'кадр-замена в разметке есть всегда');
    assert_true(strpos($html, 'poster="/uploads/public/poster.jpg"') !== false, 'постер задан у самого видео');
    assert_true(strpos($html, 'data-hero-video') !== false, 'видео помечено для скрипта');
    assert_true(strpos($html, '<video') !== false && strpos($html, 'hidden>') !== false,
        'видео скрыто до решения скрипта — пока виден кадр-замена');
    assert_true(strpos($html, ' muted ') !== false, 'фоновое видео без звука');
    assert_true(strpos($html, ' loop ') !== false, 'фоновое видео по кругу');
    assert_true(strpos($html, 'playsinline') !== false, 'на телефоне не открывается во весь экран');
    assert_true(strpos($html, 'autoplay') === false, 'старт даёт скрипт, а не атрибут: иначе грузятся все ролики сразу');

    $youtube = HeroRenderer::render(
        ['id' => 1, 'name' => 'YouTube'],
        [hero_test_slide([
            'title' => 'С ютубом',
            'media_type' => 'youtube',
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
            'poster' => '/uploads/public/poster.jpg',
        ], 1)],
        HeroSettings::defaults(),
        6
    );

    assert_true(strpos($youtube['html'], '<iframe') === false,
        'iframe не отдаётся сервером: до рукопожатия с плеером посетитель видит кадр-замену');
    assert_true(strpos($youtube['html'], 'data-hero-yt-src') !== false, 'адрес плеера передан скрипту');
    assert_true(strpos($youtube['html'], 'youtube-nocookie.com') !== false, 'плеер без куки-трекинга');
    assert_true(strpos($youtube['html'], 'mute=1') !== false, 'YouTube стартует без звука');
    assert_true(strpos($youtube['html'], 'hero__fallback') !== false, 'кадр-замена есть и у YouTube');
});

test('Цветовая схема: обложка не красит вложенные компоненты', function () {
    $rendered = HeroRenderer::render(
        ['id' => 1, 'name' => 'Navy'],
        [hero_test_slide(['title' => 'Тёмная'], 1)],
        HeroSettings::withDefaults(['scheme' => 'navy', 'content_scheme' => 'light']),
        8
    );

    assert_true(strpos($rendered['html'], 'hero--content-light') !== false, 'светлый текст на тёмной обложке');

    // Цвет объявлен переменной на .hero__text, а компонент со своей
    // поверхностью переопределяет её у себя — иначе белая карточка внутри
    // navy-обложки получила бы белый текст на белом.
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero.css');
    assert_true(strpos($css, '.hero--content-light .hero__text { --hero-fg: #fff; }') !== false,
        'светлая схема задаётся переменной на текстовом слое');
    assert_true(preg_match('/\.hero__surface\s*\{[^}]*--hero-fg:\s*#101a2b/', $css) === 1,
        'вложенная светлая поверхность возвращает себе тёмный текст');

    $light = HeroRenderer::render(
        ['id' => 1, 'name' => 'Light'],
        [hero_test_slide(['title' => 'Светлая'], 1)],
        HeroSettings::withDefaults(['scheme' => 'light', 'content_scheme' => 'auto', 'overlay' => 'none']),
        9
    );
    assert_true(strpos($light['html'], 'hero--content-dark') !== false,
        'на светлой обложке без фото авторежим даёт тёмный текст');
});

test('Расписание слайда: слайд вне окна не попадает в разметку', function () {
    $future = date('Y-m-d H:i', time() + 86400);
    $rendered = HeroRenderer::render(
        ['id' => 1, 'name' => 'Расписание'],
        [
            hero_test_slide(['title' => 'Виден'], 1),
            hero_test_slide(['title' => 'Ещё не начался', '_visible_from' => $future], 2),
        ],
        HeroSettings::defaults(),
        10
    );

    // HeroRenderer получает уже отфильтрованные слайды (это делает
    // HeroSlide::forDisplay), поэтому здесь проверяем сам фильтр.
    $visible = array_values(array_filter(
        [
            HeroSlideData::withDefaults(['title' => 'Виден']),
            HeroSlideData::withDefaults(['title' => 'Ещё не начался', '_visible_from' => $future]),
        ],
        static fn (array $d): bool => \App\Core\BlockVisibility::isVisible($d)
    ));
    assert_same(1, count($visible), 'слайд с будущим стартом отфильтрован');
    assert_true(strpos($rendered['html'], 'Виден') !== false);
});

test('Блок «Обложка»: ссылка на обложку хранится в данных блока', function () {
    assert_true(
        array_key_exists('hero_id', BlockTypeRegistry::defaultsFor('hero')),
        'у блока есть поле выбора обложки'
    );
    assert_same(0, BlockTypeRegistry::defaultsFor('hero')['hero_id'], 'по умолчанию обложка не выбрана');

    $data = HeroBlockNormalizer::normalize(['hero_id' => '42', 'title_field' => 'Свой заголовок']);
    assert_same(42, $data['hero_id'], 'выбранная обложка сохраняется числом');
    assert_same('Свой заголовок', $data['title'], 'собственные поля блока не теряются при выборе обложки');

    assert_same(0, HeroBlockNormalizer::normalize(['hero_id' => '-5'])['hero_id'], 'отрицательный id не проходит');
});

test('Обложка: рендер подключается блоком, а не только шаблоном', function () {
    $collector = (string) file_get_contents(APP_ROOT . '/app/Core/AssetCollector.php');
    assert_true(strpos($collector, "'hero_slides' => '/assets/js/blocks/hero.js'") !== false,
        'скрипт обложки объявлен в карте ассетов');
    assert_true(strpos($collector, "'hero_slides' => '/assets/css/blocks/hero.css'") !== false,
        'часть темы обложки объявлена в карте ассетов');

    // Ключ намеренно не совпадает с типом блока: старая обложка, собранная
    // прямо в блоке, этих файлов не использует и грузить их не должна.
    $renderer = (string) file_get_contents(APP_ROOT . '/app/Core/BlockRenderer.php');
    assert_true(strpos($renderer, "data-hero-transition") !== false,
        'ассеты подключаются по признаку новой разметки, а не по типу блока');

    $template = (string) file_get_contents(APP_ROOT . '/templates/blocks/hero.php');
    assert_true(strpos($template, 'HeroRenderer::render') !== false, 'шаблон блока умеет выводить выбранную обложку');
});

test('Стили обложки: фон вне потока, навигация не перекрывает контент', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero.css');

    assert_true(preg_match('/\.hero\s*\{[^}]*overflow:\s*hidden/', $css) === 1,
        'фон обрезан корнем — горизонтальной прокрутки от него не бывает');
    assert_true(preg_match('/\.hero__media\s*\{[^}]*position:\s*absolute/', $css) === 1,
        'слой фона вынут из потока и не влияет на высоту');
    assert_true(strpos($css, 'object-fit: var(--hero-fit)') !== false,
        'кадрирование фона задаётся object-fit');
    assert_true(strpos($css, 'padding-block-end: calc(var(--space-xl) + var(--hero-nav-space))') !== false,
        'под навигацию зарезервировано место, а не надежда на короткий текст');
    assert_true(strpos($css, '@media (scripting: none)') !== false,
        'без JavaScript слайды показываются подряд, а не исчезают');
    assert_true(strpos($css, '@media (prefers-reduced-motion: reduce)') !== false,
        'системная настройка «меньше движения» учтена');
    assert_true(strpos($css, '[data-a11y-motion="off"] .hero') !== false,
        'тумблер остановки анимаций из панели настроек учтён');
});

test('Навигация обложки: одна капсула, видимая всегда', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero.css');

    // Управление собрано в одну группу, а не разбросано по ширине обложки.
    assert_true(preg_match('/\.hero__nav-bar\s*\{[^}]*display:\s*inline-flex/', $css) === 1,
        'капсула обжимает содержимое, а не тянется во всю ширину');
    assert_true(preg_match('/\.hero__nav-bar\s*\{[^}]*border-radius:\s*var\(--radius-pill\)/', $css) === 1,
        'капсула скруглена');
    // Подложка полупрозрачная и без backdrop-filter: размытие на большой
    // поверхности пересчитывается каждый кадр прокрутки (см. раздел 2.5 плана
    // производительности), а читаемость даёт та же заливка.
    assert_true(preg_match('/\.hero__nav-bar\s*\{[^}]*background:\s*color-mix\(/', $css) === 1,
        'подложка капсулы полупрозрачная');
    assert_true(preg_match('/\.hero__nav-bar\s*\{[^}]*backdrop-filter/', $css) !== 1,
        'размытие подложки капсулы не используется');

    // Ничего из управления не прячется до наведения: владелец хочет видеть
    // навигацию постоянно.
    assert_true(strpos($css, '@media (hover: hover)') === false,
        'навигация обложки не должна зависеть от наведения');

    // Место под капсулу считается от её же размеров: разъедутся — текст
    // обложки ляжет под кнопки.
    assert_true(preg_match('/--hero-nav-space:\s*calc\(var\(--hero-nav-btn\) \+ var\(--hero-nav-pad\) \+ var\(--hero-nav-bottom\)\)/', $css) === 1,
        'резерв высоты считается от размера кнопок, а не числом');
    assert_true(preg_match('/@media \(pointer: coarse\)\s*\{[^}]*--hero-nav-btn:\s*44px/s', $css) === 1,
        'на сенсорном экране кнопки вырастают до 44px');
});

test('Скрипт обложки: клавиатура, свайп и уважение к «меньше движения»', function () {
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/blocks/hero.js');

    assert_true(strpos($js, 'asdrReduceMotion') !== false, 'скрипт спрашивает общий признак «меньше движения»');
    assert_true(strpos($js, "'ArrowLeft'") !== false && strpos($js, "'ArrowRight'") !== false,
        'стрелки клавиатуры переключают слайды');
    assert_true(strpos($js, 'touchstart') !== false && strpos($js, 'touchend') !== false, 'свайп обрабатывается');
    assert_true(strpos($js, 'visibilitychange') !== false, 'в фоновой вкладке автопрокрутка останавливается');
    assert_true(strpos($js, 'startAuto()') !== false && strpos($js, 'stopAuto()') !== false,
        'таймер автопрокрутки пересчитывается, а не продолжается с середины');
    assert_true(strpos($js, 'listening') !== false, 'готовность плеера YouTube проверяется рукопожатием');
});

test('Обложка: слайды и настройки хранятся отдельной записью (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $pdo->exec('DELETE FROM heroes');

    $heroId = \App\Models\Hero::create('Тестовая обложка');
    assert_true($heroId !== null && $heroId > 0, 'обложка создана');

    $first = \App\Models\HeroSlide::create($heroId, HeroSlideData::normalize([
        'title' => 'Первый слайд',
        'image' => '/uploads/public/a.jpg',
    ]));
    $second = \App\Models\HeroSlide::create($heroId, HeroSlideData::normalize([
        'title' => 'Второй слайд',
        'image' => '/uploads/public/b.jpg',
    ]));
    assert_true($first !== null && $second !== null, 'слайды созданы');

    // Черновик на сайт не выходит.
    assert_true(!\App\Models\Hero::isVisible((array) \App\Models\Hero::find($heroId)), 'черновик скрыт');

    \App\Models\Hero::update($heroId, 'Тестовая обложка', 'published', null, null, 5, HeroSettings::defaults(), '');
    assert_true(\App\Models\Hero::isVisible((array) \App\Models\Hero::find($heroId)), 'опубликованная обложка видна');

    // Порядок из админки — это порядок на сайте.
    \App\Models\HeroSlide::reorder($heroId, [$second, $first]);
    $ordered = \App\Models\HeroSlide::forHero($heroId);
    assert_same($second, (int) $ordered[0]['id'], 'перетаскивание меняет порядок показа');

    // Выключенный слайд остаётся в админке, но не уходит на сайт.
    \App\Models\HeroSlide::toggle($first);
    assert_same(2, count(\App\Models\HeroSlide::forHero($heroId)), 'в админке слайд остался');
    assert_same(1, count(\App\Models\HeroSlide::forDisplay($heroId)), 'на сайт ушёл только включённый');

    // Дубль встаёт сразу за оригиналом, а не в конец списка.
    \App\Models\HeroSlide::duplicate($second);
    $afterCopy = \App\Models\HeroSlide::forHero($heroId);
    assert_same(3, count($afterCopy));
    assert_same('Второй слайд', (string) $afterCopy[1]['title'], 'копия стоит следом за оригиналом');

    $pdo->exec('DELETE FROM heroes');
});

test('Обложка: расписание публикации и граница пересборки кэша (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $pdo->exec('DELETE FROM heroes');

    $heroId = (int) \App\Models\Hero::create('Праздничная обложка');
    $from = date('Y-m-d H:i:s', time() + 3600);
    $to = date('Y-m-d H:i:s', time() + 7200);
    \App\Models\Hero::update($heroId, 'Праздничная обложка', 'scheduled', $from, $to, 0, HeroSettings::defaults(), '');

    $hero = (array) \App\Models\Hero::find($heroId);
    assert_true(!\App\Models\Hero::isVisible($hero), 'до начала окна обложка скрыта');
    assert_true(\App\Models\Hero::isVisible($hero, time() + 4000), 'внутри окна обложка видна');
    assert_true(!\App\Models\Hero::isVisible($hero, time() + 8000), 'после окна обложка снова скрыта');

    // Без границы кэш страницы завис бы с прошлогодним баннером.
    assert_same(strtotime($from), \App\Models\Hero::boundary($hero), 'ближайшая граница расписания сообщена');

    // «По расписанию» без дат — это не «всегда»: редактор просто не заполнил окно.
    \App\Models\Hero::update($heroId, 'Праздничная обложка', 'scheduled', null, null, 0, HeroSettings::defaults(), '');
    assert_true(
        !\App\Models\Hero::isVisible((array) \App\Models\Hero::find($heroId)),
        'расписание без дат не показывает обложку'
    );

    $pdo->exec('DELETE FROM heroes');
});

test('Обложка: перевод накладывается на текст, медиа остаётся общим (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $pdo->exec('DELETE FROM heroes');

    $heroId = (int) \App\Models\Hero::create('Двуязычная обложка');
    \App\Models\Hero::update($heroId, 'Двуязычная обложка', 'published', null, null, 0, HeroSettings::defaults(), '');
    $slideId = (int) \App\Models\HeroSlide::create($heroId, HeroSlideData::normalize([
        'title' => 'Русский заголовок',
        'subtitle' => 'Русское описание',
        'image' => '/uploads/public/a.jpg',
    ]));

    \App\Models\HeroSlideTranslation::upsert($slideId, 'uz', [
        'title' => 'Oʻzbekcha sarlavha',
        // Описание намеренно не переведено: должен остаться русский текст.
        'subtitle' => '',
    ]);

    $uz = \App\Models\HeroSlide::forDisplay($heroId, 'uz');
    assert_same(1, count($uz));
    assert_same('Oʻzbekcha sarlavha', (string) $uz[0]['data']['title'], 'переведённый заголовок подставлен');
    assert_same('Русское описание', (string) $uz[0]['data']['subtitle'], 'непереведённое поле берётся с основного языка');
    assert_same('/uploads/public/a.jpg', (string) $uz[0]['data']['image'], 'медиа у слайда общее для всех языков');

    $pdo->exec('DELETE FROM heroes');
});

test('Слайд: своя длительность показа принимается и ограничивается', function () {
    // Пусто или 0 — «как у обложки»: одним интервалом на всю карусель разное
    // время чтения не выражается, но и обязательным поле быть не должно.
    assert_same(0, HeroSlideData::normalize(['title' => 'Т'])['duration']);
    assert_same(0, HeroSlideData::normalize(['title' => 'Т', 'duration' => ''])['duration']);
    assert_same(0, HeroSlideData::normalize(['title' => 'Т', 'duration' => 'быстро'])['duration']);

    assert_same(12, HeroSlideData::normalize(['title' => 'Т', 'duration' => '12'])['duration']);
    // Слишком короткий слайд читается как сбой, а не как настройка.
    assert_same(2, HeroSlideData::normalize(['title' => 'Т', 'duration' => '1'])['duration']);
    assert_same(120, HeroSlideData::normalize(['title' => 'Т', 'duration' => '9999'])['duration']);

    // Значение переживает чтение из БД.
    assert_same(12, HeroSlideData::withDefaults(['duration' => 12])['duration']);
});

test('Разметка: длительность уходит в атрибут только когда она своя', function () {
    $rendered = HeroRenderer::render(
        ['id' => 1, 'name' => 'Тест'],
        [
            hero_test_slide(['title' => 'Свои 9 секунд', 'duration' => 9], 1),
            hero_test_slide(['title' => 'Как у обложки'], 2),
        ],
        HeroSettings::withDefaults(['autoplay' => true, 'autoplay_interval' => 6]),
        11
    );

    assert_contains('data-hero-slide-duration="9000"', $rendered['html'], 'секунды переводятся в миллисекунды');
    assert_same(1, substr_count($rendered['html'], 'data-hero-slide-duration='),
        'у слайда без своей длительности атрибута нет — он держится интервалом обложки');

    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/blocks/hero.js');
    assert_contains("getAttribute('data-hero-slide-duration')", $js, 'таймер читает длительность слайда');
    assert_contains('slideInterval()', $js, 'и полоса прогресса считает по ней же');
});

test('Ширина: обложка «во всю ширину» выходит из контейнера, контент остаётся в нём', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero.css');

    // Раньше класс выводился, но правила под него не было вовсе — настройка
    // «во всю ширину» не делала ничего.
    assert_true(
        preg_match('/\.hero--w-full\s*\{[^}]*margin-inline:\s*calc\(50%\s*-\s*50vw\)/', $css) === 1,
        'обложка выходит из контейнера тем же приёмом, что и полноширинные блоки темы'
    );
    assert_true(
        preg_match('/\.hero__inner\s*\{[^}]*max-width:\s*var\(--container-max/', $css) === 1,
        'контент по-прежнему ограничен шириной сайта'
    );
    // Боковой отступ должен совпадать с контейнером страницы, иначе заголовок
    // обложки не встаёт на одну вертикаль с остальными блоками.
    assert_contains('.hero--w-full .hero__inner', $css);
    assert_contains('--hero-gutter', $css);

    // 100vw без этого даёт горизонтальную прокрутку на ширину скроллбара.
    $theme = (string) file_get_contents(APP_ROOT . '/public/assets/css/gov-theme.css');
    assert_contains('overflow-x: clip', $theme, 'корень обрезает выход за вьюпорт');
});

test('Навигация не уходит под надвинутый соседний блок', function () {
    $hero = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero.css');
    // Наезд объявлен в стилях счётчиков; они вынесены из темы отдельной частью.
    $theme = theme_css();

    // Тема надвигает карточку «Счётчики» на низ обложки. Полоса навигации
    // оказывалась под ней, и стрелки со счётчиком пропадали с экрана.
    assert_contains('bottom: var(--hero-nav-bottom);', $hero, 'смещение навигации вынесено в переменную');
    assert_contains('.cms-block--hero:has(+ .cms-block--counters) .hero--carousel', $hero);
    assert_contains('var(--hero-nav-pad) + var(--hero-nav-bottom))', $hero,
        'резерв под контентом растёт вместе со смещением');

    // Наезд в теме объявлен дважды; действует последнее объявление. Компенсация
    // обязана повторять именно его — иначе навигация снова уедет под карточку.
    preg_match_all(
        '/\.cms-block--hero \+ \.cms-block--counters \.block-counters \{[^}]*margin-top:\s*([^;}]+)/',
        $theme,
        $m
    );
    assert_true($m[1] !== [], 'правило наезда найдено в теме');
    $winning = trim((string) end($m[1]));
    if (preg_match('/clamp\(\s*-(\d+)px\s*,\s*-([\d.]+)vw\s*,\s*-(\d+)px\s*\)/', $winning, $parts) === 1) {
        $expected = 'clamp(' . $parts[3] . 'px, ' . $parts[2] . 'vw, ' . $parts[1] . 'px)';
        assert_contains('--hero-overlap: ' . $expected, $hero,
            'величина компенсации совпадает с действующим наездом темы (' . $winning . ')');
    }
});

test('Кнопка слайда: значок — только иконка набора', function () {
    // Своя картинка внутри кнопки стоила трёх полей на кнопку (файл, режим,
    // ширина) и своего семейства правил в CSS. Осталась иконка Tabler: она
    // берёт currentColor и красится под тип кнопки.
    foreach (['cta_image', 'cta_image_mode', 'cta_image_width',
              'cta2_image', 'cta2_image_mode', 'cta2_image_width'] as $gone) {
        assert_false(array_key_exists($gone, HeroSlideData::defaults()), 'картинка в кнопке вернулась: ' . $gone);
    }

    $data = HeroSlideData::normalize([
        'title' => 'Т',
        'cta_enabled' => '1',
        'cta_text' => 'Подробнее',
        'cta_url' => '/about',
        'cta_icon' => 'arrow-right',
        'cta_image' => '/uploads/public/brand-mark.svg',
    ]);
    assert_false(array_key_exists('cta_image', $data), 'присланное поле картинки не должно сохраняться');

    $rendered = HeroRenderer::render(
        ['id' => 1, 'name' => 'Тест'],
        [hero_test_slide($data, 1)],
        HeroSettings::defaults(),
        21
    );
    assert_contains('tabler-arrow-right', $rendered['html'], 'иконка кнопки пропала');
    assert_contains('hero__cta--with-icon', $rendered['html']);
    assert_true(
        strpos($rendered['html'], 'brand-mark.svg') === false,
        'картинка кнопки всё ещё доезжает до разметки'
    );

    // Семейство правил под режимы картинки ушло вместе с настройкой.
    foreach (['hero.css', 'hero-art-layout.css'] as $file) {
        $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/' . $file);
        assert_true(strpos($css, 'hero__cta--image-') === false, 'мёртвые правила картинки в кнопке: ' . $file);
    }
});

test('Выравнивание слайда: класс и селектор стоят на одном элементе', function () {
    // Классы позиции вешаются на сам слайд. Селектор вида
    // `.hero--y-top .hero__slide` искал бы их у предка и не совпадал никогда —
    // именно так настройка «по вертикали» год молчала, не выдавая ошибки.
    $rendered = HeroRenderer::render(
        ['id' => 1, 'name' => 'Тест'],
        [hero_test_slide(['title' => 'Т'], 1)],
        HeroSettings::withDefaults(['text_align_y' => 'bottom', 'text_position' => 'right']),
        31
    );
    assert_true(
        // Граница слова обязательна: «hero__slide» — часть «hero__slides»,
        // и без неё сюда попадает класс обёртки, а не самого слайда.
        preg_match('/<div class="([^"]*\bhero__slide\b[^"]*)"/', $rendered['html'], $m) === 1,
        'слайд не отрендерился'
    );
    $slideClasses = $m[1];
    assert_contains('hero--y-bottom', $slideClasses, 'класс вертикали — на слайде');
    assert_contains('hero--pos-right', $slideClasses, 'класс горизонтали — на слайде');

    // Комментарии убираем: в них разобран прежний неверный селектор, и поиск
    // по сырому файлу нашёл бы объяснение вместо правила.
    $css = (string) preg_replace(
        '#/\*.*?\*/#s',
        '',
        (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero.css')
    );

    // Вертикаль настраивает сам слайд (он grid-контейнер), поэтому класс и
    // свойство обязаны быть на одном элементе.
    assert_true(
        preg_match('/\.hero__slide\.hero--y-bottom\s*\{[^}]*align-items:\s*end/', $css) === 1,
        'правило вертикали должно относиться к самому слайду'
    );
    assert_true(
        preg_match('/\.hero--y-[a-z]+\s+\.hero__slide/', $css) !== 1,
        'селектор ищет класс вертикали у предка — так он не совпадёт никогда'
    );

    // Горизонталь, наоборот, красит вложенный .hero__inner — там потомок
    // настоящий, и селектор с пробелом правильный.
    assert_true(
        preg_match('/\.hero--pos-right\s+\.hero__inner\s*\{[^}]*justify-content:\s*flex-end/', $css) === 1,
        'горизонталь выравнивает вложенный контейнер'
    );
});

test('Промежутки между частями текста задаёт тема, а не настройка', function () {
    // Четыре числа в форме («над картинкой», «над заголовком», «над
    // описанием», «над кнопками») меняли типографику, которой у обложки и
    // так одна раскладка. Значения остались переменными темы.
    foreach (['gap_title', 'gap_subtitle', 'gap_actions', 'gap_art'] as $gone) {
        assert_false(array_key_exists($gone, HeroSettings::defaults()), 'настройка промежутка вернулась: ' . $gone);
        assert_false(array_key_exists($gone, HeroSlideData::defaults()), 'настройка промежутка вернулась к слайду: ' . $gone);
    }

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/hero.css');
    foreach (['--hero-gap-art', '--hero-gap-title', '--hero-gap-subtitle', '--hero-gap-actions'] as $var) {
        assert_contains($var . ':', $css, 'переменная промежутка пропала из темы: ' . $var);
    }

    // Промежутки не печатаются в scoped CSS обложки: их некому переопределять.
    $rendered = HeroRenderer::render(
        ['id' => 1, 'name' => 'Тест'],
        [hero_test_slide(['title' => 'Т'], 1)],
        HeroSettings::withDefaults([]),
        41
    );
    assert_false(
        str_contains((string) $rendered['css'], '--hero-gap-'),
        'промежутки снова печатаются в стилях обложки'
    );

    // Первая часть текста отступ сверху не получает: переменные про расстояние
    // МЕЖДУ частями, а не про отрыв от границы блока.
    assert_true(
        preg_match('/\.hero__text > :first-child\s*\{[^}]*margin-top:\s*0/', $css) === 1,
        'у первой части текста верхнего отступа быть не должно'
    );
});
