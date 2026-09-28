<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\LegacyDocumentImporter;

/*
 * Импорт раздела документов со старого сайта (Download Manager).
 *
 * В экспорте нет самих файлов — только их имена, — поэтому главный риск
 * импорта тихий: запись, не нашедшая файл, превратилась бы в ссылку в никуда.
 * Отсюда проверки: какой файл считается «тем самым» (плагин приписывает к
 * имени отметку времени, архив с Mac раскладывает «ў» на две точки кода),
 * что не находится — называется, закрытое на старом сайте не выкладывается
 * открытой ссылкой, а повторный запуск не плодит копий.
 */

function wpdm_item(int $id, string $title, string $file, array $cats, string $status = 'publish', string $access = 'a:1:{i:0;s:5:"guest";}', string $password = ''): string
{
    $files = serialize([1 => $file]);
    $info = serialize([1 => ['title' => $file, 'password' => '']]);
    $catXml = '';
    foreach ($cats as $slug => $name) {
        $catXml .= '<category domain="wpdmcategory" nicename="' . $slug . '"><![CDATA[' . $name . ']]></category>';
    }
    $meta = static fn (string $k, string $v): string => '<wp:postmeta><wp:meta_key><![CDATA[' . $k . ']]></wp:meta_key><wp:meta_value><![CDATA[' . $v . ']]></wp:meta_value></wp:postmeta>';

    return '<item><title><![CDATA[' . $title . ']]></title><link>https://old.example/download/doc-' . $id . '/</link>'
        . '<wp:post_id>' . $id . '</wp:post_id><wp:post_date><![CDATA[2025-01-0' . ($id % 9 + 1) . ' 10:00:00]]></wp:post_date>'
        . '<wp:post_name><![CDATA[doc-' . $id . ']]></wp:post_name><wp:status><![CDATA[' . $status . ']]></wp:status>'
        . '<wp:post_type><![CDATA[wpdmpro]]></wp:post_type>' . $catXml
        . $meta('__wpdm_files', $files) . $meta('__wpdm_fileinfo', $info)
        . $meta('__wpdm_access', $access) . $meta('__wpdm_password', $password)
        . '</item>';
}

function wpdm_xml(string $items): string
{
    $wpNs = 'http://' . 'word' . 'press.org/export/1.2/';

    return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:wp="' . $wpNs . '"'
        . ' xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel>'
        . '<wp:base_site_url>https://old.example</wp:base_site_url>' . $items
        . '<item><title>Скан</title><wp:post_id>999</wp:post_id><wp:post_type><![CDATA[attachment]]></wp:post_type><wp:status>inherit</wp:status></item>'
        . '</channel></rss>';
}

test('Разбор экспорта: публикуются только открытые записи с одним файлом', function (): void {
    $xml = wpdm_xml(
        wpdm_item(1, 'Plan 2023', 'plan.pdf', ['wgs-2023' => 'WGs Work Plans 2023', 'ish' => 'Ishchi guruhning 2023 yilgi rejalari'])
        . wpdm_item(2, 'Черновик', 'draft.pdf', [], 'draft')
        . wpdm_item(3, 'Для своих', 'secret.pdf', [], 'publish', 'a:1:{i:0;s:10:"subscriber";}')
        . wpdm_item(4, 'С паролем', 'pw.pdf', [], 'publish', 'a:1:{i:0;s:5:"guest";}', '123')
        . wpdm_item(5, 'Energy', 'e.pdf', ['energy' => 'Energy &amp;amp; Mining'])
    );
    $data = LegacyDocumentImporter::parse($xml);

    assert_same(['Plan 2023', 'Energy'], array_column($data['documents'], 'title'), 'вложения и закрытые записи не документы');
    assert_same(3, count($data['skipped']), 'пропущенное названо с причиной, а не потеряно молча');
    assert_same('Energy & Mining', $data['documents'][1]['categories']['energy'], 'двойное экранирование снято');
    assert_same('https://old.example/download/doc-1/', $data['documents'][0]['link']);
});

