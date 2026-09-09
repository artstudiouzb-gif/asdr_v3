<?php

declare(strict_types=1);

use App\Core\TelegramRichMessage;

/**
 * Подпись и автор/источник у фотографий: галерея новости, снимки альбома,
 * передача в rich-пост Telegram. Поля свободного ввода, без механики
 * переводов — редактор заполняет их при вставке медиа.
 */

test('Схема: подпись и автор есть у галереи новости и снимков альбома', function () {
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');

    // news_images: была только служебная alt — посетитель её не видит.
    $newsImages = substr($schema, (int) strpos($schema, 'CREATE TABLE IF NOT EXISTS news_images'), 700);
    assert_contains('caption', $newsImages);
    assert_contains('credit', $newsImages);

    $albumImages = substr($schema, (int) strpos($schema, 'CREATE TABLE IF NOT EXISTS photo_album_images'), 700);
    assert_contains('credit', $albumImages);

    // Миграция зарегистрирована — иначе тест консистентности схемы упадёт
    // позже, а боевая база разъедется с чистой установкой.
    assert_contains("('2026_08_01_media_captions.sql')", $schema);
    assert_true(is_file(APP_ROOT . '/database/migrations/2026_08_01_media_captions.sql'));
});

test('Rich-пост: подпись и автор снимка уходят в figcaption с cite', function () {
    $doc = TelegramRichMessage::build(
        [['code' => 'uz', 'label' => 'Oʻzbekcha', 'title' => 'Sarlavha', 'excerpt' => 'Matn.',
          'link' => 'https://s.uz/x', 'read_more' => 'Oʻqish']],
        ['https://s.uz/a.jpg', 'https://s.uz/b.jpg'],
        '', '', '', '',
        ['https://s.uz/a.jpg' => ['caption' => 'Заседание коллегии', 'credit' => 'Пресс-служба']]
    );

    assert_contains('<figure><img src="tg://photo?id=p1"/><figcaption>Заседание коллегии<cite>Пресс-служба</cite></figcaption></figure>', $doc['html']);
    // Снимок без подписи остаётся голым тегом — пустые figcaption не шлём.
    assert_contains('<img src="tg://photo?id=p2"/>', $doc['html']);
    assert_not_contains('<figcaption></figcaption>', $doc['html']);
    // Служебные ключи подписи не протекают в массив media для API.
    foreach ($doc['media'] as $m) {
        assert_same(['id', 'media'], array_keys($m));
    }
});

test('Страница новости: подпись и автор под галереей, тексты для листания', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/news_show.php');

    assert_contains('data-ndg-captions', $view);
    assert_contains("t('Фото:')", $view);
    // Подпись подставляется скриптом при листании слайдов.
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/frontend.js');
    assert_contains('data-ndg-caption-text', $js);
    assert_contains("label('photoCredit'", $js);
});

test('Подпись — один фирменный компонент, 12px, без капслока', function () {
    $css = theme_css();
    $block = substr($css, (int) strpos($css, '.media-caption {'), 700);

    // Владелец задал предел: подпись не крупнее 12px.
    assert_contains('font-size: 12px;', $block);
    // Акцентная грань слева — тот же приём, что у карточек актов.
    assert_contains('border-inline-start: 2px solid', $block);
    // Капслок на кириллице кричит и спорит с самой подписью.
    assert_not_contains('text-transform: uppercase', $block);

    // Один класс на все три места вывода, а не три разных набора правил.
    foreach ([
        '/app/Views/site/news_show.php',
        '/app/Views/site/album.php',
    ] as $view) {
        assert_contains('media-caption', (string) file_get_contents(APP_ROOT . $view));
    }
    assert_not_contains('newsdetail-gallery__credit', $css, 'старые ad-hoc правила убраны');
});

test('Обложка не теряет подпись, заданную в галерее', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/news_show.php');

    // Обложка обычно продублирована в галерее, и кадр с тем же путём
    // пропускается: подпись бралась бы из воздуха.
    assert_contains('$captionsByPath', $view);
    assert_contains("\$captionsByPath[\$cover]['caption']", $view);
});

test('Формы админки: поля подписи и автора на месте', function () {
    $news = (string) file_get_contents(APP_ROOT . '/app/Views/admin/news/form.php');
    assert_contains('[caption]', $news);
    assert_contains('[credit]', $news);

    $album = (string) file_get_contents(APP_ROOT . '/app/Views/admin/albums/form.php');
    assert_contains('name="credit"', $album);
});

