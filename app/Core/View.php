<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $template, array $data = []): void
    {
        $file = __DIR__ . '/../Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }

        // Извлечение переменных изолировано в статическом замыкании: его
        // локали (__file/__vars) не совпадают с именами переменных вьюхи,
        // поэтому extract() корректно создаёт даже переменную $data. Если бы
        // extract вызывался прямо здесь, ключ 'data' конфликтовал бы с
        // параметром $data и (при EXTR_SKIP) не попадал во вьюху — из-за этого
        // редакторы блоков не показывали сохранённое содержимое.
        // Имя шаблона доступно вьюхе как $viewTemplate: по нему подвал
        // определяет крупный тип страницы для Core Web Vitals, не собирая
        // адрес. EXTR_SKIP не даст перебить одноимённый ключ из $data.
        $data['viewTemplate'] = $data['viewTemplate'] ?? $template;

        $renderTo = static function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP);
            require $__file;
        };

        // Буфер гарантирует, что лениво запущенная в шаблоне сессия ещё может
        // безопасно выставить cookie. Заодно здесь же применяем CDN rewrite.
        ob_start();
        try {
            $renderTo($file, $data);
            $html = (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        if (str_starts_with($template, 'site/') && Asset::cdnBase() !== '') {
            $html = Asset::rewriteMedia($html);
        }
        // Геометрия иконок встраивается в готовую страницу: так браузер не
        // тянет двухмегабайтный спрайт Tabler ради десятка символов.
        $html = Icon::injectSprite($html);
        // Узбекская кириллица — выбор посетителя в настройках отображения.
        // Перевод делается здесь, на готовой странице: в базе остаётся один
        // вариант текста, а кэш блоков не нужно множить на письменность.
        if (self::isPublic($template) && self::wantsCyrillic()) {
            $html = UzCyrillic::html($html);
        }
        // «Исходный код страницы» — публичное лицо сайта не меньше, чем сама
        // страница: разметку читают ведомственные проверки, интеграторы и
        // разработчики. Форматирование двигает только незначимые пробелы,
        // поэтому применяется и к странице из кэша (см. HtmlFormatter).
        if (self::isPublic($template) && HtmlFormatter::enabled()) {
            $html = HtmlFormatter::format($html);
        }
        PublicResponseCache::apply($template);
        // Аварийный снимок: пока база жива, каждая публичная страница
        // оставляет свою копию на диске. Когда база ляжет, bootstrap отдаст
        // её вместо 503 — см. PublicResponseCache::tryServeStale().
        PublicResponseCache::saveSnapshot($html);
        if (PublicResponseCache::sendConditional($html)) {
            return;
        }
        echo $html;
    }

    /** Публичные страницы: сайт и портал репозитория (в админке — только русский). */
    private static function isPublic(string $template): bool
    {
        return str_starts_with($template, 'site/')
            || str_starts_with($template, 'repo/')
            || str_starts_with($template, 'errors/');
    }

    private static function wantsCyrillic(): bool
    {
        if (Locale::current() !== 'uz') {
            return false;
        }

        $settings = A11ySettings::fromCookie($_COOKIE[A11ySettings::COOKIE] ?? null);

        return $settings['script'] === 'cyrl';
    }

    public static function renderPartial(string $template, array $data = []): string
    {
        $file = __DIR__ . '/../Views/' . $template . '.php';
        if (!is_file($file)) {
            return '';
        }

        return (static function (string $__file, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            require $__file;

            return (string) ob_get_clean();
        })($file, $data);
    }
}