test('Язык рубрики выводится из названия', function (): void {
    assert_same('uz', LegacyDocumentImporter::categoryLang('Davra suhbati 1 – 25.02.2025'));
    assert_same('uz', LegacyDocumentImporter::categoryLang('2025 yil'));
    assert_same('en', LegacyDocumentImporter::categoryLang('Roundtable 1 – 25.02.2025'));
    assert_same('ru', LegacyDocumentImporter::categoryLang('Отчеты ВБ'));
});

test('Файл находится по имени плагина, без отметки времени и в другой форме Unicode', function (): void {
    $dir = sys_get_temp_dir() . '/wpdm-' . bin2hex(random_bytes(5));
    mkdir($dir . '/download-manager-files', 0755, true);
    file_put_contents($dir . '/download-manager-files/Report.PDF', 'x');
    file_put_contents($dir . '/download-manager-files/list.pdf', 'x');
    // «ў» разложенной формой (у + бреве), как её пишет архиватор на Mac.
    file_put_contents($dir . '/download-manager-files/' . "ro\u{0443}\u{0306}xat.pdf", 'x');
    file_put_contents($dir . '/twice-a.pdf', 'x');
    mkdir($dir . '/sub');
    file_put_contents($dir . '/sub/1700000000wpdm_twice-a.pdf', 'x');
    $index = LegacyDocumentImporter::indexFiles($dir);

    assert_true(str_ends_with((string) LegacyDocumentImporter::locate('report.pdf', $index), 'Report.PDF'), 'регистр не важен');
    assert_true(str_ends_with((string) LegacyDocumentImporter::locate('1736063738wpdm_list.pdf', $index), 'list.pdf'), 'отметка времени плагина снимается');
    assert_true(LegacyDocumentImporter::locate("ro\u{045E}xat.pdf", $index) !== null || !class_exists(\Normalizer::class), 'NFC и NFD — одно имя');
    assert_true(str_ends_with((string) LegacyDocumentImporter::locate('1700000000wpdm_twice-a.pdf', $index), 'sub/1700000000wpdm_twice-a.pdf'), 'точное имя главнее «чистого»');
    assert_same(null, LegacyDocumentImporter::locate('1800000000wpdm_twice-a.pdf', $index), 'неоднозначное «чистое» имя не угадывается');
    assert_same(null, LegacyDocumentImporter::locate('absent.pdf', $index));

    array_map('unlink', array_filter((array) glob($dir . '/{,*/}*', GLOB_BRACE), 'is_file'));
});

