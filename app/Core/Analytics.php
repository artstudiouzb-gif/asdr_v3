<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Программная сборка счётчиков аналитики из ID (задача 116). Скрипты строятся
 * из проверенных идентификаторов (GA: G-XXXX, Метрика: цифры), а не из сырого
 * пользовательского JS — исключает XSS. Загрузка блокируется до согласия на
 * cookie (JS-обёртка в consent.js), если баннер включён.
 */
final class Analytics
{
    public static function gaId(): string
    {
        return SettingsValidator::gaId(Setting::get('analytics_ga_id', ''));
    }

    public static function ymId(): string
    {
        return SettingsValidator::ymId(Setting::get('analytics_ym_id', ''));
    }

    /** Есть ли что подгружать. */
    public static function hasAny(): bool
    {
        return self::gaId() !== '' || self::ymId() !== '';
    }

    /**
     * JS-код инициализации счётчиков (без внешних тегов — их подставит
     * consent.js после согласия). Возвращает безопасный JS из валидных ID.
     */
    public static function initScript(): string
    {
        return self::buildScript(self::gaId(), self::ymId());
    }

    /** Чистая сборка JS из проверенных ID (для тестов без БД). */
    public static function buildScript(string $ga, string $ym): string
    {
        $ga = SettingsValidator::gaId($ga);
        $ym = SettingsValidator::ymId($ym);
        $parts = [];

        if ($ga !== '') {
            $g = json_encode($ga);
            $parts[] = <<<JS
(function(){var s=document.createElement('script');s.async=true;s.src='https://www.googletagmanager.com/gtag/js?id='+{$g};document.head.appendChild(s);window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}window.gtag=gtag;gtag('js',new Date());gtag('config',{$g});})();
JS;
        }
        if ($ym !== '') {
            $y = (int) $ym;
            $parts[] = <<<JS
(function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};m[i].l=1*new Date();k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a)})(window,document,'script','https://mc.yandex.ru/metrika/tag.js','ym');ym({$y},'init',{clickmap:true,trackLinks:true,accurateTrackBounce:true});
JS;
        }

        return implode("\n", $parts);
    }
}
