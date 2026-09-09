<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\FormNotifier;
use App\Core\Session;
use App\Core\SocialPublisher;
use App\Core\SocialSettings;
use App\Core\TelegramBot;
use App\Core\TelegramGateway;
use App\Core\TelegramLink;
use App\Core\View;
use App\Models\Setting;
use App\Models\User;

/**
 * Единый раздел «Telegram»: бот, привязка администратора, публикация в канал и
 * уведомления о заявках. Раньше эти настройки лежали в трёх местах
 * («Настройки», «Профиль», «Соцсети»), и было неочевидно, что токен бота для
 * входа и токен публикации — разные поля.
 *
 * Порядок на странице повторяет порядок подключения: токен → привязка →
 * канал → уведомления.
 */
final class TelegramController
{
    private const DETECTED_CHANNELS_SESSION_KEY = 'telegram_detected_channels';

    public function index(): void
    {
        Auth::requireSuperAdmin();

        // Результат определения нужен только для следующего показа формы:
        // ID подставляется в поле, но не сохраняется без явного подтверждения.
        Session::start();
        $detectedChannels = $_SESSION[self::DETECTED_CHANNELS_SESSION_KEY] ?? [];
        unset($_SESSION[self::DETECTED_CHANNELS_SESSION_KEY]);
        if (!is_array($detectedChannels)) {
            $detectedChannels = [];
        }

        $botConfigured = trim((string) Setting::get('telegram_bot_token', '')) !== '';
        $botUsername = trim((string) Setting::get('telegram_bot_username', ''));
        $botVerifiedAt = trim((string) Setting::get('telegram_bot_verified_at', ''));
        $user = User::findById((int) Auth::id());

        View::render('admin/telegram/index', [
            'botConfigured' => $botConfigured,
            'botUsername' => $botUsername,
            'botVerifiedAt' => $botVerifiedAt,
            // Страница не должна зависать до 10 секунд при каждом открытии:
            // внешний API вызывается только явной кнопкой «Проверить».
            'botOk' => $botConfigured && $botVerifiedAt !== '',
            'linked' => (int) ($user['telegram_chat_id'] ?? 0) > 0,
            'myChatId' => (int) ($user['telegram_chat_id'] ?? 0),
            // Код показываем только пока не привязан — иначе он лишний шум.
            'linkCode' => ($botConfigured && (int) ($user['telegram_chat_id'] ?? 0) === 0)
                ? TelegramLink::code()
                : null,
            'channel' => SocialSettings::configFor('telegram'),
            'detectedChannels' => $detectedChannels,
            'channelOwnTokenConfigured' => trim((string) Setting::get('social_telegram_token', '')) !== '',
            'channelEnabled' => SocialSettings::isEnabled('telegram'),
            'notifyChatIds' => (string) Setting::get('telegram_notify_chat_ids', ''),
            'gatewayConfigured' => TelegramGateway::isConfigured(),
            'setupRestricted' => Auth::requiresTwoFactorSetup(),
        ]);
    }

    /** Шаг 1: токен бота. */
    public function saveBot(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $token = trim((string) ($_POST['telegram_bot_token'] ?? ''));
        $current = trim((string) Setting::get('telegram_bot_token', ''));
        $clear = !empty($_POST['clear_telegram_bot_token']);

        // Ограниченная onboarding-сессия может впервые настроить бота, но не
        // может заменить уже работающий канал доставки кодов.
        if (Auth::requiresTwoFactorSetup() && $current !== ''
            && ($clear || ($token !== '' && !hash_equals($current, $token)))) {
            http_response_code(403);
            View::render('errors/403');
            return;
        }

        if ($clear) {
            if (!hash_equals('REMOVE', strtoupper(trim((string) ($_POST['confirm_clear_bot'] ?? ''))))) {
                Flash::error('Токен не удалён: введите REMOVE в поле подтверждения.');
                header('Location: /admin/telegram#telegram-bot');
                exit;
            }
            Setting::set('telegram_bot_token', '');
            Setting::set('telegram_bot_username', '');
            Setting::set('telegram_bot_verified_at', '');
        } elseif ($token !== '') {
            if (!self::looksLikeToken($token)) {
                Flash::error(
                    'Токен не сохранён: ожидается вид 1234567890:AA… '
                    . 'Без слова «bot» в начале, пробелов и имени бота.'
                );
                header('Location: /admin/telegram#telegram-bot');
                exit;
            }
            Setting::set('telegram_bot_token', $token);
            Setting::set('telegram_bot_username', '');
            Setting::set('telegram_bot_verified_at', '');
        }

        Flash::success($token === '' && !$clear
            ? 'Сохранённый токен не изменён.'
            : ($clear ? 'Токен бота удалён.' : 'Токен сохранён. Теперь нажмите «Проверить бота».'));

        header('Location: /admin/telegram#telegram-bot');
        exit;
    }

