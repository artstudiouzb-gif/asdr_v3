<?php

declare(strict_types=1);

namespace App\Core\Ai;

use App\Core\Config;
use App\Core\Media;
use App\Models\FileEntry;

/**
 * Альтернативный текст изображения.
 *
 * Колонка `files.alt_text` есть давно, наследуется в галерею новости и
 * печатается в `<img alt>` — но заполняется руками, то есть почти никогда.
 * axe такого не ловит вовсе: для него `alt=""` и осмысленная подпись
 * неразличимы, и в наших же заметках «осмысленность alt» отнесена к ручной
 * проверке. Это она и есть, только делает её модель, а редактор правит.
 *
 * Картинка уходит не оригиналом: у нас рядом лежат уменьшенные копии
 * (`Media::VARIANT_WIDTHS`), и снимок на 800px модель описывает не хуже, чем
 * тот же кадр на 2560px весом в мегабайт.
 */
final class AiAltText
{
    /** Длина колонки `files.alt_text`; заодно предел разумной подписи. */
    public const MAX_LENGTH = 255;

    /** Сколько секунд пакет успевает работать до возврата курсора. */
    private const BATCH_SECONDS = 12.0;

    /**
     * Подпись для одного файла медиатеки.
     *
     * @return array{alt:string, provider:string, notice:string}
     */
    public static function forFile(int $fileId): array
    {
        $file = FileEntry::findById($fileId);
        if ($file === null) {
            return self::fail('Файл не найден.');
        }

        return self::forPath(
            self::diskPath((string) ($file['stored_name'] ?? '')),
            (string) ($file['original_name'] ?? '')
        );
    }

    /**
     * Подпись для файла на диске. Путь приходит из медиатеки, а не из формы:
     * имя файла в запросе позволило бы читать чужие файлы сервера.
     *
     * @return array{alt:string, provider:string, notice:string}
     */
    public static function forPath(string $path, string $originalName = ''): array
    {
        if (!AiClient::configured()) {
            return self::fail('Ключ Gemini не настроен: подпись к изображению может дать только модель — по имени файла её не угадать.');
        }

        $source = self::readableSource($path);
        if ($source === null) {
            return self::fail('Файл недоступен для чтения или это не изображение.');
        }

        $result = AiClient::json(
            'Ты специалист по доступности. Описываешь изображения для незрячих посетителей '
                . 'официального сайта государственного агентства.',
            "Опиши изображение одной строкой на русском языке:\n"
                . "- 60–140 символов, одно предложение без точки в конце;\n"
                . "- что именно изображено: люди и их занятие, место, объект, документ;\n"
                . "- не начинай со слов «изображение», «фотография», «на фото»;\n"
                . "- не выдумывай имён, должностей и названий, которых не видно;\n"
                . "- если на снимке читаемый текст (вывеска, заголовок документа) — приведи его.\n\n"
                . 'Имя файла (подсказка, может быть бессмысленной): ' . mb_substr($originalName, 0, 120),
            [
                'type' => 'object',
                'properties' => [
                    'alt' => ['type' => 'string', 'description' => 'Альтернативный текст изображения.'],
                ],
                'required' => ['alt'],
            ],
            [
                'image' => $source,
                'label' => 'alt-текст изображения',
                'maxTokens' => 256,
                'temperature' => 0.2,
            ]
        );

        $alt = AiClient::str($result, 'alt');
        if ($alt === '') {
            return self::fail('Модель не описала изображение — попробуйте ещё раз или впишите подпись сами.');
        }

        return [
            'alt' => mb_substr(self::tidy($alt), 0, self::MAX_LENGTH),
            'provider' => 'gemini',
            'notice' => '',
        ];
    }

