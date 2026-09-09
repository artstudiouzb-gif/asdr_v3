<?php

declare(strict_types=1);

use App\Core\Uploader;

/**
 * Загрузка .css/.js через файловый менеджер: способ обновить постраничный код
 * с телефона, не вставляя сотни килобайт в textarea. Разрешение выдаётся
 * только супер-администратору — той же роли, что правит поля «произвольный
 * CSS/JS» у страницы, так что поверхность доверия не расширяется.
 */

function upload_probe(string $name, string $body, bool $allowCode): string
{
    $path = sys_get_temp_dir() . '/u30-upload-probe-' . bin2hex(random_bytes(4));
    file_put_contents($path, $body);
    try {
        Uploader::storeFromPath($path, $name, (int) filesize($path), 'public', null, false, 20971520, null, $allowCode);
        return 'принят';
    } catch (Throwable $e) {
        $message = $e->getMessage();
        // Отказы валидации отличаем от падений дальше по пути (запись на диск,
        // БД) — для проверки разрешений важен только сам факт допуска типа.
        return str_starts_with($message, 'Недопустимый тип файла')
            || str_starts_with($message, 'Содержимое файла')
            || str_starts_with($message, 'Этот тип файла')
            ? $message
            : 'принят';
    } finally {
        @unlink($path);
    }
}

test('Загрузка кода: .css и .js только с явным разрешением', function (): void {
    assert_same('Недопустимый тип файла: .css', upload_probe('style.css', 'body{color:red}', false));
    assert_same('Недопустимый тип файла: .js', upload_probe('app.js', 'console.log(1)', false));

    assert_same('принят', upload_probe('style.css', 'body{color:red}', true));
    assert_same('принят', upload_probe('app.js', 'console.log(1)', true));
});

test('Загрузка кода: исполняемые расширения не проходят никогда', function (): void {
    foreach (['shell.php', 'x.phtml', 'a.htaccess', 'b.sh', 'c.html'] as $name) {
        $result = upload_probe($name, '<?php echo 1;', true);
        assert_true($result !== 'принят', $name . ': не должен приниматься, получено «' . $result . '»');
    }
});

test('Загрузка кода: публичные формы сайта разрешение не получают', function (): void {
    // Через Uploader идут и вложения из форм посетителей — они обязаны
    // вызывать store() без флага, иначе аноним смог бы залить скрипт.
    $form = (string) file_get_contents(APP_ROOT . '/app/Controllers/Site/FormController.php');
    assert_not_contains('true', substr($form, (int) strpos($form, 'Uploader::store'), 220));

    // Файловый менеджер, наоборот, обязан спрашивать роль, а не разрешать всем.
    $files = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/FileController.php');
    assert_contains('Auth::isSuperAdmin()', $files);
});
