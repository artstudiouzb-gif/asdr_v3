<?php

declare(strict_types=1);

use App\Core\Lang;

if (!function_exists('t')) {
    /**
     * Короткий помощник перевода интерфейса для шаблонов.
     * Возвращает перевод строки на текущий язык (или сам ключ на языке
     * по умолчанию / при отсутствии перевода). См. App\Core\Lang.
     */
    function t(string $key, ?string $lang = null): string
    {
        return Lang::t($key, $lang);
    }
}

if (!function_exists('excerpt')) {
    /**
     * Анонс для карточки: снимает разметку и обрезает до $limit символов.
     * Режет по границе слова и ставит многоточие ТОЛЬКО если текст правда
     * обрезан, — иначе карточки обещают продолжение там, где его нет.
     */
    function excerpt(?string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)) ?? '');
        if ($text === '' || mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        // Не обрываем слово посередине, если до пробела недалеко.
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space >= (int) ($limit * 0.6)) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t\n\r\0\x0B.,;:—–-") . '…';
    }
}