    /** Шаг 1: проверка токена через getMe. */
    public function checkBot(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $candidate = trim((string) ($_POST['telegram_bot_token'] ?? ''));
        if ($candidate !== '' && !self::looksLikeToken($candidate)) {
            Flash::error('Токен выглядит неверно и не отправлен на проверку.');
            header('Location: /admin/telegram#telegram-bot');
            exit;
        }

        $checkingStored = $candidate === '';
        $me = $checkingStored ? TelegramBot::getMe() : TelegramBot::getMeWithToken($candidate);
        if (is_array($me)) {
            $username = (string) ($me['username'] ?? '');
            if ($checkingStored) {
                Setting::set('telegram_bot_username', $username);
                Setting::set('telegram_bot_verified_at', date('Y-m-d H:i:s'));
            }
            Flash::success('Бот отвечает: @' . $username . '.'
                . ($checkingStored ? '' : ' Проверенный токен ещё не сохранён.'));
        } else {
            if ($checkingStored) {
                Setting::set('telegram_bot_username', '');
                Setting::set('telegram_bot_verified_at', '');
            }
            Flash::error(
                'Бот не отвечает. Telegram отклоняет токен («Not Found» приходит именно на неверный токен). '
                . 'Возьмите токен у @BotFather: /mybots → ваш бот → API Token.'
            );
        }

        header('Location: /admin/telegram#telegram-bot');
        exit;
    }

    /**
     * Определение id канала. У закрытого канала нет имени `@name`, и числовой
     * `-100…` иначе добывают через сторонние боты. Бот видит свои каналы в
     * getUpdates, как только его сделали администратором.
     */
    public function detectChannel(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        if (!TelegramBot::isConfigured()) {
            Flash::error('Сначала сохраните токен бота.');
            header('Location: /admin/telegram#telegram-channel');
            exit;
        }

        $channels = TelegramBot::channelsFromUpdates();
        if ($channels === null) {
            Flash::error(
                'Telegram не ответил на запрос обновлений. Проверьте токен кнопкой «Проверить бота»; '
                . 'если у бота настроен webhook, getUpdates не работает, пока он включён. '
                . 'Подробности — в журнале ошибок.'
            );
            header('Location: /admin/telegram#telegram-channel');
            exit;
        }
        if ($channels === []) {
            Flash::error(
                'Каналы не найдены. Добавьте бота администратором в канал и отправьте туда любое сообщение, '
                . 'затем нажмите «Определить ID» ещё раз. Telegram отдаёт боту события только после этого.'
            );
            header('Location: /admin/telegram#telegram-channel');
            exit;
        }

        Session::start();
        $_SESSION[self::DETECTED_CHANNELS_SESSION_KEY] = $channels;

        if (count($channels) === 1) {
            $chat = $channels[0];
            Flash::success(
                'Найден канал «' . $chat['title'] . '» — ID ' . $chat['id']
                . ' автоматически вставлен в поле «Канал». Проверьте и нажмите «Сохранить канал».'
            );
        } else {
            foreach ($channels as $chat) {
                Flash::success('Найден канал «' . $chat['title'] . '» — ID: ' . $chat['id']);
            }
            Flash::success('Найдено несколько каналов. Выберите нужный под полем «Канал».');
        }
        header('Location: /admin/telegram#telegram-channel');
        exit;
    }

    /**
     * Отправить итоги недели прямо сейчас. Кнопка, а не только расписание:
     * редактор сам выбирает момент — например, дописав коллаж на обложку.
     */
    public function sendRoundup(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        // Кнопку нажал человек — отправляем и повторно, и при выключенном
        // расписании: это осознанное действие.
        $res = \App\Core\WeeklyRoundup::send(null, null, true);

        if ($res['sent']) {
            Flash::success('Итоги недели отправлены в канал: материалов в сводке — ' . $res['items'] . '.');
        } else {
            Flash::error('Сводка не отправлена. ' . $res['reason']);
        }

        header('Location: /admin/telegram#telegram-extras');
        exit;
    }

