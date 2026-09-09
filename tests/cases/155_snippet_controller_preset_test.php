<?php

declare(strict_types=1);

test('SnippetController::applyPreset корректно создает бэкап и не выдает Undefined variable $backup', function (): void {
    $source = (string) file_get_contents(
        dirname(__DIR__, 2) . '/app/Controllers/Admin/SnippetController.php'
    );
    $methodStart = strpos($source, 'public function applyPreset');
    $methodEnd = strpos($source, 'public function destroy', (int) $methodStart);
    assert_true($methodStart !== false && $methodEnd !== false, 'Метод applyPreset найден');
    $method = substr($source, (int) $methodStart, (int) $methodEnd - (int) $methodStart);

    $assignment = strpos($method, '$backup = $replace ? BlockSnippet::autoBackup');
    $usage = strpos($method, 'if ($backup !== null)');
    assert_true($assignment !== false, 'Бэкап инициализируется для обоих режимов');
    assert_true($usage !== false && $assignment < $usage, 'Переменная инициализирована до использования');
});
