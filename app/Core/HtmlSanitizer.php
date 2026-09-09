<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Строгий allowlist-санитайзер HTML на нативном DOMDocument (без сторонних
 * библиотек). Разрешён только безопасный форматирующий набор; скрипты,
 * inline-стили, обработчики on*, javascript:/data:-URI и опасные теги
 * вырезаются независимо от роли автора.
 */
final class HtmlSanitizer
{
    /** Разрешённые теги (форматирование, ссылки, изображения, таблицы, списки, HTML5, SVG). */
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr', 'span', 'div', 'section', 'article', 'main', 'header', 'footer', 'aside', 'nav', 'details', 'summary',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'b', 'em', 'i', 'u', 's', 'small', 'sub', 'sup', 'mark',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'a', 'img', 'figure', 'figcaption', 'picture', 'source', 'video', 'audio', 'track',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
        'svg', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon', 'g', 'text', 'tspan', 'use', 'defs', 'clippath', 'mask',
        // Градиенты и переиспользуемые символы: разметка без скриптов и без
        // сетевых обращений. Без них вставленная в блок графика теряла заливку.
        'lineargradient', 'radialgradient', 'stop', 'symbol', 'ellipse',
    ];

    /**
     * SVG регистрозависим, а HTML-разбор приводит имена к нижнему регистру:
     * viewBox превращается в viewbox и перестаёт работать. Восстанавливаем
     * написание на готовой строке — в DOM этого сделать нельзя, setAttribute
     * в HTML-режиме снова опустит регистр.
     */
    private const SVG_CASE = [
        'viewbox' => 'viewBox',
        'preserveaspectratio' => 'preserveAspectRatio',
        'gradientunits' => 'gradientUnits',
        'gradienttransform' => 'gradientTransform',
        'spreadmethod' => 'spreadMethod',
        'clippathunits' => 'clipPathUnits',
        'maskunits' => 'maskUnits',
        'lineargradient' => 'linearGradient',
        'radialgradient' => 'radialGradient',
        'clippath' => 'clipPath',
    ];

    /** Разрешённые атрибуты по тегам. */
    private const ALLOWED_ATTRS = [
        '*' => [
            'class', 'id', 'title', 'role', 'tabindex', 'hidden', 'dir', 'lang',
            'viewbox', 'cx', 'cy', 'r', 'd', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'transform', 'opacity',
            // Геометрия и оформление SVG: значения инертны — ни скриптов,
            // ни загрузок, поэтому список безопасно держать широким.
            'x', 'y', 'x1', 'y1', 'x2', 'y2', 'rx', 'ry', 'points', 'offset', 'preserveaspectratio',
            'fill-rule', 'fill-opacity', 'clip-rule', 'stroke-opacity', 'stroke-dasharray', 'stroke-dashoffset',
            'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform', 'spreadmethod',
            'text-anchor', 'dominant-baseline', 'font-size', 'font-weight', 'letter-spacing',
        ],
        'a' => ['href', 'target', 'rel'],
        // Ссылка на символ в этом же документе (спрайт иконок).
        'use' => ['href', 'width', 'height'],
        'svg' => ['width', 'height', 'xmlns'],
        'symbol' => ['width', 'height'],
        'rect' => ['width', 'height'],
        'image' => ['width', 'height'],
        'img' => ['src', 'alt', 'width', 'height', 'loading'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'video' => ['src', 'poster', 'controls', 'autoplay', 'loop', 'muted', 'playsinline', 'width', 'height'],
        'audio' => ['src', 'controls', 'autoplay', 'loop', 'muted'],
        'source' => ['src', 'type', 'media'],
    ];

    /**
     * Узкий текстовый профиль для кастомных полей конструктора контента:
     * только разметка текста (без div/img/таблиц/style/class).
     */
    private const TEXT_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'a',
    ];

    private const TEXT_ATTRS = [
        '*' => [],
        'a' => ['href', 'target', 'rel'],
    ];

    /** Компактный профиль лида: только разметка, полезная на сайте и в Telegram. */
    private const LEAD_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'ul', 'ol', 'li', 'blockquote', 'a',
    ];

    private const LEAD_ATTRS = [
        '*' => [],
        'a' => ['href', 'target', 'rel'],
    ];

    /**
     * Очистка контента кастомных полей (типы контента, этап 16.4): остаётся
     * только безопасная разметка текста; script/iframe, обработчики on*,
     * javascript:-ссылки и все атрибуты вне allowlist вырезаются.
     */
    public static function sanitizeText(string $html): string
    {
        return self::sanitize($html, self::TEXT_TAGS, self::TEXT_ATTRS);
    }

    /** Безопасная разметка короткого редакционного лида. */
    public static function sanitizeLead(string $html): string
    {
        return self::sanitize($html, self::LEAD_TAGS, self::LEAD_ATTRS);
    }

    /**
     * @param array<int, string>|null $allowedTags
     * @param array<string, array<int, string>>|null $allowedAttrs
     */
    public static function sanitize(string $html, ?array $allowedTags = null, ?array $allowedAttrs = null): string
    {
        $allowedTags ??= self::ALLOWED_TAGS;
        $allowedAttrs ??= self::ALLOWED_ATTRS;
        if (trim($html) === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        // Оборачиваем во wrapper и заставляем разбирать как UTF-8; LIBXML_NONET
        // запрещает сетевые обращения (защита от XXE/SSRF при разборе).
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="__root__">' . $html . '</div>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            // Не удалось разобрать — отдаём только экранированный текст.
            return htmlspecialchars(strip_tags($html), ENT_QUOTES);
        }

        $root = $dom->getElementById('__root__');
        if ($root === null) {
            return '';
        }

        self::cleanNode($root, $allowedTags, $allowedAttrs);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim(self::restoreSvgCase($out));
    }

    /**
     * @param array<int, string> $allowedTags
     * @param array<string, array<int, string>> $allowedAttrs
     */
    private static function cleanNode(\DOMNode $node, array $allowedTags, array $allowedAttrs): void
    {
        // Обходим копию списка детей — узлы будут удаляться на месте.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->nodeName);

                if (!in_array($tag, $allowedTags, true)) {
                    // script/style удаляем ЦЕЛИКОМ — их содержимое это код,
                    // а не контент. Прочие неразрешённые теги (iframe и т.п.)
                    // разворачиваем, сохраняя видимый текст.
                    if (in_array($tag, ['script', 'style'], true)) {
                        $child->parentNode?->removeChild($child);
                    } else {
                        self::cleanNode($child, $allowedTags, $allowedAttrs);
                        self::unwrap($child);
                    }
                    continue;
                }

                self::cleanAttributes($child, $tag, $allowedAttrs);
                self::cleanNode($child, $allowedTags, $allowedAttrs);
            } elseif ($child instanceof \DOMComment) {
                $child->parentNode?->removeChild($child);
            }
            // Текстовые узлы оставляем как есть — saveHTML их экранирует.
        }
    }

    /**
     * @param array<string, array<int, string>> $allowedAttrs
     */
    /**
     * Возвращает SVG-именам их настоящий регистр. Работает по готовой строке:
     * значения атрибутов не трогаем, поэтому текст с «viewbox» внутри абзаца
     * не пострадает — заменяются только имена тегов и атрибутов.
     */
    private static function restoreSvgCase(string $html): string
    {
        if (!str_contains($html, '<svg')) {
            return $html;
        }

        foreach (self::SVG_CASE as $lower => $proper) {
            // Имя атрибута: перед ним пробел, после — знак равенства.
            $html = (string) preg_replace('~(<[^>]*\s)' . $lower . '(=)~i', '$1' . $proper . '$2', $html);
            // Имя тега: сразу после < или </.
            $html = (string) preg_replace('~(</?)' . $lower . '(?=[\s/>])~i', '$1' . $proper, $html);
        }

        return $html;
    }

    private static function cleanAttributes(\DOMElement $el, string $tag, array $allowedAttrs): void
    {
        $allowed = array_merge(
            $allowedAttrs['*'] ?? [],
            $allowedAttrs[$tag] ?? []
        );

        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = $attr->nodeValue ?? '';

            $isDataOrAria = str_starts_with($name, 'data-') || str_starts_with($name, 'aria-');
            if ((!$isDataOrAria && !in_array($name, $allowed, true)) || str_starts_with($name, 'on')) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }

            // URL-атрибуты проверяются центральным allowlist-гейтом. href
            // допускает http(s), mailto/tel и локальные пути; src — только
            // http(s) и локальные пути. data:, file:, protocol-relative URL,
            // управляющие символы и URL с userinfo удаляются.
            // <use> ссылается только на символ внутри этой же страницы:
            // внешний документ браузеры и так не подгрузят, а разрешать адрес
            // значило бы завести ещё одну точку выхода наружу.
            if ($tag === 'use' && $name === 'href') {
                if (!str_starts_with($value, '#')) {
                    $el->removeAttribute($attr->nodeName);
                }
                continue;
            }
            if ($name === 'href' && !UrlGuard::isSafeLink($value)) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }
            if ($name === 'src' && !UrlGuard::isSafeMedia($value)) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }
        }

        // Внешние ссылки делаем безопасными.
        if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /** Заменяет элемент его дочерними узлами (сохраняя текстовое содержимое). */
    private static function unwrap(\DOMElement $el): void
    {
        $parent = $el->parentNode;
        if ($parent === null) {
            return;
        }
        while ($el->firstChild !== null) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }
}
