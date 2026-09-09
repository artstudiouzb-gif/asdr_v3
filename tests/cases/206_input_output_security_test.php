<?php

declare(strict_types=1);

use App\Core\HtmlSanitizer;
use App\Core\UrlGuard;
use App\Models\Redirect;

test('URL allowlist отклоняет опасные и protocol-relative адреса', function (): void {
    assert_true(UrlGuard::isSafeLink('/news/item'), 'Локальная ссылка разрешена');
    assert_true(UrlGuard::isSafeLink('news/item'), 'Относительная ссылка разрешена');
    assert_true(UrlGuard::isSafeLink('https://example.test/news'), 'HTTPS-ссылка разрешена');
    assert_true(UrlGuard::isSafeLink('mailto:info@example.test'), 'mailto разрешён только для href');

    assert_false(UrlGuard::isSafeLink('//evil.example/phishing'), 'Protocol-relative URL запрещён');
    assert_false(UrlGuard::isSafeLink('\\\\evil.example\\phishing'), 'Обратные слеши запрещены');
    assert_false(UrlGuard::isSafeLink('data:text/html,<script>alert(1)</script>'), 'data URL запрещён');
    assert_false(UrlGuard::isSafeLink('file:///etc/passwd'), 'file URL запрещён');
    assert_false(UrlGuard::isSafeLink('https://user:pass@example.test/'), 'Userinfo в URL запрещён');

    assert_true(UrlGuard::isSafeMedia('/uploads/public/photo.jpg'), 'Корневое локальное медиа разрешено');
    assert_true(UrlGuard::isSafeMedia('https://cdn.example.test/photo.jpg'), 'HTTPS-медиа разрешено');
    assert_false(UrlGuard::isSafeMedia('images/photo.jpg'), 'Произвольное относительное медиа запрещено');
    assert_false(UrlGuard::isSafeMedia('../../etc/passwd'), 'Traversal-путь запрещён для src');
    assert_false(UrlGuard::isSafeMedia('mailto:info@example.test'), 'mailto запрещён для src');
    assert_false(UrlGuard::isSafeMedia('data:image/svg+xml,%3Csvg%2F%3E'), 'SVG data URL запрещён для src');
});

test('HTML-санитайзер удаляет небезопасные href и src', function (): void {
    $safe = HtmlSanitizer::sanitize(
        '<p>'
        . '<a href="/safe" target="_blank">safe</a>'
        . '<a href="//evil.example/x">protocol</a>'
        . '<a href="data:text/html,bad">data</a>'
        . '<a href="file:///etc/passwd">file</a>'
        . '<img src="/uploads/public/good.jpg" alt="good">'
        . '<img src="../../etc/passwd" alt="traversal">'
        . '<img src="data:image/svg+xml,%3Csvg%20onload%3Dalert(1)%3E%3C/svg%3E" alt="bad">'
        . '</p>'
    );

    assert_contains('href="/safe"', $safe, 'Безопасная локальная ссылка сохранена');
    assert_contains('rel="noopener noreferrer"', $safe, 'target=_blank получает безопасный rel');
    assert_contains('src="/uploads/public/good.jpg"', $safe, 'Безопасное изображение сохранено');
    assert_not_contains('//evil.example', $safe, 'Protocol-relative href удалён');
    assert_not_contains('data:text/html', $safe, 'data href удалён');
    assert_not_contains('file:///etc/passwd', $safe, 'file href удалён');
    assert_not_contains('../../etc/passwd', $safe, 'Traversal src удалён');
    assert_not_contains('data:image/svg+xml', $safe, 'SVG data src удалён');
});

test('Редиректы не принимают CRLF и повторно кодируют query string', function (): void {
    assert_same(null, Redirect::normalizeTarget("/safe\r\nX-Test: injected"), 'CRLF в цели запрещён');
    assert_same(null, Redirect::normalizePath("/old\nInjected"), 'CRLF в исходном пути запрещён');
    assert_same(null, Redirect::normalizeTarget('//evil.example/path'), 'Protocol-relative цель запрещена');
    assert_same(null, Redirect::normalizeTarget('/\\evil.example/path'), 'Обратный слеш в цели запрещён');
    assert_same(null, Redirect::normalizeTarget('https://user:pass@example.test/path'), 'Userinfo в цели запрещён');
    assert_same('/safe', Redirect::normalizeTarget('/safe'), 'Безопасная локальная цель разрешена');

    $target = Redirect::buildTarget('/new', 'q=line%0D%0AX-Test%3Ayes&safe=1');
    assert_not_contains("\r", $target, 'В Location нет сырого CR');
    assert_not_contains("\n", $target, 'В Location нет сырого LF');
    assert_contains('q=line%0D%0AX-Test%3Ayes', $target, 'Переносы остаются percent-encoded');
    assert_same('/', Redirect::buildTarget("/bad\r\nHeader: x", 'a=1'), 'Невалидная запись из БД fail-closed');
});

test('Публичный health endpoint скрывает детали без сервисного токена', function (): void {
    $source = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/HealthController.php');

    assert_contains('HEALTH_CHECK_TOKEN', $source, 'Подробный режим требует секрет из окружения');
    assert_contains('HTTP_AUTHORIZATION', $source, 'Поддержан Authorization: Bearer');
    assert_contains('HTTP_X_HEALTH_TOKEN', $source, 'Поддержан сервисный заголовок');
    assert_contains('$payload = [\'status\' => $status]', $source, 'Публичный payload начинается только со статуса');
    assert_contains('if ($detailed)', $source, 'Технические детали добавляются только после авторизации');
    assert_contains("['status' => 'forbidden']", $source, 'Неверный переданный токен получает 403');
});
