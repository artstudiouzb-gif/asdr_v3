<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Форматированный лид новости и его текстовая версия для карточек, поиска,
 * SEO и соцсетей без HTML.
 */
final class NewsLead
{
    public const RECOMMENDED_MIN = 180;
    public const RECOMMENDED_MAX = 360;

    /** @return array{html:?string,text:?string} */
    public static function fromInput(?string $html, ?string $legacyText, string $locale = 'ru'): array
    {
        $html = trim((string) $html);
        $legacyText = trim((string) $legacyText);

        if ($html === '' && $legacyText !== '') {
            $html = self::paragraphsFromText($legacyText);
        }
        if ($html === '') {
            return ['html' => null, 'text' => null];
        }

        $safe = HtmlSanitizer::sanitizeLead(TextProcessor::process($html, $locale));
        $text = self::plain($safe);

        return [
            'html' => $safe !== '' && $text !== '' ? $safe : null,
            'text' => $text !== '' ? $text : null,
        ];
    }

    /** Безопасный HTML для сайта и Telegram с поддержкой старых записей. */
    public static function html(?string $html, ?string $legacyText = null): string
    {
        $safe = HtmlSanitizer::sanitizeLead(trim((string) $html));
        if ($safe !== '' && self::plain($safe) !== '') {
            return $safe;
        }

        return self::paragraphsFromText(trim((string) $legacyText));
    }

    /** Значение для визуального редактора; старый обычный лид не теряется. */
    public static function editorValue(?string $html, ?string $legacyText = null): string
    {
        return self::html($html, $legacyText);
    }

    /** Чистый текст без тегов и служебных пробелов. */
    public static function plain(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $withSpaces = preg_replace('#<(?:br\s*/?|/p|/li|/blockquote|/ul|/ol)>#iu', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($withSpaces), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function paragraphsFromText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $paragraphs = preg_split('/\R{2,}/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $html = '';
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph !== '') {
                $html .= '<p>' . nl2br(htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
            }
        }

        return $html;
    }
}
