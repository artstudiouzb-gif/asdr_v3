<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Telegram-уведомления о новых заявках форм через собственного бота
 * (TelegramBot, тот же токен, что и для кодов входа). Получатели — список
 * chat_id в настройке telegram_notify_chat_ids (через запятую; отрицательные
 * id — групповые чаты).
 * Поля заявок размечаются стильным HTML с интерактивными тегами <code>...</code>
 * для мгновенного копирования номеров, почты, кодов по клику в Telegram.
 */
final class FormNotifier
{
    /** Telegram ограничивает сообщение 4096 символами — режем с запасом. */
    private const MAX_TEXT = 3800;

    /** Длинные значения полей укорачиваются до этого размера. */
    private const MAX_FIELD = 500;

    public static function isEnabled(): bool
    {
        return TelegramBot::isConfigured() && self::chatIds() !== [];
    }

    /** @return array<int, int> */
    public static function chatIds(): array
    {
        return self::parseChatIds(Setting::get('telegram_notify_chat_ids', ''));
    }

    /**
     * Разбирает список chat_id из настройки: разделители — запятая, точка с
     * запятой, пробелы, переводы строк; мусор пропускается (чистая функция).
     *
     * @return array<int, int>
     */
    public static function parseChatIds(string $raw): array
    {
        $ids = [];
        foreach (preg_split('/[\s,;]+/', trim($raw)) ?: [] as $part) {
            if ($part !== '' && preg_match('/^-?\d{1,19}$/', $part) === 1) {
                $id = filter_var($part, FILTER_VALIDATE_INT);
                if ($id !== false && $id !== 0) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Текст уведомления о заявке (чистая функция): название формы + поля.
     * Поля вроде телефона, email, кодов, IP оборачиваются в <code>...</code>
     * для автоматического копирования по клику в клиенте Telegram.
     *
     * @param array<string, string> $fields
     */
    public static function formatSubmission(string $formName, array $fields): string
    {
        $lines = ["<b>Новая заявка: " . htmlspecialchars($formName, ENT_QUOTES) . "</b>\n"];
        foreach ($fields as $key => $value) {
            $value = trim((string) $value);
            if (mb_strlen($value) > self::MAX_FIELD) {
                $value = mb_substr($value, 0, self::MAX_FIELD) . '…';
            }
            $safeKey = htmlspecialchars((string) $key, ENT_QUOTES);
            $safeVal = htmlspecialchars($value, ENT_QUOTES);

            $keyLower = mb_strtolower((string) $key);
            // Если поле содержит контакты, коды, логины или IP-адреса — оборачиваем в <code> для копирования по клику
            if (
                str_contains($keyLower, 'телефон') || str_contains($keyLower, 'phone') || str_contains($keyLower, 'tel') ||
                str_contains($keyLower, 'email') || str_contains($keyLower, 'почта') || str_contains($keyLower, 'код') ||
                str_contains($keyLower, 'code') || str_contains($keyLower, 'id') || str_contains($keyLower, 'номер') ||
                str_contains($keyLower, 'ip') || preg_match('/^\+?\d[\d\s\-()]{7,}$/', $value)
            ) {
                $lines[] = "• <b>{$safeKey}:</b> <code>{$safeVal}</code>";
            } elseif (mb_strlen($value) > 60 || str_contains($value, "\n")) {
                $lines[] = "• <b>{$safeKey}:</b>\n<blockquote>{$safeVal}</blockquote>";
            } else {
                $lines[] = "• <b>{$safeKey}:</b> {$safeVal}";
            }
        }

        $text = implode("\n", $lines);
        if (mb_strlen($text) > self::MAX_TEXT) {
            $text = mb_substr($text, 0, self::MAX_TEXT) . '…';
        }

        return $text;
    }

    /**
     * Шлёт уведомление о новой заявке всем получателям.
     * Возвращает число успешных доставок (0 — если канал не настроен).
     *
     * @param array<string, string> $fields
     */
    public static function notifySubmission(string $formName, array $fields): int
    {
        return self::broadcast(self::formatSubmission($formName, $fields));
    }

    /**
     * Произвольное служебное уведомление тем же получателям (сбой автобэкапа
     * и т.п.). Отправляет сообщения параллельно через curl_multi для минимальной задержки.
     * Возвращает число успешных доставок.
     */
    public static function broadcast(string $text): int
    {
        if (!self::isEnabled()) {
            return 0;
        }

        $chatIds = self::chatIds();
        if ($chatIds === []) {
            return 0;
        }

        if (count($chatIds) === 1) {
            return TelegramBot::sendMessage($chatIds[0], $text) ? 1 : 0;
        }

        $token = trim(Setting::get('telegram_bot_token', ''));
        if ($token === '') {
            return 0;
        }

        $base = getenv('TELEGRAM_BOT_URL') ?: 'https://api.telegram.org';
        $url = rtrim($base, '/') . '/bot' . $token . '/sendMessage';

        $mh = curl_multi_init();
        $handles = [];

        foreach ($chatIds as $chatId) {
            $ch = curl_init($url);
            $payload = json_encode([
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ], JSON_UNESCAPED_UNICODE);

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            curl_multi_add_handle($mh, $ch);
            $handles[$chatId] = $ch;
        }

        $active = null;
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 0.05);
            }
        } while ($active && $status === CURLM_OK);

        $sent = 0;
        foreach ($handles as $chatId => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $json = is_string($response) ? json_decode($response, true) : null;

            if ($httpCode === 200 && is_array($json) && ($json['ok'] ?? false) === true) {
                $sent++;
            } else {
                // Если HTML-парсинг не удался, пробуем чистый текст вторым шансом
                if (TelegramBot::sendMessage($chatId, strip_tags($text), '')) {
                    $sent++;
                }
            }

            curl_multi_remove_handle($mh, $ch);
        }

        curl_multi_close($mh);

        return $sent;
    }
}
