<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Digest;
use App\Models\Subscriber;

/** Таблица подписчиков (идемпотентно — миграция с IF NOT EXISTS). */
function ensure_subscribers_table(): void
{
    ensure_test_db();
    Database::pdo()->exec((string) file_get_contents(__DIR__ . '/../../database/migrations/2026_07_08_subscribers.sql'));
}

test('Subscriber: подписка с валидацией, дубликаты, отписка по токену (БД)', function () {
    ensure_subscribers_table();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM subscribers');

    assert_same('ok', Subscriber::subscribe('User@Example.com'));
    assert_same('exists', Subscriber::subscribe('user@example.com'), 'регистр не создаёт дубль');
    assert_same('invalid', Subscriber::subscribe('не-емейл'));
    assert_same('invalid', Subscriber::subscribe(''));
    assert_same(1, Subscriber::count());

    $row = Subscriber::all()[0];
    assert_same('user@example.com', (string) $row['email'], 'адрес приведён к нижнему регистру');
    assert_same(48, strlen((string) $row['token']));

    // Отписка: мусорный токен — нет; настоящий — да, повторно — нет.
    assert_false(Subscriber::unsubscribeByToken('xxx'));
    assert_true(Subscriber::unsubscribeByToken((string) $row['token']));
    assert_false(Subscriber::unsubscribeByToken((string) $row['token']));
    assert_same(0, Subscriber::count());

    $pdo->exec('DELETE FROM subscribers');
});

test('Digest: тема, тело со ссылками и обрезкой, подвал с отпиской', function () {
    $subject = Digest::buildSubject('АСДР', '12.07.2026');
    assert_same('Дайджест новостей — АСДР (12.07.2026)', $subject);

    $body = Digest::buildBody([
        ['title' => 'Первая новость', 'slug' => 'pervaya', 'excerpt' => 'Кратко'],
        ['title' => 'Вторая', 'slug' => 'vtoraya', 'excerpt' => str_repeat('х', 300)],
    ], 'АСДР', 'https://site.uz/');

    assert_true(str_contains($body, '>Первая новость</a>'));
    assert_true(str_contains($body, 'https://site.uz/news/pervaya'), 'ссылка без двойного слэша');
    assert_true(str_contains($body, '…'), 'длинный анонс обрезан');
    assert_false(str_contains($body, str_repeat('х', 250)), 'анонс не попал целиком');

    $footer = Digest::buildFooter('https://site.uz', 'abc123');
    assert_true(str_contains($footer, '/unsubscribe?token=abc123'));
    assert_true(str_contains($footer, 'Отписаться'));

    $uzSubject = Digest::buildSubject('ASDR', '12.07.2026', 'uz');
    assert_same('Yangiliklar dayjesti — ASDR (12.07.2026)', $uzSubject);
    $uzBody = Digest::buildBody([
        ['title' => 'Yangilik', 'slug' => 'yangilik', 'excerpt' => 'Qisqacha'],
    ], 'ASDR', 'https://site.uz', 'uz', '/uz');
    assert_true(str_contains($uzBody, 'https://site.uz/uz/news/yangilik'));
    assert_same('2026-W29', Digest::periodKey(new \DateTimeImmutable('2026-07-13')));
});

test('Блок subscribe зарегистрирован: дефолты и шаблон на месте', function () {
    $defaults = \App\Core\BlockRenderer::defaultsFor('subscribe');
    assert_true(isset($defaults['title'], $defaults['text'], $defaults['button_text']));
    assert_true(is_file(APP_ROOT . '/templates/blocks/subscribe.php'), 'шаблон блока существует');
});

test('Подписка: все публичные формы используют единый honeypot, источник и согласие', function () {
    $files = [
        'templates/blocks/subscribe.php' => 'block',
        'templates/widgets/subscribe.php' => 'widget',
        'app/Views/site/news_show.php' => 'news',
        'app/Views/site/_footer.php' => 'footer',
    ];
    foreach ($files as $path => $source) {
        $template = (string) file_get_contents(APP_ROOT . '/' . $path);
        assert_contains('honeypotField()', $template, $path);
        assert_contains('name="source" value="' . $source . '"', $template, $path);
    }

    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/SubscribeController.php');
    assert_contains("form_consent_enabled", $controller);
    assert_contains('Locale::current()', $controller);
});
