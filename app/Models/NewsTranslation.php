<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class NewsTranslation
{
    /**
     * @return array<string, array<string, mixed>> переводы по коду языка
     */
    public static function forNews(int $newsId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM news_translations WHERE news_id = :id');
        $stmt->execute([':id' => $newsId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['lang']] = $row;
        }

        return $result;
    }

    public static function find(int $newsId, string $lang): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM news_translations WHERE news_id = :id AND lang = :lang LIMIT 1'
        );
        $stmt->execute([':id' => $newsId, ':lang' => $lang]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Пакетная загрузка одного перевода для списка новостей — устраняет N+1.
     * @param list<int> $newsIds
     * @return array<int, array<string, mixed>>
     */
    public static function forNewsIds(array $newsIds, string $lang): array
    {
        $newsIds = array_values(array_unique(array_filter(array_map('intval', $newsIds), static fn (int $id): bool => $id > 0)));
        if ($newsIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($newsIds), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM news_translations WHERE news_id IN ({$placeholders}) AND lang = ?"
        );
        $stmt->execute([...$newsIds, $lang]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['news_id']] = $row;
        }
        return $result;
    }

    public static function upsert(int $newsId, string $lang, array $data): void
    {
        $timelineJson = null;
        if (isset($data['timeline_json'])) {
            $timelineJson = is_array($data['timeline_json']) ? json_encode($data['timeline_json'], JSON_UNESCAPED_UNICODE) : $data['timeline_json'];
        }

        $docsJson = null;
        if (isset($data['docs'])) {
            $docsJson = is_array($data['docs']) ? json_encode($data['docs'], JSON_UNESCAPED_UNICODE) : $data['docs'];
        }

        $pollOptionsJson = null;
        if (isset($data['poll_options_json'])) {
            $pollOptionsJson = is_array($data['poll_options_json']) ? json_encode($data['poll_options_json'], JSON_UNESCAPED_UNICODE) : $data['poll_options_json'];
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO news_translations (news_id, lang, title, badge, excerpt, lead_html, content, hashtags, key_points, event_meta, timeline_json, docs, poll_question, poll_options_json, meta_title, meta_description,
                card_title, card_badge, card_stats, card_signature, card_note)
             VALUES (:news_id, :lang, :title, :badge, :excerpt, :lead_html, :content, :hashtags, :key_points, :event_meta, :timeline_json, :docs, :poll_question, :poll_options_json, :meta_title, :meta_description,
                :card_title, :card_badge, :card_stats, :card_signature, :card_note)
             ON DUPLICATE KEY UPDATE title = VALUES(title), badge = VALUES(badge), excerpt = VALUES(excerpt), lead_html = VALUES(lead_html),
                content = VALUES(content), hashtags = VALUES(hashtags), key_points = VALUES(key_points), event_meta = VALUES(event_meta),
                timeline_json = VALUES(timeline_json), docs = VALUES(docs), poll_question = VALUES(poll_question), poll_options_json = VALUES(poll_options_json),
                meta_title = VALUES(meta_title), meta_description = VALUES(meta_description),
                card_title = VALUES(card_title), card_badge = VALUES(card_badge), card_stats = VALUES(card_stats),
                card_signature = VALUES(card_signature), card_note = VALUES(card_note)'
        );
        $stmt->execute([
            ':news_id' => $newsId,
            ':lang' => $lang,
            ':title' => $data['title'] ?? null,
            ':badge' => $data['badge'] ?? null,
            ':excerpt' => $data['excerpt'] ?? null,
            ':lead_html' => $data['lead_html'] ?? null,
            ':content' => $data['content'] ?? null,
            ':hashtags' => \App\Models\News::cleanHashtags($data['hashtags'] ?? null),
            ':key_points' => $data['key_points'] ?? null,
            ':event_meta' => $data['event_meta'] ?? null,
            ':timeline_json' => $timelineJson,
            ':docs' => $docsJson,
            ':poll_question' => $data['poll_question'] ?? null,
            ':poll_options_json' => $pollOptionsJson,
            ':meta_title' => $data['meta_title'] ?? null,
            ':meta_description' => $data['meta_description'] ?? null,
            ':card_title' => ($data['card_title'] ?? '') !== '' ? $data['card_title'] : null,
            ':card_badge' => ($data['card_badge'] ?? '') !== '' ? $data['card_badge'] : null,
            ':card_stats' => ($data['card_stats'] ?? '') !== '' ? $data['card_stats'] : null,
            ':card_signature' => ($data['card_signature'] ?? '') !== '' ? $data['card_signature'] : null,
            ':card_note' => ($data['card_note'] ?? '') !== '' ? $data['card_note'] : null,
        ]);
        \App\Core\Cache::forgetPrefix('page:');
    }
}
