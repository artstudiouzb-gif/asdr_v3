<?php

declare(strict_types=1);

use App\Core\CustomAssetHelper;
use App\Core\GeneratedJs;
use App\Core\GeneratedCss;

test('CustomAssetHelper::resolveCssUrls: разделяет внешние URL и компилирует кастомный CSS во внешние файлы', function () {
    $rawInput = "https://cdn.example.com/animate.min.css\nbody { --custom-accent: #007bff; }";
    $urls = CustomAssetHelper::resolveCssUrls($rawInput, 999);

    assert_same(2, count($urls));
    assert_same('https://cdn.example.com/animate.min.css', $urls[0]);
    assert_true(str_starts_with($urls[1], '/uploads/public/generated-css/page-999-'), 'сгенерирован внешний CSS файл');
    assert_true(str_contains($urls[1], '.css'));
});

test('CustomAssetHelper::resolveJsUrls: разделяет внешние URL и компилирует кастомный JS во внешние файлы', function () {
    $rawInput = "https://cdn.example.com/confetti.js\nconsole.log('Custom JS execution');";
    $urls = CustomAssetHelper::resolveJsUrls($rawInput, 999);

    assert_same(2, count($urls));
    assert_same('https://cdn.example.com/confetti.js', $urls[0]);
    assert_true(str_starts_with($urls[1], '/uploads/public/generated-js/page-999-'), 'сгенерирован внешний JS файл');
    assert_true(str_contains($urls[1], '.js'));
});

test('GeneratedJs::publish: сохраняет JS во внешний файл на диске без инлайн-тегов', function () {
    $url = GeneratedJs::publish("console.log('unit test js');", 'test-scope');
    assert_true($url !== null);
    assert_true(str_contains($url, '/uploads/public/generated-js/test-scope-'));
    $filePath = APP_ROOT . '/public' . parse_url($url, PHP_URL_PATH);
    assert_true(is_file($filePath), 'файл сгенерирован на диске');
    $content = (string) file_get_contents($filePath);
    assert_contains("console.log('unit test js');", $content);
});

test('CustomAssetHelper: опасная схема не попадает в href/src', function () {
    foreach (CustomAssetHelper::resolveCssUrls('javascript:alert(1)//x.css', 501) as $url) {
        assert_not_contains('javascript:', $url);
    }
    foreach (CustomAssetHelper::resolveJsUrls("<script src=\"javascript:alert(1)\"></script>", 501) as $url) {
        assert_not_contains('javascript:', $url);
    }
    foreach (CustomAssetHelper::resolveCssUrls('<link rel="stylesheet" href="data:text/css,body{}">', 501) as $url) {
        assert_true(!str_starts_with($url, 'data:'), 'data:-URI отброшен');
    }
});

test('CustomAssetHelper: протокол-относительный адрес приводится к https', function () {
    $urls = CustomAssetHelper::resolveCssUrls('//cdn.example.com/a.css', 502);
    assert_same(['https://cdn.example.com/a.css'], $urls);
});

test('CustomAssetHelper: селектор с .css остаётся кодом, а не ссылкой', function () {
    $urls = CustomAssetHelper::resolveCssUrls(".hero.css\n{color:red}", 503);
    assert_same(1, count($urls));
    assert_true(str_contains($urls[0], '/uploads/public/generated-css/'), 'ушло в сгенерированный файл');
});

test('CustomAssetHelper::originsOf: только внешние origin, свои пути пропускаются', function () {
    $origins = CustomAssetHelper::originsOf([
        '/uploads/public/generated-css/page-1-abc.css?v=1',
        'https://cdn.example.com/a/b/c.css?v=2',
        'http://old.example.org:8080/x.js',
    ]);
    assert_same(['https://cdn.example.com', 'http://old.example.org:8080'], $origins);
});

test('CSP: внешние источники постраничных CSS/JS попадают в директивы, мусор отбрасывается', function () {
    $csp = \App\Core\SecurityHeaders::publicCsp('nonce123', [
        'extra_style' => ['https://cdn.example.com', 'https://evil.example.com/path', "'unsafe-inline'"],
        'extra_script' => ['https://cdn.example.com'],
    ]);
    assert_contains('style-src', $csp);
    assert_contains('https://cdn.example.com', $csp);
    assert_not_contains('https://evil.example.com/path', $csp);
    // Никакой ввод не должен добавить 'unsafe-inline' в script-src.
    $scriptSrc = (string) (explode(';', explode('script-src ', $csp)[1] ?? '')[0] ?? '');
    assert_not_contains("'unsafe-inline'", $scriptSrc);
});

test('Публичные шаблоны разрешают внешние ассеты страницы в CSP', function () {
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    $footer = (string) file_get_contents(APP_ROOT . '/app/Views/site/_footer.php');
    assert_contains('SecurityHeaders::allowPageAssets', $header);
    assert_contains('SecurityHeaders::allowPageAssets', $footer);
});

test('Произвольные CSS/JS страницы правит только супер-администратор', function () {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/PageController.php');
    assert_contains('Auth::isSuperAdmin()', $controller);
    assert_contains('limitAssetField', $controller);
    // Редактору сохраняются прежние значения, а не присланные формой.
    assert_contains("\$existing['custom_css']", $controller);
    assert_contains("\$existing['custom_js']", $controller);

    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/form.php');
    assert_contains('Auth::isSuperAdmin()', $form);
});

test('Разметку блока «HTML» не переписать без прав супер-администратора', function () {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/BlockController.php');
    // И обычное сохранение, и откат ревизии сохраняют прежний html.
    assert_same(3, substr_count($controller, "=== 'html' && !Auth::isSuperAdmin()"));
});
