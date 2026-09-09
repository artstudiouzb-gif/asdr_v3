<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\FileEntry;

/**
 * Универсальная обработка полей "изображение" в формах админки: если
 * загружен файл — сохраняем его через Uploader и используем публичную
 * ссылку, иначе используем вручную вставленный URL (внешний адрес или
 * уже существующее значение при редактировании).
 */
final class ImageField
{
    public static function resolve(string $fileInputName, string $urlInputName, ?string $existingUrl, ?int $uploadedBy): ?string
    {
        if (!empty($_FILES[$fileInputName]) && ($_FILES[$fileInputName]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $file = Uploader::store($_FILES[$fileInputName], 'public', $uploadedBy);
            return FileEntry::publicUrl($file);
        }

        // Если поле URL присутствует в форме — учитываем его дословно: пустое
        // значение означает явную очистку (пользователь нажал «×»). Только когда
        // поля нет в запросе вовсе — сохраняем ранее сохранённое значение.
        if (array_key_exists($urlInputName, $_POST)) {
            return trim((string) $_POST[$urlInputName]);
        }

        return $existingUrl;
    }
}
