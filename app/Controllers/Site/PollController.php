<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Models\NewsPoll;

final class PollController
{
    /** @param array<string, string> $params */
    public function vote(array $params): void
    {
        header("Content-Type: application/json; charset=utf-8");

        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            echo json_encode(["ok" => false, "error" => "Сессия устарела"], JSON_UNESCAPED_UNICODE);
            return;
        }

        $pollId = (int) ($params["id"] ?? 0);
        $optionIndex = (int) ($_POST["option"] ?? -1);

        if ($pollId <= 0 || $optionIndex < 0) {
            http_response_code(400);
            echo json_encode(["ok" => false, "error" => "Неверные параметры запроса"], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Защита от накруток: сессия даёт стабильный идентификатор, а лимит
        // по IP сдерживает повторное голосование после очистки cookie.
        $ip = $_SERVER["REMOTE_ADDR"] ?? "127.0.0.1";
        if (!RateLimiter::throttle('poll_vote', $ip, 10, 10, false)) {
            http_response_code(429);
            header('Retry-After: 600');
            echo json_encode(["ok" => false, "error" => "Слишком много попыток"], JSON_UNESCAPED_UNICODE);
            return;
        }
        $voterHash = hash("sha256", $ip . "|" . session_id());

        $res = NewsPoll::vote($pollId, $optionIndex, $voterHash);
        if (!$res["ok"]) {
            http_response_code(422);
        }

        echo json_encode($res, JSON_UNESCAPED_UNICODE);
    }
}
