<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\MailQueue;
use App\Models\News;
use App\Models\Setting;
use App\Models\Subscriber;

/**
 * Единая точка формирования дайджеста для cron и ручного запуска из админки.
 * Повторный запуск в ту же ISO-неделю не создаёт дубли писем.
 */
final class DigestDispatcher
{
    /**
     * @return array{news:int,recipients:int,queued:int,duplicates:int,period:string}
     */
    public static function queueWeekly(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $subscribers = Subscriber::active();
        $result = [
            'news' => 0,
            'recipients' => count($subscribers),
            'queued' => 0,
            'duplicates' => 0,
            'period' => Digest::periodKey($now),
        ];
        if ($subscribers === []) {
            return $result;
        }

        $byLanguage = [];
        foreach ($subscribers as $subscriber) {
            $byLanguage[(string) $subscriber['lang']][] = $subscriber;
        }

        $weekAgo = $now->modify('-7 days')->format('Y-m-d H:i:s');
        $baseUrl = AppUrl::base();
        foreach ($byLanguage as $lang => $recipients) {
            $items = array_values(array_filter(
                News::published(50, 0, $lang),
                static fn (array $news): bool => (string) ($news['published_at'] ?? '') >= $weekAgo
            ));
            $result['news'] = max($result['news'], count($items));
            if ($items === []) {
                continue;
            }

            $siteName = Setting::getLocalized('site_name', $lang, 'ASDR');
            $prefix = Locale::prefix($lang);
            $subject = Digest::buildSubject($siteName, $now->format('d.m.Y'), $lang);
            $body = Digest::buildBody($items, $siteName, $baseUrl, $lang, $prefix);

            foreach ($recipients as $subscriber) {
                $subscriberId = (int) $subscriber['id'];
                $queued = MailQueue::enqueueDigest(
                    $subscriberId,
                    (string) $subscriber['email'],
                    $subject,
                    $body . Digest::buildFooter(
                        $baseUrl,
                        (string) $subscriber['token'],
                        $lang,
                        $prefix
                    ),
                    'digest:' . $result['period'] . ':' . $subscriberId
                );
                if ($queued) {
                    Subscriber::markDigestQueued($subscriberId);
                    $result['queued']++;
                } else {
                    $result['duplicates']++;
                }
            }
        }

        return $result;
    }
}
