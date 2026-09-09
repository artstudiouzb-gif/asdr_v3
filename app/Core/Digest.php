<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Сборка HTML email-дайджеста новостей (чистые функции; отправку выполняет
 * app/Console/digest_worker.php через очередь писем).
 */
final class Digest
{
    /**
     * Тема письма: «Дайджест новостей — Сайт (12.07.2026)».
     */
    public static function buildSubject(string $siteName, string $date, string $lang = 'ru'): string
    {
        $siteName = trim($siteName) !== '' ? trim($siteName) : 'Сайт';

        $label = match ($lang) {
            'uz' => 'Yangiliklar dayjesti',
            'en' => 'News digest',
            default => 'Дайджест новостей',
        };

        return $label . ' — ' . $siteName . ' (' . $date . ')';
    }

    /**
     * HTML-тело письма: список новостей со ссылками.
     *
     * @param array<int, array<string, mixed>> $items новости (title, slug, excerpt)
     */
    public static function buildBody(
        array $items,
        string $siteName,
        string $baseUrl,
        string $lang = 'ru',
        string $urlPrefix = ''
    ): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $urlPrefix = '/' . trim($urlPrefix, '/');
        if ($urlPrefix === '/') {
            $urlPrefix = '';
        }
        $heading = match ($lang) {
            'uz' => 'Haftalik yangiliklar',
            'en' => 'News from the past week',
            default => 'Новости за неделю',
        };
        $siteSuffix = trim($siteName) !== ''
            ? ' — ' . htmlspecialchars(trim($siteName), ENT_QUOTES)
            : '';
        $html = '<div style="font-family:Arial,sans-serif;max-width:680px;margin:0 auto;color:#172033;line-height:1.55">'
            . '<h1 style="font-size:24px;margin:0 0 24px">' . $heading . $siteSuffix . '</h1>';

        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $excerpt = trim(strip_tags((string) ($item['excerpt'] ?? '')));
            if ($excerpt !== '') {
                if (mb_strlen($excerpt) > 200) {
                    $excerpt = mb_substr($excerpt, 0, 200) . '…';
                }
            }
            $url = $baseUrl . $urlPrefix . '/news/' . rawurlencode(trim((string) ($item['slug'] ?? '')));
            $html .= '<article style="padding:0 0 20px;margin:0 0 20px;border-bottom:1px solid #e5e7eb">'
                . '<h2 style="font-size:18px;line-height:1.35;margin:0 0 8px">'
                . '<a style="color:#0f5f7a;text-decoration:none" href="' . htmlspecialchars($url, ENT_QUOTES) . '">'
                . htmlspecialchars($title, ENT_QUOTES) . '</a></h2>';
            if ($excerpt !== '') {
                $html .= '<p style="margin:0;color:#526071">' . htmlspecialchars($excerpt, ENT_QUOTES) . '</p>';
            }
            $html .= '</article>';
        }

        return $html . '</div>';
    }

    /** Подвал с персональной ссылкой отписки. */
    public static function buildFooter(
        string $baseUrl,
        string $token,
        string $lang = 'ru',
        string $urlPrefix = ''
    ): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $urlPrefix = '/' . trim($urlPrefix, '/');
        if ($urlPrefix === '/') {
            $urlPrefix = '';
        }
        [$reason, $unsubscribe] = match ($lang) {
            'uz' => [
                'Siz yangiliklar dayjestiga obuna bo‘lganingiz uchun ushbu xatni oldingiz.',
                'Obunani bekor qilish',
            ],
            'en' => [
                'You received this email because you subscribed to the news digest.',
                'Unsubscribe',
            ],
            default => [
                'Вы получили это письмо, потому что подписались на дайджест.',
                'Отписаться',
            ],
        };
        $url = $baseUrl . $urlPrefix . '/unsubscribe?token=' . rawurlencode($token);

        return '<div style="font-family:Arial,sans-serif;max-width:680px;margin:24px auto 0;padding-top:18px;'
            . 'border-top:1px solid #e5e7eb;color:#6b7280;font-size:13px">'
            . '<p style="margin:0 0 8px">' . htmlspecialchars($reason, ENT_QUOTES) . '</p>'
            . '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="color:#0f5f7a">'
            . htmlspecialchars($unsubscribe, ENT_QUOTES) . '</a></div>';
    }

    /** Стабильный ключ недели для защиты повторного запуска cron. */
    public static function periodKey(\DateTimeInterface $date): string
    {
        return $date->format('o-\WW');
    }
}
