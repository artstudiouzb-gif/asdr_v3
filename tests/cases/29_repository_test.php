<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\RepoCategory;
use App\Models\RepoFile;
use App\Models\RepoUser;

// Все проверки этого файла требуют тестовую БД (см. TEST_DB_* в run.php,
// ensure_test_db() определена в 08_bulk_duplicate_search_test.php).

test('RepoUser: создание, поиск, уникальность, активность и 2FA', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM repo_users');

    $id = RepoUser::create('portal_user', 'Иван Иванов', 'portal@example.com', 'Str0ng-Pass-99');
    assert_true($id > 0, 'вернулся id');

    $found = RepoUser::findByUsername('portal_user');
    assert_true($found !== null, 'пользователь найден');
    assert_same('Иван Иванов', $found['full_name']);
    assert_true(password_verify('Str0ng-Pass-99', $found['password_hash']), 'пароль хэширован');
    assert_same(1, (int) $found['is_active']);
    assert_same(0, (int) $found['totp_enabled']);

    assert_true(RepoUser::usernameExists('portal_user'), 'usernameExists');
    assert_true(RepoUser::emailExists('portal@example.com'), 'emailExists');
    assert_false(RepoUser::usernameExists('nope'), 'нет такого логина');

    RepoUser::setActive($id, false);
    assert_same(0, (int) RepoUser::findById($id)['is_active']);
    RepoUser::setActive($id, true);
    assert_same(1, (int) RepoUser::findById($id)['is_active']);

    RepoUser::enableTotp($id, 'JBSWY3DPEHPK3PXP');
    $u = RepoUser::findById($id);
    assert_same(1, (int) $u['totp_enabled']);
    assert_same('JBSWY3DPEHPK3PXP', $u['totp_secret']);
    $rawSecret = (string) $pdo->query('SELECT totp_secret FROM repo_users WHERE id = ' . $id)->fetchColumn();
    assert_true(str_starts_with($rawSecret, 'enc:v1:'), 'TOTP зашифрован в БД');
    assert_false(str_contains($rawSecret, 'JBSWY3DPEHPK3PXP'), 'открытый TOTP отсутствует в БД');
    RepoUser::disableTotp($id);
    $u = RepoUser::findById($id);
    assert_same(0, (int) $u['totp_enabled']);
    assert_true($u['totp_secret'] === null, 'секрет сброшен');

    RepoUser::updatePassword($id, 'New-Pass-2026!');
    assert_true(password_verify('New-Pass-2026!', RepoUser::findById($id)['password_hash']), 'пароль обновлён');

    RepoUser::delete($id);
    assert_true(RepoUser::findById($id) === null, 'пользователь удалён');
});

