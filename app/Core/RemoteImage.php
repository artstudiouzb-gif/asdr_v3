<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\FileEntry;

/**
 * Картинка с чужого сервера — копией в медиатеку.
 *
 * Чужой адрес в разметке не годится дважды: публичная CSP разрешает картинки
 * только со своего сайта, а у соцсетей ссылка на кадр подписана и живёт
 * несколько дней. Поэтому кадр скачивается один раз, проходит те же проверки,
 * что и загрузка из формы (тип по содержимому, размер), и дальше отдаётся
 * своим адресом. Читают двое — импорт YouTube и лента Instagram: правила
 * приёма у них одни, и вторая копия разошлась бы с первой.
 */
final class RemoteImage
{
    public const MAX_BYTES = 5242880;

    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * Скачивает картинку и кладёт её в медиатеку. Отказ — null, без
     * исключения: один недоставшийся кадр не должен обрывать весь импорт.
     *
     * @param string $basename имя файла без расширения (расширение — по содержимому)
     */
    public static function import(string $url, string $basename, ?int $userId, string $context, int $maxBytes = self::MAX_BYTES): ?string
    {
        $tmp = null;
        $named = null;
        try {
            $res = Http::getSafeRemote($url, ['Accept: image/*'], 20, $maxBytes);
            if ((int) $res['status'] !== 200 || strlen($res['body']) < 1024) {
                return null;
            }
            $temp = tempnam(sys_get_temp_dir(), 'rimg_');
            if ($temp === false) {
                return null;
            }
            $tmp = $temp;
            if (file_put_contents($tmp, $res['body']) === false) {
                return null;
            }
            $ext = self::EXTENSIONS[(string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp)] ?? null;
            if ($ext === null) {
                return null;
            }
            $named = $tmp . '.' . $ext;
            if (!@rename($tmp, $named)) {
                return null;
            }
            $tmp = null;
            $file = Uploader::storeFromPath(
                $named,
                $basename . '.' . $ext,
                (int) filesize($named),
                'public',
                $userId,
                false,
                $maxBytes,
                ['jpg', 'jpeg', 'png', 'webp']
            );

            return FileEntry::publicUrl($file);
        } catch (\Throwable $e) {
            Logger::swallowed($context . ': кадр не сохранён', $e);

            return null;
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
            if ($named !== null) {
                @unlink($named);
            }
        }
    }
}
