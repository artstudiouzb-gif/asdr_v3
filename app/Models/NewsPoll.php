<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class NewsPoll
{
    /**
     * Возвращает опрос для новости или null.
     */
    public static function findByNews(int $newsId): ?array
    {
        if (!Database::isConnected()) {
            return null;
        }

        $stmt = Database::pdo()->prepare("SELECT * FROM news_polls WHERE news_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$newsId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $options = json_decode((string) $row["options_json"], true);
        $row["options"] = is_array($options) ? $options : [];
        return $row;
    }

    /**
     * Создаёт или обновляет опрос для новости.
     */
    public static function saveForNews(int $newsId, ?string $question, array $options): void
    {
        if (!Database::isConnected()) {
            return;
        }

        $question = trim((string) $question);
        $cleanOptions = array_values(array_filter(array_map("trim", $options), static fn ($v) => $v !== ""));

        if ($question === "" || count($cleanOptions) < 2) {
            // Удаляем опрос, если поля очищены
            $stmt = Database::pdo()->prepare("DELETE FROM news_polls WHERE news_id = ?");
            $stmt->execute([$newsId]);
            return;
        }

        $existing = self::findByNews($newsId);
        $optionsJson = json_encode($cleanOptions, JSON_UNESCAPED_UNICODE);

        if ($existing) {
            $stmt = Database::pdo()->prepare("UPDATE news_polls SET question = ?, options_json = ? WHERE id = ?");
            $stmt->execute([$question, $optionsJson, $existing["id"]]);
        } else {
            $stmt = Database::pdo()->prepare("INSERT INTO news_polls (news_id, question, options_json) VALUES (?, ?, ?)");
            $stmt->execute([$newsId, $question, $optionsJson]);
        }
    }

    /**
     * Голосование с хэш-защитой от накруток (IP + Cookie + UserAgent).
     */
    public static function vote(int $pollId, int $optionIndex, string $voterHash): array
    {
        if (!Database::isConnected()) {
            return ["ok" => false, "error" => "База данных недоступна"];
        }

        $stmt = Database::pdo()->prepare("SELECT * FROM news_polls WHERE id = ?");
        $stmt->execute([$pollId]);
        $poll = $stmt->fetch();
        if (!$poll) {
            return ["ok" => false, "error" => "Опрос не найден"];
        }

        $options = json_decode((string) $poll["options_json"], true);
        if (!is_array($options) || !isset($options[$optionIndex])) {
            return ["ok" => false, "error" => "Неверный вариант ответа"];
        }

        try {
            $stmt = Database::pdo()->prepare("INSERT INTO news_poll_votes (poll_id, option_index, voter_hash) VALUES (?, ?, ?)");
            $stmt->execute([$pollId, $optionIndex, $voterHash]);
        } catch (\PDOException $e) {
            // Ужé голосовал
        }

        return [
            "ok" => true,
            "results" => self::getResults($pollId, $options),
        ];
    }

    /**
     * Проверяет, проголосовал ли уже пользователь.
     */
    public static function hasVoted(int $pollId, string $voterHash): bool
    {
        if (!Database::isConnected()) {
            return false;
        }

        $stmt = Database::pdo()->prepare("SELECT COUNT(*) FROM news_poll_votes WHERE poll_id = ? AND voter_hash = ?");
        $stmt->execute([$pollId, $voterHash]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Подсчитывает результаты опроса в процентах и голосах.
     */
    public static function getResults(int $pollId, ?array $options = null): array
    {
        if (!Database::isConnected()) {
            return ["total" => 0, "items" => []];
        }

        if ($options === null) {
            $poll = Database::pdo()->query("SELECT options_json FROM news_polls WHERE id = " . (int) $pollId)->fetch();
            $decoded = $poll ? json_decode((string) $poll["options_json"], true) : [];
            $options = is_array($decoded) ? $decoded : [];
        }

        $stmt = Database::pdo()->prepare("SELECT option_index, COUNT(*) as cnt FROM news_poll_votes WHERE poll_id = ? GROUP BY option_index");
        $stmt->execute([$pollId]);
        $counts = [];
        $total = 0;
        foreach ($stmt->fetchAll() as $r) {
            $idx = (int) $r["option_index"];
            $cnt = (int) $r["cnt"];
            $counts[$idx] = $cnt;
            $total += $cnt;
        }

        $items = [];
        foreach ($options as $idx => $label) {
            $votes = $counts[$idx] ?? 0;
            $percent = $total > 0 ? round(($votes / $total) * 100, 1) : 0;
            $items[] = [
                "index" => $idx,
                "label" => $label,
                "option" => $label,
                "votes" => $votes,
                "percent" => $percent,
            ];
        }

        return [
            "total" => $total,
            "items" => $items,
        ];
    }
}