    /** Шаг 2: подтверждение привязки своего аккаунта. */
    public function link(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $res = TelegramLink::confirm((int) Auth::id());
        if ($res['ok']) {
            Flash::success($res['message']);
        } else {
            Flash::error($res['message']);
        }

        header('Location: /admin/telegram');
        exit;
    }

    /** Шаг 3: канал для публикации новостей. */
    public function saveChannel(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();
        self::requireCompletedTwoFactorSetup();

        $chatId = SocialSettings::normalizeTelegramChatId((string) ($_POST['chat_id'] ?? ''));
        $signature = trim((string) ($_POST['signature'] ?? ''));
        $ownToken = trim((string) ($_POST['own_token'] ?? ''));
        if ($chatId === null) {
            Flash::error('Канал указан неверно: используйте @имя_канала или ID вида -1001234567890.');
            header('Location: /admin/telegram#telegram-channel');
            exit;
        }
        $signatureError = SocialSettings::telegramSignatureError($signature);
        if ($signatureError !== null) {
            Flash::error($signatureError);
            header('Location: /admin/telegram#telegram-channel');
            exit;
        }
        if ($ownToken !== '' && !self::looksLikeToken($ownToken)) {
            Flash::error('Отдельный токен публикации не сохранён: ожидается вид 1234567890:AA…');
            header('Location: /admin/telegram#telegram-channel');
            exit;
        }
        $enabled = !empty($_POST['enabled']) ? '1' : '0';

        Setting::set('social_telegram_chat_id', $chatId);
        Setting::set('social_telegram_signature', $signature);
        Setting::set('social_telegram_enabled', $enabled);
        Setting::set('social_telegram_format', (string) SocialSettings::normalizeConfigField('telegram', 'format', (string) ($_POST['format'] ?? 'auto')));
        Setting::set('social_telegram_silent', !empty($_POST['silent']) ? '1' : '0');
        Setting::set('social_telegram_second_lang', (string) SocialSettings::normalizeConfigField('telegram', 'second_lang', (string) ($_POST['second_lang'] ?? 'inline')));
        Setting::set('social_telegram_buttons', !empty($_POST['buttons']) ? '1' : '0');
        if (!empty($_POST['clear_own_token'])) {
            Setting::set('social_telegram_token', '');
        } elseif ($ownToken !== '') {
            Setting::set('social_telegram_token', $ownToken);
        }

        if ($chatId !== '') {
            if ($enabled === '1') {
                Flash::success('Канал «' . $chatId . '» сохранён, авто-публикация включена.');
            } else {
                Flash::success('Канал «' . $chatId . '» сохранён (авто-публикация отключена).');
            }
        } else {
            Flash::success('Настройки канала сохранены.');
        }

        header('Location: /admin/telegram#telegram-channel');
        exit;
    }

    /** Шаг 3: проверка канала и прав бота в нём. */
    public function checkChannel(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();
        self::requireCompletedTwoFactorSetup();

        $cfg = SocialSettings::configFor('telegram');
        $submittedChatId = SocialSettings::normalizeTelegramChatId((string) ($_POST['chat_id'] ?? $cfg['chat_id'] ?? ''));
        if ($submittedChatId === null) {
            Flash::error('Канал указан неверно: используйте @имя_канала или ID вида -1001234567890.');
            header('Location: /admin/telegram#telegram-channel');
            exit;
        }
        $cfg['chat_id'] = $submittedChatId;
        $ownToken = trim((string) ($_POST['own_token'] ?? ''));
        if ($ownToken !== '') {
            if (!self::looksLikeToken($ownToken)) {
                Flash::error('Отдельный токен публикации выглядит неверно.');
                header('Location: /admin/telegram#telegram-channel');
                exit;
            }
            $cfg['token'] = $ownToken;
        } elseif (!empty($_POST['clear_own_token'])) {
            $cfg['token'] = trim((string) Setting::get('telegram_bot_token', ''));
        }

        $result = (new SocialPublisher())->checkTelegram($cfg);
        foreach ($result['steps'] as $step) {
            $line = $step['name'] . ': ' . $step['text'];
            if ($step['ok']) {
                Flash::success($line);
            } else {
                Flash::error($line);
            }
        }
        if ($result['ok']) {
            Flash::success('Публикация настроена верно.');
        }

        header('Location: /admin/telegram#telegram-channel');
        exit;
    }

