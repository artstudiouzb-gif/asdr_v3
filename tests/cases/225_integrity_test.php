<?php

declare(strict_types=1);

use App\Core\Integrity;

// Контроль целостности файлов: эталон, обнаружение подмены и защита от
// повторных алертов об одном и том же наборе расхождений.

/** Временный «сайт» с парой файлов; корень подменяется через useRoot. */
function integrity_fixture(): string
{
    $root = sys_get_temp_dir() . '/asdr_integrity_' . bin2hex(random_bytes(6));
    mkdir($root . '/app/Core', 0775, true);
    mkdir($root . '/public/uploads/public', 0775, true);
    mkdir($root . '/templates', 0775, true);
    file_put_contents($root . '/app/Core/Demo.php', '<?php // original');
    file_put_contents($root . '/templates/page.php', 'page');
    file_put_contents($root . '/public/index.php', 'front');
    // Загрузки не отслеживаются: они меняются в обычной работе.
    file_put_contents($root . '/public/uploads/public/photo.jpg', 'binary');
    Integrity::useRoot($root);

    return $root;
}

function integrity_cleanup(string $root): void
{
    Integrity::useRoot(null);
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($root);
}

test('Целостность: эталон снимается, сразу после него расхождений нет', function () {
    $root = integrity_fixture();
    try {
        assert_false(Integrity::hasBaseline(), 'эталона ещё нет');
        $count = Integrity::writeBaseline();
        assert_same(3, $count, 'в эталоне три отслеживаемых файла');
        assert_true(Integrity::hasBaseline());

        $diff = Integrity::compare();
        assert_same([], $diff['added']);
        assert_same([], $diff['changed']);
        assert_same([], $diff['removed']);
    } finally {
        integrity_cleanup($root);
    }
});

test('Целостность: подмена, подложенный файл и удаление попадают в отчёт', function () {
    $root = integrity_fixture();
    try {
        Integrity::writeBaseline();

        file_put_contents($root . '/app/Core/Demo.php', '<?php // подменено');
        file_put_contents($root . '/public/shell.php', '<?php // unauthorized script');
        unlink($root . '/templates/page.php');

        $diff = Integrity::compare();
        assert_same(['app/Core/Demo.php'], $diff['changed']);
        assert_same(['public/shell.php'], $diff['added']);
        assert_same(['templates/page.php'], $diff['removed']);

        $text = Integrity::alertText($diff);
        assert_contains('public/shell.php', $text);
        assert_contains('--baseline', $text);
    } finally {
        integrity_cleanup($root);
    }
});

test('Целостность: загрузки и storage не отслеживаются', function () {
    $root = integrity_fixture();
    try {
        Integrity::writeBaseline();
        file_put_contents($root . '/public/uploads/public/photo.jpg', 'другое содержимое');
        file_put_contents($root . '/public/uploads/public/new.jpg', 'ещё файл');

        $diff = Integrity::compare();
        assert_same([], $diff['added'], 'новая загрузка — не инцидент');
        assert_same([], $diff['changed']);
    } finally {
        integrity_cleanup($root);
    }
});

test('Целостность: повторный алерт об одном наборе не отправляется', function () {
    $root = integrity_fixture();
    try {
        Integrity::writeBaseline();
        file_put_contents($root . '/public/shell.php', '<?php // unauthorized script');

        $diff = Integrity::compare();
        assert_true(Integrity::isNewReport($diff), 'первое расхождение — новое');
        Integrity::rememberReported($diff);
        assert_false(Integrity::isNewReport($diff), 'тот же набор повторно не тревожит');

        // Появился ещё один файл — набор изменился, сообщить нужно снова.
        file_put_contents($root . '/public/shell2.php', '<?php');
        assert_true(Integrity::isNewReport(Integrity::compare()));

        // Всё вычищено — возврат к норме тоже сбрасывает отметку.
        unlink($root . '/public/shell.php');
        unlink($root . '/public/shell2.php');
        Integrity::rememberReported(Integrity::compare());
        assert_false(Integrity::isNewReport(Integrity::compare()));
    } finally {
        integrity_cleanup($root);
    }
});
