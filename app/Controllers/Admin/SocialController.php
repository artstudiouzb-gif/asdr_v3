<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Heartbeat;
use App\Core\SocialPublisher;
use App\Core\SocialSettings;
use App\Core\View;
use App\Models\Setting;
use App\Models\SocialPost;

/**
 * Настройки авто-публикации в соцсети (Facebook / LinkedIn / Instagram).
 * Токены — чувствительные данные, поэтому раздел доступен только
 * супер-администратору.
 */
final class SocialController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();

        // Telegram живёт в собственном разделе (бот, коды входа, канал —
        // единый сценарий подключения), здесь остаются остальные сети.
        $config = [];
        foreach (self::formNetworks() as $net) {
            $fields = SocialSettings::configFor($net);
            $tokenConfigured = trim((string) ($fields['token'] ?? '')) !== '';
            if (array_key_exists('token', $fields)) {
                $fields['token'] = '';
            }
            $config[$net] = [
                'enabled' => SocialSettings::isEnabled($net),
                'ready' => SocialSettings::isReady($net),
                'fields' => $fields,
                'tokenConfigured' => $tokenConfigured,
            ];
        }

        View::render('admin/settings/social', [
            'config' => $config,
            'telegramEnabled' => SocialSettings::isEnabled('telegram'),
            'telegramReady' => SocialSettings::isReady('telegram'),
            'queueLog' => SocialPost::recent(40),
            'queueCounts' => SocialPost::counts(),
            'workerStatus' => Heartbeat::status()['social'] ?? null,
        ]);
    }

    /**
     * Запуск обработки очереди публикаций прямо из админки — то же, что делает
     * Cron-воркер. Полезно, если Cron ещё не настроен на хостинге или нужно
     * дослать «зависшие» pending без ожидания расписания.
     */
    public function runNow(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        // В HTTP-запросе берём небольшую пачку, чтобы медленные API соцсетей
        // не упёрлись в лимит времени веб-сервера. Cron продолжает брать по 20.
        $res = SocialSettings::dispatchQueue(5);
        if (!empty($res['busy'])) {
            Flash::error('Воркер уже выполняется. Очередь не запущена повторно.');
        } elseif ($res['taken'] === 0) {
            Flash::success('Очередь пуста — отправлять нечего.');
        } elseif ($res['failed'] === 0) {
            Flash::success("Отправлено: {$res['sent']}.");
        } elseif ($res['sent'] === 0) {
            Flash::error("Не удалось отправить: {$res['failed']}. Подробности — в журнале ниже.");
        } else {
            Flash::success("Отправлено: {$res['sent']}, с ошибкой: {$res['failed']} (см. журнал).");
        }

        header('Location: /admin/social#social-queue');
        exit;
    }

    public function update(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $prepared = [];
        $errors = [];
        // Сначала проверяем всю форму и только потом записываем: ошибка в одной
        // сети не должна наполовину сохранить остальные.
        foreach (self::formNetworks() as $net) {
            $enabled = !empty($_POST[$net]['enabled']);
            $current = SocialSettings::configFor($net);
            $candidate = [];
            $writeToken = false;
            foreach (SocialSettings::FIELDS[$net] as $field) {
                $value = trim((string) ($_POST[$net][$field] ?? ''));
                if ($field === 'token') {
                    if (!empty($_POST[$net]['clear_token'])) {
                        $value = '';
                        $writeToken = true;
                    } elseif ($value !== '') {
                        $writeToken = true;
                    } else {
                        $value = (string) ($current['token'] ?? '');
                    }
                }
                $normalized = SocialSettings::normalizeConfigField($net, $field, $value);
                $candidate[$field] = $normalized ?? $value;
            }
            foreach (SocialSettings::configurationErrors($net, $candidate, $enabled) as $error) {
                $errors[] = ucfirst($net) . ': ' . $error;
            }
            $prepared[$net] = [
                'enabled' => $enabled,
                'fields' => $candidate,
                'writeToken' => $writeToken,
            ];
        }
        if ($errors !== []) {
            Flash::error(implode(' ', $errors));
            header('Location: /admin/social#social-settings');
            exit;
        }

        // Telegram сознательно исключён: его настройки в своём разделе.
        foreach ($prepared as $net => $data) {
            Setting::set('social_' . $net . '_enabled', $data['enabled'] ? '1' : '0');
            foreach ($data['fields'] as $field => $value) {
                if ($field === 'token' && !$data['writeToken']) {
                    continue;
                }
                Setting::set('social_' . $net . '_' . $field, (string) $value);
            }
        }

        Flash::success('Настройки соцсетей сохранены.');
        header('Location: /admin/social#social-settings');
        exit;
    }

    /** Повторить одну публикацию, завершившуюся ошибкой. */
    public function retry(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && SocialPost::retryFailed($id)) {
            Flash::success('Публикация возвращена в очередь.');
        } else {
            Flash::error('Не удалось вернуть публикацию в очередь: запись не найдена или уже обрабатывается.');
        }
        header('Location: /admin/social#social-queue');
        exit;
    }

    /** @return list<string> сети, которыми управляет форма этого раздела */
    private static function formNetworks(): array
    {
        return array_values(array_filter(
            SocialPublisher::NETWORKS,
            static fn (string $net): bool => $net !== 'telegram'
        ));
    }
}
