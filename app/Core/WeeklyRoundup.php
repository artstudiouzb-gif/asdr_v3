<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Language;
use App\Models\News;
use App\Models\Setting;

/**
 * Итоги недели одним постом в канал: список новостей за семь дней на всех
 * языках, где они вышли.
 *
 * Зачем: подписчик, пропустивший будни, получает сводку и не листает ленту
 * назад. Формат для госканала привычный — короткий список со ссылками, без
 * пересказа; сверху обложка, если редактор её задал.
 *
 * Пустую неделю не отправляем: пост «новостей не было» приучает пролистывать
 * сообщения канала, а вместе с ними и настоящие.
 */
final class WeeklyRoundup
{
    /** Больше этого числа новостей в один пост не собираем. */
    private const MAX_ITEMS = 15;

    /** Не отправлять повторно чаще, чем раз в эти дни (защита от двойного cron). */
    private const MIN_INTERVAL_DAYS = 3;

    public const ENABLED_KEY = 'social_telegram_roundup';
    public const LAST_SENT_KEY = 'social_telegram_roundup_sent_at';
    public const COVER_KEY = 'social_telegram_roundup_image';

    public static function isEnabled(): bool
    {
        return (string) Setting::get(self::ENABLED_KEY, '0') === '1';
    }

    /**
     * Новости за период по языкам: код языка → список записей. Язык попадает
     * в сводку, только если на нём реально что-то вышло.
     *
     * @return array<string, list<array<string,mixed>>>
     */
    public static function collect(int $days = 7, ?\DateTimeImmutable $now = null): array
    {
        if (!Database::isConnected()) {
            return [];
        }
        $now ??= new \DateTimeImmutable();
        $since = $now->modify('-' . max(1, $days) . ' days')->format('Y-m-d H:i:s');
        $until = $now->format('Y-m-d H:i:s');

        $out = [];
        foreach (Language::activeCodes() as $code) {
            $items = array_values(array_filter(
                News::published(self::MAX_ITEMS * 2, 0, $code),
                static function (array $news) use ($since, $until): bool {
                    $at = (string) ($news['published_at'] ?? '');

                    return $at >= $since && $at <= $until;
                }
            ));
            if ($items !== []) {
                $out[$code] = array_slice($items, 0, self::MAX_ITEMS);
            }
        }

        return $out;
    }

    /**
     * Обложка сводки: коллаж или заставка, которую редактор задаёт в
     * настройках. Пусто — берём общую картинку для соцсетей; нет и её —
     * пост уходит без изображения, это нормально.
     */
    public static function coverUrl(): string
    {
        foreach ([(string) Setting::get(self::COVER_KEY, ''), (string) Setting::get('default_og_image', '')] as $raw) {
            $url = trim($raw);
            if ($url === '') {
                continue;
            }
            if (!preg_match('#^https?://#', $url)) {
                $url = AppUrl::base() . '/' . ltrim($url, '/');
            }
            // Telegram забирает картинку сам и умеет только https.
            if (str_starts_with($url, 'https://')) {
                return $url;
            }
        }

        return '';
    }

    /**
     * Пост для канала. Заголовок с датами периода, дальше по разделу на язык:
     * название-ссылка и рубрика. Формат — обычный HTML Telegram; обложка,
     * если задана, уходит отдельным полем, а не тегом в тексте.
     *
     * @param array<string, list<array<string,mixed>>> $byLang
     */
    public static function buildHtml(array $byLang, ?\DateTimeImmutable $now = null): string
    {
        if ($byLang === []) {
            return '';
        }
        $now ??= new \DateTimeImmutable();
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $base = AppUrl::base();
        $default = Language::defaultCode();

        $titles = [
            'ru' => 'Итоги недели',
            'uz' => 'Hafta yakunlari',
            'en' => 'Week in review',
        ];
        $from = $now->modify('-7 days');

        $blocks = [];
        foreach ($byLang as $code => $items) {
            $heading = $titles[$code] ?? ($titles['ru']);
            $period = DateFormatter::short($from->format('Y-m-d')) . ' — ' . DateFormatter::short($now->format('Y-m-d'));
            $lines = ['<b>' . $esc($heading) . '</b> · ' . $esc($period), ''];

            foreach ($items as $item) {
                $title = trim((string) ($item['title'] ?? ''));
                $slug = trim((string) ($item['slug'] ?? ''));
                if ($title === '' || $slug === '') {
                    continue;
                }
                $url = $base . ($code === $default ? '' : '/' . $code) . '/news/' . rawurlencode($slug);
                $badge = trim((string) ($item['badge'] ?? ''));
                $lines[] = '• <a href="' . $esc($url) . '">' . $esc($title) . '</a>'
                    . ($badge !== '' ? ' — <i>' . $esc($badge) . '</i>' : '');
            }
            if (count($lines) > 2) {
                $blocks[] = implode("\n", $lines);
            }
        }

        return implode("\n\n———\n\n", $blocks);
    }

    /**
     * Собирает и отправляет сводку в канал.
     *
     * @return array{sent:bool, reason:string, items:int}
     */
    public static function send(?\DateTimeImmutable $now = null, ?SocialPublisher $publisher = null, bool $force = false): array
    {
        $now ??= new \DateTimeImmutable();
        // Кнопка в админке — осознанное действие человека: она отправляет и
        // повторно. Автоматический запуск остаётся защищённым от повторов.
        if (!$force && !self::isEnabled()) {
            return ['sent' => false, 'reason' => 'Сводка выключена в настройках канала.', 'items' => 0];
        }
        if (!SocialSettings::isReady('telegram')) {
            return ['sent' => false, 'reason' => 'Канал Telegram не настроен.', 'items' => 0];
        }
        if (!$force && self::sentRecently($now)) {
            return ['sent' => false, 'reason' => 'Сводка уже отправлена на этой неделе.', 'items' => 0];
        }

        $byLang = self::collect(7, $now);
        $count = array_sum(array_map('count', $byLang));
        if ($count === 0) {
            // Пустая неделя — молчим. Пост «новостей не было» приучает
            // пролистывать сообщения канала.
            return ['sent' => false, 'reason' => 'За неделю новостей не было.', 'items' => 0];
        }

        $html = self::buildHtml($byLang, $now);
        $res = ($publisher ?? new SocialPublisher())
            ->sendChannelMessage(SocialSettings::configFor('telegram'), $html, self::coverUrl());

        if (!empty($res['ok'])) {
            Setting::set(self::LAST_SENT_KEY, $now->format('Y-m-d H:i:s'));

            return ['sent' => true, 'reason' => '', 'items' => $count];
        }

        return ['sent' => false, 'reason' => (string) ($res['error'] ?? 'Telegram отказал.'), 'items' => $count];
    }

    private static function sentRecently(\DateTimeImmutable $now): bool
    {
        $last = trim((string) Setting::get(self::LAST_SENT_KEY, ''));
        if ($last === '') {
            return false;
        }
        $ts = strtotime($last);

        return $ts !== false && ($now->getTimestamp() - $ts) < self::MIN_INTERVAL_DAYS * 86400;
    }
}
