<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Память интеграций: когда каждая в последний раз сработала и на чём споткнулась.
 *
 * Прежде интеграции были без памяти. Узнать, жива ли связь с Cloudflare,
 * Telegram или YouTube, можно было единственным способом — дёрнуть её и
 * посмотреть, что выйдет. Отсюда и брались круги диагностики: вопрос «оно
 * работает?» требовал эксперимента, а эксперимент показывал только «сейчас», и
 * ничего — про вчера.
 *
 * Здесь два решения, и оба важнее самой таблицы.
 *
 * **Успех не стирает последнюю ошибку, а ошибка не стирает последний успех.**
 * Хранятся обе отметки. Иначе одна удачная попытка затирала бы след суточного
 * простоя («всё работает» — а публикации за сутки не ушли), а одна неудача
 * прятала бы то, что связь была живой десять минут назад, то есть дело в
 * разовом сбое, а не в настройке.
 *
 * **Запись никогда не роняет вызывающего.** Интеграция, не сумевшая записать
 * свой отказ, не вправе превратить предупреждение в 500: журналировать
 * происходящее — не та задача, ради которой можно уронить сохранение новости.
 */
final class IntegrationStatus
{
    /**
     * Известные интеграции — объявлены один раз и здесь.
     *
     * Список нужен затем, что интеграция, не сработавшая **ни разу**, иначе не
     * видна вовсе: строки в таблице у неё нет, и на экране она просто
     * отсутствует — то есть выглядит так же, как исправная. Это ровно тот
     * тихий отказ, ради которого раздел и заводится, поэтому такие показываются
     * отдельно: «ни разу не использовалась».
     *
     * @var array<string, string>
     */
    public const KNOWN = [
        'cloudflare' => 'Cloudflare (очистка кэша)',
        'telegram' => 'Telegram (публикации и коды входа)',
        'mail' => 'Почта (SMTP)',
        'youtube' => 'YouTube (импорт роликов)',
        'ai' => 'ИИ (аннотации и переводы)',
    ];

    /** Длина хранимого текста ошибки: он показывается в панели, а не разбирается кодом. */
    private const MAX_ERROR = 1000;

    /**
     * Чаще раза в минуту успех не записывается.
     *
     * `Setting::set()` сбрасывает кэш настроек, и следующий `Setting::get()`
     * перечитывает таблицу целиком. Рассылка отправляет письма пачкой, и запись
     * на каждое письмо превратила бы память интеграций в сотню лишних чтений
     * всех настроек за проход. Минутной точности экрану достаточно: он отвечает
     * на вопрос «когда в последний раз работало», а не считает вызовы.
     * Отказы записываются всегда — они редки и важны.
     */
    private const OK_THROTTLE = 60;

    /** Успешный вызов интеграции. */
    public static function ok(string $name, string $operation = ''): void
    {
        self::write($name, $operation, true, '');
    }

    /** Неудачный вызов: причина уходит в память как есть, её читает владелец. */
    public static function fail(string $name, string $error, string $operation = ''): void
    {
        self::write($name, $operation, false, $error);
    }

    /** Успех или отказ одним вызовом — для мест, где результат уже посчитан. */
    public static function record(string $name, bool $ok, string $error = '', string $operation = ''): void
    {
        $ok ? self::ok($name, $operation) : self::fail($name, $error, $operation);
    }

    /**
     * Состояние всех известных интеграций, включая ни разу не использованные.
     *
     * @return list<array{name:string, title:string, ok_at:?int, fail_at:?int, error:string, operation:string, used:bool}>
     */
    public static function all(): array
    {
        $rows = self::read();
        $out = [];
        foreach (self::KNOWN as $name => $title) {
            $row = $rows[$name] ?? null;
            $out[] = [
                'name' => $name,
                'title' => $title,
                'ok_at' => $row['ok_at'] ?? null,
                'fail_at' => $row['fail_at'] ?? null,
                'error' => (string) ($row['error'] ?? ''),
                'operation' => (string) ($row['operation'] ?? ''),
                'used' => $row !== null,
            ];
        }

        return $out;
    }

    /**
     * Хранилище — строка настроек на интеграцию.
     *
     * Отдельная таблица тут ничего не добавила бы: записей ровно столько,
     * сколько интеграций, читаются они все разом и только на одном экране, а
     * миграция ради пяти строк — лишний шаг при обновлении сайта.
     *
     * @return array<string, array{ok_at:?int, fail_at:?int, error:string, operation:string}>
     */
    private static function read(): array
    {
        $out = [];
        foreach (self::KNOWN as $name => $_title) {
            $raw = Setting::get(self::key($name), '');
            if ($raw === '') {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            $out[$name] = [
                'ok_at' => isset($data['ok_at']) ? (int) $data['ok_at'] : null,
                'fail_at' => isset($data['fail_at']) ? (int) $data['fail_at'] : null,
                'error' => (string) ($data['error'] ?? ''),
                'operation' => (string) ($data['operation'] ?? ''),
            ];
        }

        return $out;
    }

    private static function write(string $name, string $operation, bool $ok, string $error): void
    {
        if (!isset(self::KNOWN[$name])) {
            return;
        }

        try {
            $current = self::read()[$name] ?? ['ok_at' => null, 'fail_at' => null, 'error' => '', 'operation' => ''];
            if ($ok
                && $current['ok_at'] !== null
                && time() - $current['ok_at'] < self::OK_THROTTLE
                && ($operation === '' || $operation === $current['operation'])) {
                return;
            }
            $data = [
                // Обе отметки переживают друг друга: см. объяснение у класса.
                'ok_at' => $ok ? time() : $current['ok_at'],
                'fail_at' => $ok ? $current['fail_at'] : time(),
                'error' => $ok ? $current['error'] : mb_substr(trim($error), 0, self::MAX_ERROR),
                'operation' => $operation !== '' ? $operation : $current['operation'],
            ];
            // Своя запись, а не редакторская: битую кодировку здесь прятать
            // нельзя, но и ронять вызывающего нечем — исключение ловится ниже.
            Setting::set(self::key($name), (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            // Память интеграций — служебная запись. Не сумели записать отказ —
            // это не повод превратить его в отказ всей операции.
            Logger::swallowed('IntegrationStatus: не удалось записать состояние ' . $name, $e);
        }
    }

    private static function key(string $name): string
    {
        return 'integration_status_' . $name;
    }
}
