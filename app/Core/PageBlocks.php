<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Block;
use App\Models\Setting;

/**
 * Скомпилированное содержимое записи-конструктора: HTML блоков, их scoped CSS
 * и зарегистрированные ассеты.
 *
 * Вынесено из PageController, потому что блоки теперь есть не только у
 * страницы: проект — та же запись pages с подтипом, и его детальная страница
 * обязана собираться тем же способом, вместе с кэшем, свежим CSRF-токеном и
 * одноразовым nonce. Второй копии этой логики быть не должно: разойдясь, они
 * дадут разное поведение кэша на двух половинах сайта.
 */
final class PageBlocks
{
    /**
     * @return array{html: string, css: string, preload_images: array<int, string>, assets: array<int, string>}
     */
    public static function compile(int $pageId, string $lang): array
    {
        $build = static function () use ($pageId, $lang): array {
            return BlockRenderer::renderPage(Block::forPageLocalized($pageId, $lang));
        };

        // Запрошена не первая страница блока с постраничным выводом или его
        // вторая вкладка. Кэш страницы общий и не знает про параметры адреса:
        // сохранив под тем же ключом вторую страницу галереи, мы отдали бы её
        // всем посетителям главной. С вкладкой то же самое — на «Фото»
        // открыта другая вкладка и другая полоса страниц уже на первой.
        // Такие запросы редки (их делает читатель, листающий блок), поэтому
        // просто собираем страницу заново, а не плодим ключи.
        if (BlockPager::current() > 1 || BlockPager::tabRequested()) {
            $rendered = $build();
        } elseif (Setting::get('perf_page_cache', '1') === '1') {
            $ttl = max(0, (int) Setting::get('perf_cache_ttl', '0'));
            $cacheKey = 'page:' . $pageId . ':' . $lang;
            $rendered = Cache::remember($cacheKey, $build, $ttl);

            // У блоков с расписанием кэш живёт только до ближайшей границы
            // показа: иначе баннер «до 30 июля» остался бы висеть 31-го.
            $expiresAt = is_array($rendered) ? ($rendered['expires_at'] ?? null) : null;
            if ($expiresAt !== null && (int) $expiresAt <= time()) {
                Cache::forget($cacheKey);
                $rendered = Cache::remember($cacheKey, $build, $ttl);
            }
        } else {
            $rendered = $build();
        }

        // Кэш страниц общий для всех посетителей, а CSRF-токен и метка времени
        // honeypot — сессионные: подставляем живые значения при каждой отдаче,
        // иначе формы у второго посетителя падают с 419.
        if (!empty($rendered['html']) && str_contains((string) $rendered['html'], 'name="csrf_token"')) {
            $rendered['html'] = preg_replace(
                '/(name="csrf_token" value=")[^"]*(")/',
                '${1}' . htmlspecialchars(Csrf::token(), ENT_QUOTES) . '${2}',
                (string) $rendered['html']
            );
            $rendered['html'] = preg_replace(
                '/(name="hp_ts" value=")[^"]*(")/',
                '${1}' . time() . '${2}',
                (string) $rendered['html']
            );
        }

        // Совместимость с другими доверенными HTML-фрагментами: если они
        // содержат script, nonce должен быть одноразовым для текущего запроса.
        $rendered['html'] = SecurityHeaders::injectScriptNonce((string) $rendered['html']);

        // Ассеты блоков регистрируются и на попадании в кэш, и при промахе.
        foreach ($rendered['assets'] ?? [] as $assetType) {
            AssetCollector::requireJs((string) $assetType);
        }

        return [
            'html' => (string) $rendered['html'],
            'css' => (string) ($rendered['css'] ?? ''),
            'preload_images' => (array) ($rendered['preload_images'] ?? []),
            'assets' => (array) ($rendered['assets'] ?? []),
        ];
    }
}
