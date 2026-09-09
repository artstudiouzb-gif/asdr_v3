<?php

declare(strict_types=1);

/**
 * Загрузка не должна отдавать «ничего» вместо записи о файле.
 */

test('Uploader объясняет несозданную запись, а не роняет вызывающего TypeError', function (): void {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/Uploader.php');

    // Метод объявлен возвращающим массив, а FileEntry::findById() отдаёт
    // ?array: без проверки null уходил бы в return и в файле со strict_types
    // превращался в TypeError у вызывающего — вместо объяснения, что случилось.
    assert_not_contains(
        'return FileEntry::findById($id);',
        $src,
        'результат чтения записи обязан проверяться на null'
    );
    assert_contains('$file = FileEntry::findById($id);', $src);
    assert_contains('if ($file === null) {', $src);

    // Сценарий не выдуманный: id приходит из lastInsertId().
    $entry = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Models/FileEntry.php');
    assert_contains('lastInsertId()', $entry, 'источник id — тот самый, что умеет обнуляться');

    // Файл с диска не удаляем: если вставка прошла, а потерялся только id,
    // удаление оставило бы строку в базе без файла.
    assert_not_contains('unlink($destination)', $src, 'удаление файла здесь опаснее, чем неучтённый файл');
});

test('Портал и админка одинаково переживают сбой БД в проверке сессии', function (): void {
    $repo = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/RepoAuth.php');
    $admin = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Core/Auth.php');

    // Осознанное решение: фингерпринт проверен выше, поэтому транзиентная
    // ошибка БД логируется и пропускается, а не выбрасывает всех на вход.
    // Два разных поведения в одинаковой ситуации разъехались бы при первой
    // правке, поэтому проверяются вместе.
    foreach (['RepoAuth' => $repo, 'Auth' => $admin] as $name => $src) {
        assert_contains(
            'Транзиентная ошибка БД не должна разлогинивать всех',
            $src,
            $name . ': решение о fail-open обязано быть объяснено на месте'
        );
    }
});