test('RepoFile: поиск, фильтр по категории/подкатегории, дерево категорий', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM repo_files');
    $pdo->exec('DELETE FROM repo_categories');

    $orders = RepoCategory::create('Приказы');
    $reports = RepoCategory::create('Отчёты');
    $yearly = RepoCategory::create('Годовые', $reports);
    assert_true($orders > 0 && $yearly > 0, 'категории созданы');

    $insert = $pdo->prepare(
        'INSERT INTO repo_files (title, description, category_id, stored_name, original_name, mime_type, size, created_at)
         VALUES (:t, :d, :c, :s, :o, :m, :sz, NOW())'
    );
    $insert->execute([':t' => 'Приказ №1', ':d' => 'О назначении', ':c' => $orders, ':s' => 'a.pdf', ':o' => 'prikaz1.pdf', ':m' => 'application/pdf', ':sz' => 1024]);
    $insert->execute([':t' => 'Отчёт за год', ':d' => 'Финансовый', ':c' => $yearly, ':s' => 'b.pdf', ':o' => 'report.pdf', ':m' => 'application/pdf', ':sz' => 2048]);
    $insert->execute([':t' => 'Инструкция', ':d' => null, ':c' => null, ':s' => 'c.docx', ':o' => 'guide.docx', ':m' => 'application/octet-stream', ':sz' => 512]);

    assert_same(3, count(RepoFile::all()));

    $byQuery = RepoFile::all('Приказ');
    assert_same(1, count($byQuery));
    assert_same('Приказ №1', $byQuery[0]['title']);
    assert_same('Приказы', $byQuery[0]['category'], 'computed-имя категории');

    // Поиск также по имени файла и по имени категории (включая родителя).
    assert_same(1, count(RepoFile::all('report.pdf')));
    assert_same(1, count(RepoFile::all('Годовые')));
    assert_same(1, count(RepoFile::all('Отчёты')));

    // Фильтр: точная категория и корневая с подкатегориями.
    assert_same(1, count(RepoFile::all('', $orders)));
    assert_same(1, count(RepoFile::all('', $yearly)));
    assert_same(1, count(RepoFile::all('', $reports)), 'корень включает файлы подкатегорий');
    $sub = RepoFile::all('', $reports)[0];
    assert_same('Отчёты / Годовые', $sub['category'], 'полное имя «Родитель / Дочка»');

    // Дерево и плоский список.
    $tree = RepoCategory::tree();
    assert_same(2, count($tree));
    $flat = RepoCategory::flatOptions();
    assert_same(3, count($flat));
    $labels = array_column($flat, 'label');
    assert_true(in_array('Отчёты / Годовые', $labels, true), 'label подкатегории с родителем');

    // Обновление метаданных файла и снятие категории.
    RepoFile::updateMeta((int) $byQuery[0]['id'], 'Приказ №1 (ред.)', 'Новое описание', null);
    $updated = RepoFile::findById((int) $byQuery[0]['id']);
    assert_same('Приказ №1 (ред.)', $updated['title']);
    assert_true($updated['category_id'] === null, 'категория снята');

    // Удаление корня: подкатегории каскадом, файлы остаются без категории.
    RepoCategory::delete($reports);
    assert_true(RepoCategory::findById($yearly) === null, 'подкатегория удалена каскадом');
    $orphan = RepoFile::all('report.pdf')[0];
    assert_true($orphan['category_id'] === null, 'файл остался без категории');

    $pdo->exec('DELETE FROM repo_files');
    $pdo->exec('DELETE FROM repo_categories');
});

test('RepoFile: премодерация пользовательских загрузок (pending → approved)', function () {
    ensure_test_db();
    $pdo = Database::pdo();
    $pdo->exec('DELETE FROM repo_files');
    $pdo->exec('DELETE FROM repo_users');

    $uid = RepoUser::create('uploader', 'Пётр Петров', 'uploader@example.com', 'Str0ng-Pass-99', 'ГУП «Центр»');
    $user = RepoUser::findById($uid);
    assert_same('ГУП «Центр»', $user['organization'], 'организация сохранена');

    // Привязка Telegram для 2FA: установка и отвязка chat_id.
    RepoUser::setTelegramChatId($uid, 123456789);
    assert_same(123456789, (int) RepoUser::findById($uid)['telegram_chat_id']);
    RepoUser::setTelegramChatId($uid, null);
    assert_true(RepoUser::findById($uid)['telegram_chat_id'] === null, 'chat_id отвязан');

    $insert = $pdo->prepare(
        'INSERT INTO repo_files (title, status, uploaded_by_repo_user, stored_name, original_name, mime_type, size, created_at)
         VALUES (:t, :st, :u, :s, :o, :m, :sz, NOW())'
    );
    $insert->execute([':t' => 'Предложенный документ', ':st' => 'pending', ':u' => $uid, ':s' => 'p.pdf', ':o' => 'prop.pdf', ':m' => 'application/pdf', ':sz' => 100]);
    $insert->execute([':t' => 'Обычный документ', ':st' => 'approved', ':u' => null, ':s' => 'q.pdf', ':o' => 'ok.pdf', ':m' => 'application/pdf', ':sz' => 100]);

    // Портал видит только одобренные; pending() отдаёт ждущие с автором.
    assert_same(1, count(RepoFile::all()));
    assert_same(1, RepoFile::pendingCount());
    $pending = RepoFile::pending();
    assert_same(1, count($pending));
    assert_same('uploader', $pending[0]['repo_username'], 'логин автора в модерации');

    RepoFile::approve((int) $pending[0]['id']);
    assert_same(2, count(RepoFile::all()), 'после одобрения файл виден');
    assert_same(0, RepoFile::pendingCount());

    $pdo->exec('DELETE FROM repo_files');
    $pdo->exec('DELETE FROM repo_users');
});

test('RepoFile::basePath указывает в подкаталог protected_uploads/repo', function () {
    $base = RepoFile::basePath();
    assert_contains('protected_uploads/repo', str_replace('\\', '/', $base));
});
