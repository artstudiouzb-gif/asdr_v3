<?php

declare(strict_types=1);

namespace App\Core\Ai;

/**
 * Объяснение записи журнала ошибок человеческим языком.
 *
 * Раздел «Журналы» сделан ради того, чтобы владелец не скачивал `error.log`
 * инженеру, — но показывает он ровно то, что записал PHP: «Call to a member
 * function on null» с путём и номером строки. Для того, кто не программист,
 * это такой же неразобранный файл, только на экране.
 *
 * Отсюда два обязательства, и второе важнее первого:
 * - ответ состоит из «что это значит» и «что делать», а не из пересказа
 *   сообщения другими словами;
 * - наружу уходит **только текст ошибки**. Журнал видит и содержимое запросов,
 *   и имена таблиц, поэтому строка обрезается, а раздел и без того доступен
 *   лишь супер-админу: отправлять к третьей стороне данные посетителей мы не
 *   вправе ни при какой пользе от объяснения.
 */
final class AiLogDoctor
{
    /** Длина строки, уходящей к модели: стек целиком ей не нужен. */
    public const MAX_INPUT = 1200;

    /**
     * @return array{meaning:string, action:string, notice:string}
     */
    public static function explain(string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            return self::fail('Пустая запись — объяснять нечего.');
        }
        if (!AiClient::configured()) {
            return self::fail('Ключ Gemini не настроен: разбор записи недоступен.');
        }

        $result = AiClient::json(
            'Ты инженер поддержки PHP-сайта. Объясняешь ошибки владельцу сайта, который не программист.',
            "Разбери запись журнала:\n"
                . "- meaning: что произошло, одно-два предложения без жаргона и без пересказа сообщения;\n"
                . "- action: что сделать владельцу или что передать разработчику, по делу и коротко;\n"
                . "- ничего не выдумывай: непонятна запись — так и скажи в meaning;\n"
                . "- не предлагай менять код, которого не видно из записи.\n\n"
                . "Запись:\n" . mb_substr($message, 0, self::MAX_INPUT),
            [
                'type' => 'object',
                'properties' => [
                    'meaning' => ['type' => 'string'],
                    'action' => ['type' => 'string'],
                ],
                'required' => ['meaning', 'action'],
            ],
            ['label' => 'разбор записи журнала', 'maxTokens' => 400, 'temperature' => 0.2]
        );

        $meaning = AiClient::str($result, 'meaning');
        if ($meaning === '') {
            return self::fail('Gemini не ответил — попробуйте ещё раз.');
        }

        return [
            'meaning' => mb_substr($meaning, 0, 600),
            'action' => mb_substr(AiClient::str($result, 'action'), 0, 600),
            'notice' => '',
        ];
    }

    /** @return array{meaning:string, action:string, notice:string} */
    private static function fail(string $notice): array
    {
        return ['meaning' => '', 'action' => '', 'notice' => $notice];
    }
}