test('«Фото:» переведено — подпись не выйдет по-русски на UZ-версии', function () {
    $uz = require APP_ROOT . '/app/Core/lang/uz.php';
    $en = require APP_ROOT . '/app/Core/lang/en.php';
    assert_true(array_key_exists('Фото:', $uz));
    assert_true(array_key_exists('Фото:', $en));
});

test('Миниатюры галереи новости не тянут оригинал', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/news_show.php');

    // Миниатюра показывается 110×62. Прежде здесь стоял сырой путь к файлу:
    // браузер тянул оригинал целиком, и это была ВТОРАЯ загрузка той же
    // фотографии — главный слайд берёт webp-вариант по другому адресу. На
    // галерее из четырёх снимков это четыре лишних оригинала.
    $thumbs = substr($view, (int) strpos($view, 'newsdetail-gallery__thumbs'));
    $thumbs = substr($thumbs, 0, (int) strpos($thumbs, '</div>'));

    assert_true(
        !(bool) preg_match('/<img\s+src="<\?=\s*htmlspecialchars\(\$s\[.path.\]/', $thumbs),
        'миниатюра не ссылается на исходный файл напрямую'
    );
    assert_contains("Media::picture((string) \$s['path']", $thumbs, 'миниатюра идёт через Media::picture');
    assert_contains("'110px'", $thumbs, 'подсказка размера уводит выбор на мелкий вариант');

    // Третий и четвёртый аргументы picture() — это точка фокуса, а не размеры:
    // числа вместо null уводили кадр к правому краю (object-position 100% 62%).
    assert_true(
        (bool) preg_match('/Media::picture\(\(string\) \$s\[.path.\], \(string\) \$s\[.alt.\], null, null,/', $thumbs),
        'точка фокуса берётся из медиатеки, а не задаётся числами наугад'
    );
});

test('Миниатюры медиатеки в админке не тянут оригинал', function () {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/files/index.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/FileController.php');
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');
    $media = (string) file_get_contents(APP_ROOT . '/app/Core/Media.php');

    // Карточка сетки — около 135px, а грузился оригинал: при ширине 2560px по
    // умолчанию это сотни килобайт на карточку, а карточек на странице до
    // трёхсот. Мест три, и все три обязаны брать мелкий вариант.
    assert_contains('Media::thumbUrl($url)', $view, 'страница медиатеки берёт мелкий вариант');
    assert_contains("'thumb' => \\App\\Core\\Media::thumbUrl(", $controller, 'ответ для окна выбора несёт адрес миниатюры');
    assert_contains('it.thumb || it.url', $js, 'сетка окна выбора предпочитает миниатюру');

    // Без вариантов помощник обязан вернуть исходный адрес: иначе у файлов,
    // загруженных до появления webp, карточка осталась бы пустой.
    assert_contains('public static function thumbUrl(string $url): string', $media);
    assert_contains("return \$variants['full'] ?? \$url;", $media, 'без размерных вариантов берётся полный, затем исходный');

    // Набор ширин объявлен один раз: прежде тот же список был выписан
    // литералами в четырёх местах — генерация при загрузке, подбор srcset,
    // пакетная достройка и удаление вместе с оригиналом, — и такой список
    // расходится с первой правкой молча.
    assert_contains('public const VARIANT_WIDTHS = [400, 800, 1600];', $media);
    assert_contains('public static function variantSuffixes(): array', $media);
    foreach ([
        'app/Core/MediaCleaner.php',
        'app/Controllers/Admin/FileController.php',
        'app/Core/Uploader.php',
        'app/Core/ImageBatchOptimizer.php',
    ] as $file) {
        $code = (string) file_get_contents(APP_ROOT . '/' . $file);
        assert_true(
            !(bool) preg_match("/\['\.webp', '-1600\.webp', '-800\.webp'\]/", $code),
            'свой список вариантов не остался в ' . $file
        );
    }

    // 400px — ради самих миниатюр: карточка около 135px, и даже с удвоенной
    // плотностью экрана 800px там втрое больше нужного.
    assert_contains('400', $media, 'мелкий вариант объявлен');
});