test('Импорт переносит файлы, собирает черновики и не дублирует при повторе (БД)', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();
    $tag = bin2hex(random_bytes(4));
    $inbox = sys_get_temp_dir() . '/wpdm-inbox-' . $tag;
    mkdir($inbox . '/download-manager-files', 0755, true);
    $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    file_put_contents($inbox . '/download-manager-files/plan-' . $tag . '.pdf', $pdf);
    file_put_contents($inbox . '/download-manager-files/1736063738wpdm_minutes-' . $tag . '.pdf', $pdf);
    file_put_contents($inbox . '/export.xml', wpdm_xml(
        wpdm_item(11, 'Plan', 'plan-' . $tag . '.pdf', ['wg-' . $tag => 'WGs Work Plans 2023', 'ish-' . $tag => 'Ishchi guruhning 2023 yilgi rejalari'])
        . wpdm_item(12, 'Minutes', '1736063738wpdm_minutes-' . $tag . '.pdf', ['rt-' . $tag => 'Roundtable 1'])
        . wpdm_item(13, 'Minutes copy', '1736063738wpdm_minutes-' . $tag . '.pdf', ['rt-' . $tag => 'Roundtable 1'])
        . wpdm_item(14, 'Lost', 'lost-' . $tag . '.pdf', ['rt-' . $tag => 'Roundtable 1'])
    ));
    $filesBefore = (int) $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();

    $dry = LegacyDocumentImporter::run($inbox, true, 0, 30.0, null);
    assert_same(2, $dry['planned'], 'проверка находит оба файла');
    assert_same(1, $dry['missing'], 'и называет недостающий');
    assert_same(['lost-' . $tag . '.pdf'], $dry['missing_files']);
    assert_same($filesBefore, (int) $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn(), 'проверка ничего не пишет');

    $run = LegacyDocumentImporter::run($inbox, false, 0, 30.0, null);
    $state = json_decode((string) file_get_contents($inbox . '/.import-state.json'), true);
    try {
        assert_true($run['done'], 'четыре записи — один пакет');
        assert_same(2, $run['imported'], 'один и тот же файл двух записей переносится один раз');
        assert_same(1, $run['reused']);
        assert_same(3, $run['redirects'], 'старые адреса получили редиректы');
        assert_same($filesBefore + 2, (int) $pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
        assert_false(is_file($inbox . '/download-manager-files/plan-' . $tag . '.pdf'), 'файл перенесён, а не скопирован');

        $pageIds = array_values((array) $state['pages']);
        assert_true($pageIds !== [], 'созданы черновики страниц');
        $blocks = $pdo->query('SELECT b.type, b.data, p.status, p.slug FROM blocks b JOIN pages p ON p.id = b.page_id WHERE b.page_id IN (' . implode(',', array_map('intval', $pageIds)) . ')')->fetchAll(PDO::FETCH_ASSOC);
        assert_same(3, count($blocks), 'по блоку на рубрику');
        $titles = [];
        foreach ($blocks as $block) {
            assert_same('docs_list', $block['type']);
            assert_same('draft', $block['status'], 'страницы приходят черновиками');
            $data = json_decode((string) $block['data'], true);
            $titles[$data['title']] = array_column($data['items'], 'title');
            foreach ($data['items'] as $item) {
                assert_true(str_starts_with($item['url'], '/uploads/'), 'ссылка ведёт в медиатеку');
            }
        }
        assert_same(['Minutes', 'Minutes copy'], $titles['Roundtable 1'], 'документ без файла в блок не попал');
        assert_same(1, count(array_unique(array_column($blocks, 'slug'))), 'языковые версии делят один адрес');

        $again = LegacyDocumentImporter::run($inbox, false, 0, 30.0, null);
        assert_same(0, $again['imported'], 'повторный запуск не переносит заново');
        assert_same(3, $again['skipped']);
        assert_same(0, $again['pages'], 'новых страниц не появилось');
        assert_same(3, (int) $pdo->query('SELECT COUNT(*) FROM blocks WHERE page_id IN (' . implode(',', array_map('intval', $pageIds)) . ')')->fetchColumn(), 'блоки черновика пересобраны, а не удвоены');
    } finally {
        $uploads = rtrim((string) \App\Core\Config::get('paths.public_uploads'), '/');
        foreach (array_unique(array_values((array) ($state['files'] ?? []))) as $url) {
            @unlink($uploads . '/' . basename((string) $url));
            $pdo->prepare('DELETE FROM files WHERE stored_name = :n')->execute([':n' => basename((string) $url)]);
        }
        foreach ((array) ($state['pages'] ?? []) as $pageId) {
            $pdo->prepare('DELETE FROM blocks WHERE page_id = :id')->execute([':id' => (int) $pageId]);
            $pdo->prepare('DELETE FROM pages WHERE id = :id')->execute([':id' => (int) $pageId]);
        }
        $pdo->exec("DELETE FROM redirects WHERE from_path LIKE '/download/doc-1%'");
        array_map('unlink', array_filter((array) glob($inbox . '/{,.,*/}*', GLOB_BRACE), 'is_file'));
    }
});
