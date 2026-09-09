<?php

declare(strict_types=1);

require __DIR__ . '/../../app/Core/Cli.php';
\App\Core\Cli::assertCli();

require __DIR__ . '/../../app/Core/bootstrap.php';

/*
 * Фикстура админки для визуальных регресс-тестов.
 *
 *   php tests/browser/seed_admin_visual.php
 *
 * Создаёт учётную запись с известным паролем и известным секретом TOTP, а
 * также запись новости — чтобы список, форма и панель показывали одно и то же
 * при каждом прогоне. Пароль и секрет заданы константами и продублированы в
 * tests/browser/admin-visual.spec.js: файла с секретом на диске нет, а сами
 * значения имеют смысл только в одноразовой тестовой базе.
 *
 * Скрипт отказывается работать вне testing/development: та же команда на
 * боевой базе завела бы администратора с опубликованным паролем.
 */

use App\Core\Config;
use App\Core\Database;
use App\Models\Language;
use App\Models\User;

const VISUAL_ADMIN_USERNAME = 'visual';
const VISUAL_ADMIN_EMAIL = 'visual@example.test';
const VISUAL_ADMIN_PASSWORD = 'Visual-regression-1';
// 32 символа base32 — секрет приложения-аутентификатора. Код по нему считает
// сам тест (tests/browser/admin-visual.spec.js), поэтому вход идёт обычным
// путём, со вторым фактором, а не в обход него.
const VISUAL_ADMIN_TOTP = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
// Двух записей достаточно, чтобы в списке была и чётная строка: полоска
// строится подложкой, и без второй строки её просто нечем снимать.
const VISUAL_NEWS_TITLE = 'Заседание коллегии Агентства';
const VISUAL_NEWS_TITLE_SECOND = 'Итоги квартала: показатели реформ';

$env = (string) Config::get('app.env', 'production');
if (!in_array($env, ['testing', 'development'], true)) {
    fwrite(STDERR, "Фикстура админки работает только при APP_ENV=testing или development (сейчас: {$env}).\n");
    exit(1);
}

// Секрет TOTP хранится зашифрованным. Без ключа SecretBox бросает исключение,
// а обработчик ошибок в CLI печатает трассу и выходит с нулём — шаг CI считался
// успешным, фикстура ложилась наполовину, и падал уже вход в панель, где
// причину было не видно. Поэтому проверяем ключ здесь и падаем громко.
if ((string) Config::get('crypto.encryption_key', '') === '') {
    fwrite(STDERR, "Нужен APP_ENCRYPTION_KEY: секрет TOTP тестовой учётки хранится зашифрованным.\n");
    exit(1);
}

$pdo = Database::pdo();

$existing = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$existing->execute([VISUAL_ADMIN_USERNAME]);
$userId = (int) ($existing->fetchColumn() ?: 0);

if ($userId === 0) {
    $userId = User::create(VISUAL_ADMIN_USERNAME, VISUAL_ADMIN_EMAIL, VISUAL_ADMIN_PASSWORD, 'admin');
} else {
    User::updatePassword($userId, VISUAL_ADMIN_PASSWORD);
}
User::enableTotp($userId, VISUAL_ADMIN_TOTP);

// Записи списка: без них таблица показывает пустое состояние, и снимок не о
// чем было бы сверять. Одна опубликована, вторая черновик — в колонке статуса
// видно обе метки.
$rows = [
    [VISUAL_NEWS_TITLE, 'visual-regression-news', 'published', '2026-01-15 10:00:00'],
    [VISUAL_NEWS_TITLE_SECOND, 'visual-regression-news-2', 'draft', '2026-01-12 09:30:00'],
];
foreach ($rows as [$title, $slug, $status, $publishedAt]) {
    $news = $pdo->prepare('SELECT id FROM news WHERE title = ?');
    $news->execute([$title]);
    if ((int) ($news->fetchColumn() ?: 0) !== 0) {
        continue;
    }

    $pdo->prepare(
        'INSERT INTO news (title, slug, lang, excerpt, content, status, published_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    )->execute([
        $title,
        $slug,
        Language::defaultCode(),
        'Фиксированная запись для визуальных тестов админки.',
        '<p>Содержимое не меняется, поэтому расхождение снимка означает правку оформления.</p>',
        $status,
        $publishedAt,
    ]);
}

// Два файла в медиатеке: без них и страница медиабиблиотеки, и сетка в окне
// выбора показывают пустое состояние — карточку файла нечем снимать, а она
// как раз общая для обоих мест.
$uploadsDir = (string) Config::get('paths.public_uploads', '');
$media = [
    ['visual-regression-photo.jpg', 'visual-regression-photo.jpg', 'image/jpeg'],
    ['постановление-2026.pdf', 'visual-regression-doc.pdf', 'application/pdf'],
];
foreach ($media as [$original, $stored, $mime]) {
    $path = $uploadsDir . '/' . $stored;
    if (!is_file($path)) {
        if (str_starts_with($mime, 'image/') && function_exists('imagejpeg')) {
            $image = imagecreatetruecolor(480, 300);
            for ($y = 0; $y < 300; $y++) {
                $shade = (int) (30 + ($y / 300) * 120);
                imageline($image, 0, $y, 480, $y, imagecolorallocate($image, $shade, $shade + 20, $shade + 45));
            }
            imagejpeg($image, $path, 82);
            unset($image);
        } else {
            file_put_contents($path, "%PDF-1.4\n% фикстура визуальных тестов\n");
        }
        @chmod($path, 0644);
    }

    $exists = $pdo->prepare('SELECT id FROM files WHERE stored_name = ?');
    $exists->execute([$stored]);
    if ((int) ($exists->fetchColumn() ?: 0) !== 0) {
        continue;
    }

    $pdo->prepare(
        "INSERT INTO files (original_name, stored_name, mime_type, size, access_type, created_at)
         VALUES (?, ?, ?, ?, 'public', ?)"
    )->execute([$original, $stored, $mime, (int) (@filesize($path) ?: 1024), '2026-01-10 12:00:00']);
}

echo 'Фикстура админки готова: пользователь ' . VISUAL_ADMIN_USERNAME
    . ', новостей: ' . count($rows)
    . ', файлов: ' . count($media) . PHP_EOL;