    /** Шаг 4: получатели уведомлений о заявках форм и резервный Gateway. */
    public function saveExtras(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();
        self::requireCompletedTwoFactorSetup();

        $rawChatIds = trim((string) ($_POST['telegram_notify_chat_ids'] ?? ''));
        if (mb_strlen($rawChatIds) > 5000) {
            Flash::error('Список получателей слишком длинный.');
            header('Location: /admin/telegram#telegram-extras');
            exit;
        }
        $chatIds = FormNotifier::parseChatIds($rawChatIds);
        if ($rawChatIds !== '' && $chatIds === []) {
            Flash::error('Не найдено ни одного корректного chat_id получателя.');
            header('Location: /admin/telegram#telegram-extras');
            exit;
        }
        Setting::set('telegram_notify_chat_ids', implode(', ', $chatIds));
        Setting::set(
            \App\Core\WeeklyRoundup::ENABLED_KEY,
            !empty($_POST['telegram_roundup']) ? '1' : '0'
        );
        // Обложка сводки: загрузка файла или адрес из медиабиблиотеки.
        // Пустое присланное поле — явная очистка (кнопка «×»).
        $cover = \App\Core\ImageField::resolve(
            'telegram_roundup_image_file',
            'telegram_roundup_image',
            (string) Setting::get(\App\Core\WeeklyRoundup::COVER_KEY, ''),
            (int) Auth::id()
        );
        if ($cover !== null) {
            Setting::set(\App\Core\WeeklyRoundup::COVER_KEY, $cover);
        }
        $gatewayToken = mb_substr(trim((string) ($_POST['telegram_gateway_token'] ?? '')), 0, 10000);
        if (!empty($_POST['clear_telegram_gateway_token'])) {
            Setting::set('telegram_gateway_token', '');
        } elseif ($gatewayToken !== '') {
            Setting::set('telegram_gateway_token', $gatewayToken);
        }

        Flash::success('Дополнительные настройки сохранены.');
        header('Location: /admin/telegram#telegram-extras');
        exit;
    }

    /** Проверка доставки уведомлений без сохранения введённого списка. */
    public function checkExtras(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();
        self::requireCompletedTwoFactorSetup();

        if (!TelegramBot::isConfigured()) {
            Flash::error('Сначала настройте и проверьте основного Telegram-бота.');
            header('Location: /admin/telegram#telegram-extras');
            exit;
        }
        $raw = trim((string) ($_POST['telegram_notify_chat_ids'] ?? ''));
        if (mb_strlen($raw) > 5000) {
            Flash::error('Список получателей слишком длинный.');
            header('Location: /admin/telegram#telegram-extras');
            exit;
        }
        $ids = FormNotifier::parseChatIds($raw);
        if ($ids === []) {
            Flash::error('Укажите хотя бы один корректный chat_id получателя.');
            header('Location: /admin/telegram#telegram-extras');
            exit;
        }

        $sent = 0;
        foreach ($ids as $chatId) {
            if (TelegramBot::sendMessage(
                $chatId,
                '<b>Проверка уведомлений ASDR CMS</b>' . "\n\n"
                . 'Соединение с Telegram работает. Этот chat_id готов получать заявки с сайта.'
            )) {
                $sent++;
            }
        }
        if ($sent === count($ids)) {
            Flash::success('Тестовое уведомление доставлено всем получателям: ' . $sent . '.');
        } else {
            Flash::error('Доставлено ' . $sent . ' из ' . count($ids)
                . '. Проверьте chat_id и убедитесь, что получатель запустил бота.');
        }

        header('Location: /admin/telegram#telegram-extras');
        exit;
    }

    /** Токен Bot API: цифры, двоеточие, ключ. */
    private static function looksLikeToken(string $token): bool
    {
        return strlen($token) <= 256
            && (bool) preg_match('/^\d{6,}:[A-Za-z0-9_-]{30,}$/', $token);
    }

    private static function requireCompletedTwoFactorSetup(): void
    {
        if (Auth::requiresTwoFactorSetup()) {
            http_response_code(403);
            View::render('errors/403');
            exit;
        }
    }
}