    /**
     * Пакетный проход по медиатеке: снимки без подписи.
     *
     * Пакетами, а не одним ответом, по той же причине, что у достройки
     * миниатюр: шлюз обрывает длинный запрос, и чем больше медиатека, тем
     * вернее отказ. Здесь к этому добавляется цена: каждый снимок — отдельное
     * обращение к модели, поэтому счёт обработанного виден в панели, а
     * остановка работает в любой момент.
     *
     * @return array{cursor:int, total:int, scanned:int, planned:int, fixed:int, skipped:int, failed:int}
     */
    public static function run(bool $dryRun, int $offset, float $budget = self::BATCH_SECONDS): array
    {
        $offset = max(0, $offset);
        $started = microtime(true);

        // В пробном проходе записи не меняются, поэтому набор кандидатов не
        // тает и курсор — обычная страница списка. В боевом каждый снимок
        // получает подпись и из набора уходит, значит брать надо всегда с
        // начала, а пройденное считать снаружи.
        $remaining = FileEntry::countMissingAltText();
        $total = $remaining + ($dryRun ? 0 : $offset);
        $rows = FileEntry::missingAltText(50, $dryRun ? $offset : 0);

        $result = ['cursor' => $offset, 'total' => $total, 'scanned' => 0, 'planned' => 0, 'fixed' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            $result['scanned']++;

            if ($dryRun) {
                $result['planned']++;
            } else {
                $generated = self::forPath(
                    self::diskPath((string) ($row['stored_name'] ?? '')),
                    (string) ($row['original_name'] ?? '')
                );
                if ($generated['alt'] === '') {
                    // Неудача не должна крутить пакет по одному и тому же
                    // файлу вечно: он остаётся без подписи, но и не считается
                    // обработанным — на следующем запуске попадётся снова.
                    $result['failed']++;
                } else {
                    // Только подпись: общий метод формы перезаписал бы разом
                    // и caption, и автора, и точку фокуса.
                    FileEntry::setAltText((int) $row['id'], $generated['alt']);
                    $result['fixed']++;
                }
            }

            $result['cursor']++;

            // Бюджет проверяется после файла, а не до: иначе пакет вернулся бы,
            // не сдвинув курсор, и обработка стояла бы, показывая движение.
            if (microtime(true) - $started >= $budget) {
                break;
            }
        }

        if ($rows === []) {
            $result['cursor'] = $total;
        }

        return $result;
    }

    private static function diskPath(string $storedName): string
    {
        $base = rtrim((string) Config::get('paths.public_uploads', APP_ROOT . '/public/uploads/public'), '/');
        $storedName = basename($storedName);

        return $storedName === '' ? '' : $base . '/' . $storedName;
    }

    /**
     * Самая лёгкая годная копия снимка в base64.
     *
     * @return array{mime:string, data:string}|null
     */
    private static function readableSource(string $path): ?array
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }

        foreach (self::candidates($path) as $candidate) {
            if (!is_file($candidate) || !is_readable($candidate)) {
                continue;
            }
            $size = (int) filesize($candidate);
            if ($size <= 0 || $size > AiClient::MAX_IMAGE_BYTES) {
                continue;
            }
            $mime = (string) (@mime_content_type($candidate) ?: '');
            if (!str_starts_with($mime, 'image/')) {
                continue;
            }
            $bytes = @file_get_contents($candidate);
            if ($bytes === false || $bytes === '') {
                continue;
            }

            return ['mime' => $mime, 'data' => base64_encode($bytes)];
        }

        return null;
    }

    /**
     * Уменьшенные копии впереди оригинала: 800px модели хватает, а весит он в
     * десятки раз меньше.
     *
     * @return list<string>
     */
    private static function candidates(string $path): array
    {
        $withoutExt = preg_replace('/\.[^.\/]+$/', '', $path) ?? $path;
        $variants = [];
        foreach (Media::VARIANT_WIDTHS as $width) {
            if ($width >= 800) {
                $variants[] = $withoutExt . '-' . $width . '.webp';
            }
        }
        $variants[] = $path;

        return $variants;
    }

    /** Модель иногда всё же начинает с «На фото …» — это шум в каждом alt. */
    private static function tidy(string $alt): string
    {
        $alt = trim(preg_replace('/\s+/u', ' ', $alt) ?? $alt);
        $alt = preg_replace('/^(на\s+(фото|изображении|снимке|картинке)[,:]?\s+|изображение\s+|фотография\s+)/iu', '', $alt) ?? $alt;
        $alt = rtrim(trim($alt), '.');

        return $alt === '' ? '' : mb_strtoupper(mb_substr($alt, 0, 1)) . mb_substr($alt, 1);
    }

    /** @return array{alt:string, provider:string, notice:string} */
    private static function fail(string $notice): array
    {
        return ['alt' => '', 'provider' => '', 'notice' => $notice];
    }
}
