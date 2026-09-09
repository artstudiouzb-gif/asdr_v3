<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Восстанавливает абзацы в старом контенте, который исходная CMS хранила с
 * пустыми строками и форматировала при выводе. Новые редакторские материалы,
 * уже содержащие <p>, не меняются.
 */
final class LegacyContentFormatter
{
    /** Блочные элементы нельзя заворачивать внутрь <p>. */
    private const BLOCK_START = '/^\s*<(?:address|article|aside|blockquote|div|dl|fieldset|figure|footer|form|h[1-6]|header|hr|main|nav|ol|pre|section|table|ul)\b/i';

    public static function paragraphize(string $html): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        $trimmed = trim($html);

        // Обычный контент редактора уже семантически размечен. Этот formatter
        // нужен только legacy-тексту, где абзацы обозначены пустой строкой.
        if ($trimmed === '' || stripos($trimmed, '<p') !== false || !preg_match('/\n[ \t]*\n/', $trimmed)) {
            return $html;
        }

        $chunks = preg_split('/\n[ \t]*\n+/', $trimmed) ?: [$trimmed];
        $out = [];

        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            // Самостоятельные блочные элементы оставляем нетронутыми: таблицы,
            // списки, заголовки, figure и т.п. не должны оказаться внутри <p>.
            if (preg_match(self::BLOCK_START, $chunk)) {
                $out[] = $chunk;
                continue;
            }

            // Одинарный перенос внутри legacy-абзаца старый сайт показывал как
            // визуальный перенос. Сохраняем его, но не создаём лишние абзацы.
            $chunk = (string) preg_replace('/\n+/', "<br>\n", $chunk);
            $out[] = '<p>' . $chunk . '</p>';
        }

        return implode("\n\n", $out);
    }
}
