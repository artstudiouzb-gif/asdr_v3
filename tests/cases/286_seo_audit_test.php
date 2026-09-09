<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Seo\SeoAudit;
use App\Core\Seo\SeoFinding;
use App\Models\SeoAudit as SeoAuditLog;

/**
 * @param list<SeoFinding> $findings
 */
function seo_finding(array $findings, string $key): ?SeoFinding
{
    foreach ($findings as $finding) {
        if ($finding->key === $key) {
            return $finding;
        }
    }

    return null;
}

test('Проверка индексации: находка знает уровень, счётчик и примеры адресов', function (): void {
    $finding = new SeoFinding('demo', SeoFinding::LEVEL_ERROR, 'Заголовок', 'Пояснение', 3, ['/a', '/b'], '/admin/redirects');
    $restored = SeoFinding::fromArray($finding->toArray());

    // Снимок кладётся в базу и читается обратно: находка обязана пережить дорогу.
    assert_same('demo', $restored->key);
    assert_same(SeoFinding::LEVEL_ERROR, $restored->level);
    assert_same(3, $restored->count);
    assert_same(['/a', '/b'], $restored->samples);
    assert_same('/admin/redirects', $restored->fixUrl);

    // Подделанный уровень не превращается в третий вид: неизвестное значение
    // приводится к предупреждению, а не показывается как «в порядке».
    assert_same(SeoFinding::LEVEL_WARNING, SeoFinding::fromArray(['key' => 'x', 'level' => 'критично'])->level);
    // Мусор в примерах не доезжает до разметки.
    assert_same([], SeoFinding::fromArray(['key' => 'x', 'samples' => [['вложенный'], 5]])->samples);
});

test('Проверка индексации: сводка считает ошибки и предупреждения отдельно', function (): void {
    $summary = SeoAudit::summary([
        new SeoFinding('a', SeoFinding::LEVEL_ERROR, 'A'),
        new SeoFinding('b', SeoFinding::LEVEL_WARNING, 'B'),
        new SeoFinding('c', SeoFinding::LEVEL_OK, 'C'),
        new SeoFinding('d', SeoFinding::LEVEL_ERROR, 'D'),
    ]);

    assert_same(['errors' => 2, 'warnings' => 1, 'ok' => 1], $summary);
});

test('Проверка индексации: находит редирект поверх живой страницы (БД)', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec("DELETE FROM redirects WHERE from_path = '/seo-audit-probe'");
    $pdo->exec("DELETE FROM pages WHERE slug = 'seo-audit-probe'");

    // Без конфликта проверка обязана молчать, иначе она бесполезна.
    $clean = seo_finding(SeoAudit::run(false), 'redirect_conflict');
    assert_same(SeoFinding::LEVEL_OK, $clean->level);

    $pdo->exec(
        "INSERT INTO pages (title, slug, lang, status, layout_type, entity_type, created_at, updated_at)"
        . " VALUES ('Проба', 'seo-audit-probe', 'ru', 'published', 'no_sidebar', 'page', NOW(), NOW())"
    );
    $pdo->exec(
        "INSERT INTO redirects (from_path, to_url, code, is_active) VALUES ('/seo-audit-probe', '/', 301, 1)"
    );

    $found = seo_finding(SeoAudit::run(false), 'redirect_conflict');
    // Поисковик увидит переадресацию, а страницу — нет: это ошибка, не совет.
    assert_same(SeoFinding::LEVEL_ERROR, $found->level);
    assert_true($found->count >= 1);
    assert_contains('/seo-audit-probe', implode(' ', $found->samples));
    // Чинить редиректы редактор идёт в свой раздел — адрес прилагается.
    assert_same('/admin/redirects', $found->fixUrl);

    $pdo->exec("DELETE FROM redirects WHERE from_path = '/seo-audit-probe'");
    $pdo->exec("DELETE FROM pages WHERE slug = 'seo-audit-probe'");
});

test('Проверка индексации: предел карты сайта совпадает с тем, что она отдаёт', function (): void {
    // Расхождение здесь означало бы, что проверка врёт о числе потерянных
    // новостей — а именно ради него она и написана.
    $sitemap = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/SitemapController.php');
    assert_contains('LIMIT ' . SeoAudit::SITEMAP_NEWS_LIMIT, $sitemap);
});

test('Проверка индексации: снимок ложится в историю и читается обратно (БД)', function (): void {
    ensure_test_db();

    $id = SeoAuditLog::save([
        new SeoFinding('probe_error', SeoFinding::LEVEL_ERROR, 'Ошибка пробы', 'Пояснение', 2, ['/x']),
        new SeoFinding('probe_ok', SeoFinding::LEVEL_OK, 'Всё хорошо'),
    ]);
    assert_true($id > 0);

    $latest = SeoAuditLog::latest();
    assert_same(1, $latest['errors']);
    assert_same(0, $latest['warnings']);
    assert_same(2, count($latest['findings']));
    assert_same('probe_error', $latest['findings'][0]->key);
    assert_same(['/x'], $latest['findings'][0]->samples);

    // История нужна, чтобы ответить «это новое или так было всегда».
    $history = SeoAuditLog::history(5);
    assert_true(count($history) >= 1);
    assert_same($latest['id'], $history[0]['id']);

    Database::pdo()->prepare('DELETE FROM seo_audits WHERE id = ?')->execute([$id]);
});

test('Проверка индексации: раздел админки закрыт, зарегистрирован и с иконкой', function (): void {
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    assert_contains("\$router->get('/admin/seo'", $routes);
    assert_contains("\$router->post('/admin/seo/run'", $routes);

    // Запуск проверки ходит по сети от имени сайта: обычному редактору такого
    // рычага не даём, и форма обязана быть с CSRF.
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/SeoController.php');
    assert_contains('Auth::requireSuperAdmin()', $controller);
    assert_contains('Csrf::verifyRequest()', $controller);

    // Неизвестный ключ Icon::render отдаёт пустой строкой — пункт меню остался
    // бы без иконки (стережёт и тест 248).
    assert_true(\App\Core\AdminUi::navigationIcon('seo') !== '');
    $nav = (string) file_get_contents(APP_ROOT . '/app/Views/admin/layout/header.php');
    assert_contains("'seo' => ['/admin/seo'", $nav);
});
