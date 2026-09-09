<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Language;

/** Показывает понятное предложение, когда у материала нет перевода. */
final class ContentLanguageNotice
{
    /**
     * @param string[] $availableCodes языки, на которых материал реально заполнен
     */
    public static function renderIfMissing(array $availableCodes, string $path): bool
    {
        $current = Locale::current();
        $active = Language::active();
        $activeCodes = array_map(static fn (array $lang): string => (string) $lang['code'], $active);
        $availableCodes = array_values(array_unique(array_intersect($availableCodes, $activeCodes)));

        if (in_array($current, $availableCodes, true)) {
            Locale::setContentLangs($availableCodes);
            return false;
        }

        // Для hreflang оставляем только реальные переводы. Переключатель
        // независимо от этого показывает все активные языки.
        Locale::setContentLangs($availableCodes);

        $alternatives = [];
        foreach ($active as $language) {
            $code = (string) $language['code'];
            if (!in_array($code, $availableCodes, true)) {
                continue;
            }
            $alternatives[] = [
                'code' => $code,
                'name' => (string) $language['name'],
                // Это осознанный выбор посетителя, поэтому роутер сменит язык
                // только после нажатия этой кнопки, а не автоматически.
                'url' => Locale::url($path, $code) . '?' . LocalePreference::QUERY . '=' . rawurlencode($code),
            ];
        }

        http_response_code(404);
        View::render('site/translation_unavailable', ['alternatives' => $alternatives]);

        return true;
    }
}
