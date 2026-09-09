<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Отправка алертов в Telegram (задача 59) нативным HTTP-клиентом, без SDK.
 * Уровни, метки, троттлинг и выбор чата обрабатываются здесь; вызывается через
 * App\Core\Logger::event(). Конфигурация читается строго из config[telegram].
 */
final class TelegramNotifier
{
    /** Порядок уровней для сравнения с min_level (SECURITY — вне шкалы). */
    private const RANK = ['INFO' => 10, 'WARNING' => 20, 'ERROR' => 30, 'CRITICAL' => 40];

    private const LABELS = [
        'CRITICAL' => '[КРИТИЧНО]',
        'ERROR' => '[ОШИБКА]',
        'WARNING' => '[ПРЕДУПРЕЖДЕНИЕ]',
        'SECURITY' => '[БЕЗОПАСНОСТЬ]',
        'INFO' => '[ИНФО]',
    ];

    /** TTL троттлинга по уровню (секунды). CRITICAL — без троттлинга. */
    private const THROTTLE_TTL = [
        'CRITICAL' => 0,
        'ERROR' => 300,
        'SECURITY' => 600,
        'WARNING' => 900,
        'INFO' => 3600,
    ];

    /** @var callable(string,array):void|null Инжектируемый транспорт (для тестов). */
    private static $transport = null;

    /** @param callable(string,array):void|null $transport */
    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function send(string $level, string $message, array $context = []): void
    {
        $level = strtoupper($level);
        $cfg = (array) Config::get('telegram', []);

        if (trim((string) ($cfg['bot_token'] ?? '')) === '' || trim((string) ($cfg['chat_id'] ?? '')) === '') {
            return; // не настроено
        }
        if (!self::isEligible($level, (string) ($cfg['min_level'] ?? 'WARNING'))) {
            return;
        }

        // Троттлинг по сигнатуре (уровень + форма сообщения), чтобы поток
        // однотипных событий не спамил чат. CRITICAL не троттлится.
        $ttl = $context['throttle'] ?? (self::THROTTLE_TTL[$level] ?? 300);
        if ($ttl > 0 && !self::passThrottle($level, $message, (int) $ttl)) {
            return;
        }

        $chatId = ($level === 'SECURITY' && trim((string) ($cfg['chat_id_security'] ?? '')) !== '')
            ? (string) $cfg['chat_id_security']
            : (string) $cfg['chat_id'];

        $text = self::buildText($level, $message, $context);
        $url = 'https://api.telegram.org/bot' . $cfg['bot_token'] . '/sendMessage';
        $fields = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'MarkdownV2', 'disable_web_page_preview' => 'true'];

        try {
            if (self::$transport !== null) {
                (self::$transport)($url, $fields);
            } else {
                Http::postForm($url, $fields, [], 10);
            }
        } catch (\Throwable $e) {
            // Не логируем через Logger, чтобы не спровоцировать рекурсию.
            error_log(Logger::redact('Telegram send failed: ' . $e->getMessage()));
        }
    }

    /** Уровень достаточно важен для отправки. SECURITY отправляется всегда. */
    public static function isEligible(string $level, string $minLevel): bool
    {
        if ($level === 'SECURITY') {
            return true;
        }
        $min = self::RANK[strtoupper($minLevel)] ?? self::RANK['WARNING'];
        $cur = self::RANK[$level] ?? 0;

        return $cur >= $min;
    }

    /** Экранирование для Telegram MarkdownV2. */
    public static function escapeMarkdown(string $text): string
    {
        return preg_replace('/([_*\[\]()~`>#+\-=|{}.!\\\\])/', '\\\\$1', $text) ?? $text;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function buildText(string $level, string $message, array $context): string
    {
        $label = self::LABELS[$level] ?? $level;
        $tz = (string) Config::get('app.timezone', 'UTC');
        try {
            $time = (new \DateTime('now', new \DateTimeZone($tz)))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            $time = date('Y-m-d H:i:s');
        }

        $lines = [
            '*' . self::escapeMarkdown($label) . '*',
            self::escapeMarkdown($message),
        ];

        foreach (['file', 'line', 'url', 'ip', 'user', 'network'] as $key) {
            if (isset($context[$key]) && $context[$key] !== '') {
                $lines[] = '_' . self::escapeMarkdown(ucfirst($key)) . ':_ `' . self::escapeMarkdown((string) $context[$key]) . '`';
            }
        }
        $lines[] = '_' . self::escapeMarkdown($time . ' (' . $tz . ')') . '_';

        return implode("\n", $lines);
    }

    private static function passThrottle(string $level, string $message, int $ttl): bool
    {
        $dir = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/cache/telegram';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return true; // не смогли создать каталог — не блокируем отправку
        }

        // Сигнатура: уровень + сообщение с обезличенными числами (группировка похожих).
        $signature = $level . '|' . preg_replace('/\d+/', '#', $message);
        $flag = $dir . '/' . sha1($signature) . '.flag';

        if (is_file($flag) && (time() - (int) @filemtime($flag)) < $ttl) {
            return false;
        }
        @touch($flag);

        return true;
    }
}
