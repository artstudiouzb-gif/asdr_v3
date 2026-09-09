<?php

declare(strict_types=1);

use App\Core\ImageBatchOptimizer;
use App\Core\MediaPermissions;

/*
 * Достройка миниатюр запускается из админки, а не только из консоли.
 *
 * Пакетная обработка существует давно (`scripts/optimize_images.php`), но
 * владелец сайта на shared-хостинге до SSH не доходит: раздел «Изображения»
 * предлагал команду, которую там некому выполнить, — то есть не предлагал
 * ничего. Отсюда два требования, и оба проверяются здесь.
 *
 * Первое: обработка обязана идти пакетами с курсором. Один запрос на весь
 * каталог шлюз обрывает по таймауту (ровно так падал импорт новостей), и чем
 * больше медиатека, тем вернее отказ — то есть отказ приходит именно туда, где
 * обработка нужна.
 *
 * Второе: курсор обязан сдвигаться даже при исчерпанном бюджете времени, иначе
 * пакет возвращается на том же месте и обработка стоит, показывая движение.
 */

test('Пакет возвращает курсор и продолжается с того же места', function () {
    $dir = sys_get_temp_dir() . '/imgbatch-' . bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);

    foreach (['a', 'b', 'c'] as $name) {
        $image = imagecreatetruecolor(60, 40);
        imagejpeg($image, $dir . '/' . $name . '.jpg', 90);
        unset($image);
    }

    $first = ImageBatchOptimizer::run($dir, true, false, 1);
    assert_same(3, $first['total'], 'Кандидаты посчитаны');
    assert_true($first['cursor'] > 0 && $first['cursor'] < 3, 'Курсор остановился внутри списка');

    $second = ImageBatchOptimizer::run($dir, true, false, 0, $first['cursor']);
    assert_same(3, $second['cursor'], 'Второй пакет дошёл до конца');
    assert_same($first['planned'] + $second['planned'], 3, 'Вместе пакеты покрыли весь список');

    // Порядок обхода каталога задаёт файловая система, поэтому номер, на
    // котором остановился пакет, обязан указывать на тот же файл и в
    // следующем запросе. Держит это сортировка списка кандидатов.
    $candidates = ImageBatchOptimizer::candidates($dir);
    $sorted = $candidates;
    sort($sorted, SORT_STRING);
    assert_same($sorted, $candidates, 'Список кандидатов отсортирован');

    array_map('unlink', glob($dir . '/*') ?: []);
    rmdir($dir);
});

test('Исчерпанный бюджет времени всё равно сдвигает курсор', function () {
    $dir = sys_get_temp_dir() . '/imgbatch-' . bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);
    $image = imagecreatetruecolor(60, 40);
    imagejpeg($image, $dir . '/only.jpg', 90);
    unset($image);

    // Нулевой бюджет исчерпан заведомо: если бы проверка стояла до первого
    // файла, пакет вернул бы курсор 0 и обработка не сдвинулась бы никогда.
    $result = ImageBatchOptimizer::run($dir, true, false, 0, 0, 0.0001);
    assert_same(1, $result['cursor'], 'Хотя бы один файл просмотрен');

    array_map('unlink', glob($dir . '/*') ?: []);
    rmdir($dir);
});

test('Раздел производительности отдаёт кнопку, маршрут и обработчик', function () {
    $view = file_get_contents(APP_ROOT . '/app/Views/admin/performance/index.php') ?: '';
    assert_true(str_contains($view, 'data-batch-task="images"'), 'В разделе есть блок достройки миниатюр');
    assert_true(str_contains($view, 'data-batch-task="permissions"'), 'И блок починки прав');
    assert_true(str_contains($view, '/admin/performance/optimize-images'), 'Указан адрес обработчика');
    assert_true(str_contains($view, 'admin-media-batch.js'), 'Подключён общий бегунок пакетов');

    $routes = file_get_contents(APP_ROOT . '/public/index.php') ?: '';
    assert_true(
        str_contains($routes, "\$router->post('/admin/performance/optimize-images'"),
        'Маршрут объявлен только на POST'
    );

    $controller = file_get_contents(APP_ROOT . '/app/Controllers/Admin/PerformanceController.php') ?: '';
    assert_true(str_contains($controller, 'function optimizeImages'), 'Обработчик на месте');
    assert_true(str_contains($controller, 'Auth::requireSuperAdmin'), 'Доступ только супер-админу');
    assert_true(str_contains($controller, 'Csrf::verifyRequest'), 'Запрос проверяется на CSRF');

    assert_true(
        is_file(APP_ROOT . '/public/assets/js/admin-media-batch.js'),
        'Файл скрипта существует'
    );
});

