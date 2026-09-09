<?php

declare(strict_types=1);

namespace App\Core;

/** Генерирует адаптивные srcset-строки и picture-разметку для медиа. */
final class MediaOptimizer
{
    /**
     * Возвращает атрибут srcset для браузера на основе имеющихся WebP-вариантов.
     */
    public static function srcset(string $imageUrl): string
    {
        $imageUrl = trim($imageUrl);
        if ($imageUrl === '' || str_starts_with($imageUrl, 'data:') || str_starts_with($imageUrl, 'http://') || str_starts_with($imageUrl, 'https://')) {
            return '';
        }

        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
        $publicDir = $docRoot !== '' ? $docRoot : dirname(__DIR__, 2) . '/public';
        $relative = '/' . ltrim($imageUrl, '/');
        $localPath = $publicDir . $relative;

        $base = preg_replace('/\.[^.]+$/', '', $relative) ?? $relative;
        $basePath = preg_replace('/\.[^.]+$/', '', $localPath) ?? $localPath;

        $srcset = [];
        if (is_file($basePath . '-400.webp')) {
            $srcset[] = $base . '-400.webp 400w';
        }
        if (is_file($basePath . '-800.webp')) {
            $srcset[] = $base . '-800.webp 800w';
        }
        if (is_file($basePath . '-1200.webp')) {
            $srcset[] = $base . '-1200.webp 1200w';
        }
        if (is_file($basePath . '-1600.webp')) {
            $srcset[] = $base . '-1600.webp 1600w';
        }
        if (is_file($basePath . '.webp')) {
            $srcset[] = $base . '.webp 1920w';
        }

        return implode(', ', $srcset);
    }

    /**
     * Формирует готовую теговую разметку <img> с атрибутами loading="lazy" и srcset.
     */
    public static function renderImg(string $url, string $alt = '', string $class = '', string $sizes = '(max-width: 768px) 100vw, 1200px'): string
    {
        $src = htmlspecialchars($url, ENT_QUOTES);
        $altText = htmlspecialchars($alt, ENT_QUOTES);
        $cls = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"' : '';
        $srcsetAttribute = self::srcset($url);
        $srcsetHtml = $srcsetAttribute !== '' ? ' srcset="' . htmlspecialchars($srcsetAttribute, ENT_QUOTES) . '" sizes="' . htmlspecialchars($sizes, ENT_QUOTES) . '"' : '';

        return '<img src="' . $src . '" alt="' . $altText . '"' . $cls . $srcsetHtml . ' loading="lazy" decoding="async">';
    }
}