/*
 * Права оригинала чинятся так же, как права вариантов.
 *
 * PR, закрывший это для WebP-вариантов, оригинала не касался: `is_file()`
 * отвечает «файл есть», а отдаст ли его веб-сервер — не спрашивает. Файл с
 * правами 0600 лежит на диске, PHP его видит, веб-сервер отвечает 403, и
 * <img> на такой ответ не откатывается ни на что: вместо фотографии
 * alt-текст. У варианта есть запасной путь, у оригинала никакого — поэтому
 * страница ломается именно на нём.
 */

test('Оригинал с правами только для владельца открывается на чтение', function () {
    if (DIRECTORY_SEPARATOR === '\\') {
        skip_test('POSIX permissions are not supported on Windows');
    }
    // Конфигурацию не подменяем: Config::set() заменяет её целиком, а файлы
    // кейсов идут по порядку строк — соседний тест получил бы пустые пути.
    $root = rtrim((string) \App\Core\Config::get('paths.public_uploads', ''), '/');
    $urlBase = rtrim((string) \App\Core\Config::get('paths.public_uploads_url', ''), '/');
    if ($root === '' || $urlBase === '' || !is_dir($root)) {
        skip_test('Каталог публичных загрузок не настроен');
        return;
    }
    $dir = $root . '/probe-' . bin2hex(random_bytes(5));
    mkdir($dir, 0755, true);

    $path = $dir . '/cover.jpg';
    $image = imagecreatetruecolor(1200, 800);
    imagejpeg($image, $path, 88);
    unset($image);
    // 0600 — ровно то, что оставляет tempnam() у файла, собранного самим PHP.
    chmod($path, 0600);

    $url = $urlBase . '/' . basename($dir) . '/cover.jpg';
    $html = \App\Core\Media::picture($url, 'Заголовок');

    assert_same(0644, fileperms($path) & 0777, 'Права открыты на чтение при отрисовке');
    assert_true(str_contains($html, 'src="' . $url . '"'), 'Адрес остался прежним');

    unlink($path);
    rmdir($dir);
});

test('Пакетная починка прав идёт по курсору и не трогает содержимое', function () {
    if (DIRECTORY_SEPARATOR === '\\') {
        skip_test('POSIX permissions are not supported on Windows');
    }
    $dir = sys_get_temp_dir() . '/perm-' . bin2hex(random_bytes(6));
    mkdir($dir . '/2026', 0700, true);
    $file = $dir . '/2026/photo.jpg';
    file_put_contents($file, 'содержимое');
    chmod($file, 0600);
    $blank = $dir . '/2026/blank.jpg';
    file_put_contents($blank, '');
    chmod($blank, 0600);

    $plan = MediaPermissions::run($dir, true, 0, 0.0);
    assert_true($plan['planned'] > 0, 'Пробный проход видит работу');
    assert_same(0600, fileperms($file) & 0777, 'Пробный проход ничего не меняет');

    $done = MediaPermissions::run($dir, false, 0, 0.0);
    assert_same(0644, fileperms($file) & 0777, 'Файлу выставлены 0644');
    assert_same(0755, fileperms($dir . '/2026') & 0777, 'Каталогу выставлены 0755');
    assert_same('содержимое', file_get_contents($file), 'Содержимое файла не тронуто');
    // Пустой файл правами не чинится — он считается отдельно, иначе отчёт
    // выдавал бы неудавшуюся загрузку за исправленную.
    assert_true($done['empty'] >= 1, 'Пустой файл посчитан отдельно');
    assert_same($done['total'], $done['cursor'], 'Курсор дошёл до конца');

    assert_same(0, MediaPermissions::run($dir, false, 0, 0.0)['fixed'], 'Повторный проход ничего не делает');

    unlink($file);
    unlink($blank);
    rmdir($dir . '/2026');
    rmdir($dir);
});
