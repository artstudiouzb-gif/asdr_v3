<?php

use App\Core\Csrf;
use App\Models\SessionRegistry;

/** @var array $sessions */
/** @var string $currentHash */
/** @var array|null $profileUser */
$sessions = $sessions ?? [];
$currentHash = $currentHash ?? '';
$profileUser = $profileUser ?? null;
$pageTitle = 'Профиль и безопасность';
$activeNav = 'profile';
require __DIR__ . '/../layout/header.php';

function ua_short(?string $ua): string
{
    $ua = (string) $ua;
    $browser = 'Браузер';
    if (stripos($ua, 'Firefox') !== false) { $browser = 'Firefox'; }
    elseif (stripos($ua, 'Edg') !== false) { $browser = 'Edge'; }
    elseif (stripos($ua, 'Chrome') !== false) { $browser = 'Chrome'; }
    elseif (stripos($ua, 'Safari') !== false) { $browser = 'Safari'; }
    $os = '';
    if (stripos($ua, 'Windows') !== false) { $os = 'Windows'; }
    elseif (stripos($ua, 'Android') !== false) { $os = 'Android'; }
    elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) { $os = 'iOS'; }
    elseif (stripos($ua, 'Mac') !== false) { $os = 'macOS'; }
    elseif (stripos($ua, 'Linux') !== false) { $os = 'Linux'; }
    return trim($browser . ($os !== '' ? ' · ' . $os : ''));
}
?>

<div class="form-card">
    <h2 class="u-inline-291b7bbb01">Язык интерфейса по умолчанию</h2>
    <p class="form-hint">Выберите ваш персональный язык для работы в панели управления. Он будет использоваться при каждом входе.</p>
    <form method="post" action="/admin/profile/admin-lang" class="form-grid u-inline-6add97efa7">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="profile_admin_lang">Язык админ-панели</label>
            <select id="profile_admin_lang" name="admin_lang">
                <option value="">— Системный по умолчанию —</option>
                <?php foreach (\App\Models\Language::active() as $l): ?>
                    <?php
                    $code = (string) $l['code'];
                    $name = (string) $l['name'];
                    $userLang = (string) ($profileUser['admin_lang'] ?? '');
                    ?>
                    <option value="<?= htmlspecialchars($code, ENT_QUOTES) ?>"<?= $code === $userLang ? ' selected' : '' ?>>
                        <?= htmlspecialchars($name, ENT_QUOTES) ?> (<?= strtoupper(htmlspecialchars($code, ENT_QUOTES)) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Сохранить язык</button>
        </div>
    </form>
</div>

<?php
$currentUserId = (int) ($profileUser['id'] ?? 0);
$currentAppearance = $_SESSION['admin_appearance']
    ?? \App\Models\Setting::get('admin_appearance_user_' . $currentUserId, 'system');

// Пять цветовых тем убраны: четыре были вариантами одного и того же светлого
// вида, а пятая — тёмным, который приходил ещё и вторым путём (тёмная тема
// операционной системы). Два независимых механизма спорили: выбранная тема
// перекрашивалась чужими цветами при тёмной ОС. Остался один выбор.
$appearanceList = [
    'system' => ['Как в системе', 'Панель светлая или тёмная — так же, как настроена ваша операционная система.', 'device-desktop'],
    'light' => ['Светлая', 'Всегда светлая, независимо от настроек системы.', 'sun'],
    'dark' => ['Тёмная', 'Всегда тёмная, независимо от настроек системы.', 'moon'],
];
?>

<div class="form-card u-inline-3343fd6464">
    <h2 class="u-inline-1bff6ebf4f">
        <?= \App\Core\AdminUi::icon('palette', 18) ?> Внешний вид панели
    </h2>
    <p class="form-hint u-inline-5b6aad9a3f">
        Выбор действует только для вашей учётной записи. Цвет акцента панели задаётся отдельно, в «Настройках сайта».
    </p>

    <form method="post" action="/admin/profile/admin-theme">
        <?= Csrf::field() ?>
        <div class="u-inline-5429e4728e">
            <?php foreach ($appearanceList as $aKey => [$aName, $aDesc, $aIcon]): ?>
                <?php $isAct = $aKey === $currentAppearance; ?>
                <label class="admin-theme-card<?= $isAct ? ' is-active' : '' ?>"
                       data-admin-appearance-preview="<?= htmlspecialchars($aKey, ENT_QUOTES) ?>">
                    <div class="u-inline-e02c6d0480">
                        <span class="u-inline-0b08752dae">
                            <input class="u-inline-1da9facb4d" type="radio" name="admin_appearance" value="<?= htmlspecialchars($aKey, ENT_QUOTES) ?>" <?= $isAct ? 'checked' : '' ?>>
                            <?= \App\Core\AdminUi::icon($aIcon, 16) ?>
                            <?= htmlspecialchars($aName, ENT_QUOTES) ?>
                        </span>
                        <?php if ($isAct): ?>
                            <span class="badge badge--success u-inline-07bbf13a37">Выбрано</span>
                        <?php endif; ?>
                    </div>
                    <p class="u-inline-934ef2d866"><?= htmlspecialchars($aDesc, ENT_QUOTES) ?></p>
                </label>
            <?php endforeach; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Сохранить внешний вид</button>
        </div>
    </form>
</div>

<div class="form-card u-inline-3343fd6464">
    <h2 class="u-inline-291b7bbb01">Смена пароля</h2>
    <form method="post" action="/admin/profile/password" class="form-grid u-inline-6add97efa7">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="current_password">Текущий пароль</label>
            <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
        </div>
        <div class="form-field">
            <label for="new_password">Новый пароль</label>
            <input type="password" id="new_password" name="new_password" autocomplete="new-password" required>
            <span class="form-hint">Минимум 10 символов, минимум две группы символов, не из списка популярных паролей.</span>
        </div>
        <div class="form-field">
            <label for="new_password_confirm">Повторите новый пароль</label>
            <input type="password" id="new_password_confirm" name="new_password_confirm" autocomplete="new-password" required>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Изменить пароль</button>
        </div>
    </form>
</div>

<div class="form-card u-inline-3343fd6464">
    <h2 class="u-inline-291b7bbb01">Приложение-аутентификатор (работает без интернета)</h2>
    <?php if ($totpEnabled ?? false): ?>
        <p><span class="badge badge--success">Подключено</span> При входе вводите 6-значный код из приложения.</p>
        <p class="form-hint">Это единственный канал, который не зависит от Telegram и связи: коды считаются на вашем устройстве.</p>
        <form method="post" action="/admin/profile/totp/disable" class="form-grid u-inline-6add97efa7">
            <?= Csrf::field() ?>
            <div class="form-field">
                <label for="totp_password">Подтвердите паролем, чтобы отключить</label>
                <input type="password" id="totp_password" name="password" autocomplete="current-password" required>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn--danger">Отключить приложение</button></div>
        </form>
    <?php elseif (!($totpReady ?? true)): ?>
        <p class="form-hint">Чтобы подключить приложение, задайте в конфигурации сайта переменную <code>APP_ENCRYPTION_KEY</code> — секрет приложения хранится зашифрованным.</p>
    <?php else: ?>
        <p class="form-hint">Подключите Google Authenticator, Aegis, 1Password или другое приложение — коды будут работать даже без интернета и без Telegram.</p>
        <?php
        $totpQr = '';
        if (!empty($totpUri)) {
            try {
                $totpQr = \App\Core\QrCode::svg((string) $totpUri, 4);
            } catch (\Throwable $e) {
                \App\Core\Logger::swallowed('Профиль: не удалось построить QR-код для двухфакторной настройки', $e);
            }
        }
        ?>
        <div class="totp-setup">
            <?php if ($totpQr !== ''): ?><div class="totp-setup__qr"><?= $totpQr ?></div><?php endif; ?>
            <ol class="u-inline-d40243f2ec">
                <li><?= $totpQr !== '' ? 'Отсканируйте QR-код приложением.' : 'Добавьте ключ в приложение-аутентификатор.' ?></li>
                <li>Ключ для ручного ввода: <code class="u-inline-e906946788"><?= htmlspecialchars((string) ($totpSecret ?? ''), ENT_QUOTES) ?></code></li>
                <li>Введите код из приложения и сохраните.</li>
            </ol>
        </div>
        <form method="post" action="/admin/profile/totp/enable" class="form-grid u-inline-6add97efa7">
            <?= Csrf::field() ?>
            <div class="form-field">
                <label for="totp_code">Код из приложения</label>
                <input type="text" id="totp_code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,9}" maxlength="9" required>
            </div>
            <div class="form-field">
                <label for="totp_enable_password">Подтвердите паролем</label>
                <input type="password" id="totp_enable_password" name="password" autocomplete="current-password" required>
                <p class="form-hint">Пароль нужен, чтобы чужая сессия не могла привязать к аккаунту свой аутентификатор.</p>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn--primary">Подключить приложение</button></div>
        </form>
    <?php endif; ?>
</div>

<div class="form-card u-inline-3343fd6464">
    <h2 class="u-inline-291b7bbb01">Код входа через Telegram-бота (бесплатно)</h2>
    <?php if (!($botConfigured ?? false)): ?>
        <p class="form-hint">
            Бот не настроен. Создайте бота у <strong>@BotFather</strong> (бесплатно) и укажите токен
            в разделе <a href="/admin/telegram">Telegram</a> — там же проверка подключения и привязка.
        </p>
    <?php elseif ($botLinked ?? false): ?>
        <p><span class="badge badge--success">Привязан</span> Коды входа приходят вам в Telegram от бота.</p>
        <p class="form-hint">Ваш chat_id: <code><?= (int) ($profileUser['telegram_chat_id'] ?? 0) ?></code> — укажите его в разделе <a href="/admin/telegram">Telegram</a>, чтобы получать уведомления о заявках с форм.</p>
        <form method="post" action="/admin/profile/telegram/unlink" class="form-grid u-inline-6add97efa7">
            <?= Csrf::field() ?>
            <div class="form-field">
                <label for="tg_password">Подтвердите паролем, чтобы отвязать</label>
                <input type="password" id="tg_password" name="password" autocomplete="current-password" required>
            </div>
            <div class="form-actions"><button type="submit" class="btn btn--danger">Отвязать Telegram</button></div>
        </form>
    <?php else: ?>
        <p class="form-hint">Привяжите свой Telegram — и коды входа будут приходить от бота бесплатно (без QR-кодов и секретов на экране):</p>
        <ol class="u-inline-d40243f2ec">
            <li>Откройте бота<?php if (!empty($botUsername)): ?> <a href="https://t.me/<?= htmlspecialchars($botUsername, ENT_QUOTES) ?>" target="_blank" rel="noopener">@<?= htmlspecialchars($botUsername, ENT_QUOTES) ?></a><?php endif; ?> и нажмите <strong>Start</strong>.</li>
            <li>Отправьте боту код: <code class="u-inline-e906946788"><?= htmlspecialchars((string) ($linkCode ?? ''), ENT_QUOTES) ?></code></li>
            <li>Нажмите кнопку ниже.</li>
        </ol>
        <form method="post" action="/admin/profile/telegram/link">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn--primary">Проверить привязку</button>
        </form>
    <?php endif; ?>
</div>

<div class="form-card u-inline-3343fd6464">
    <h2 class="u-inline-291b7bbb01">Резервный канал: телефон (платный шлюз)</h2>
    <p class="form-hint">Код входа приходит в Telegram от официального канала
       <strong>Verification&nbsp;Codes</strong> (t.me/VerificationCodes) на номер, привязанный к вашему
       Telegram-аккаунту. Без телефона вход выполняется только по паролю.</p>
    <form method="post" action="/admin/profile/phone" class="form-grid u-inline-6add97efa7">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="phone">Телефон (международный формат)</label>
            <input type="tel" id="phone" name="phone" placeholder="+998901234567" value="<?= htmlspecialchars((string) ($profileUser['phone'] ?? ''), ENT_QUOTES) ?>" autocomplete="tel">
            <span class="form-hint">Оставьте пустым, чтобы отключить код подтверждения для своего аккаунта.</span>
        </div>
        <div class="form-field">
            <label for="ph_password">Подтвердите паролем</label>
            <input type="password" id="ph_password" name="password" autocomplete="current-password" required>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Сохранить телефон</button>
        </div>
    </form>
</div>

<div class="form-card u-inline-3343fd6464">
    <h2 class="u-inline-291b7bbb01">Активные сессии</h2>
    <p class="form-hint">Список устройств, где выполнен вход. Отзыв сессии мгновенно завершает её на сервере.</p>
    <table class="data-table">
        <thead>
            <tr><th>Устройство</th><th>IP</th><th>Вход</th><th>Активность</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($sessions as $s): ?>
            <?php $isCurrent = hash_equals((string) $currentHash, (string) $s['sid_hash']); ?>
            <tr>
                <td><?= htmlspecialchars(ua_short($s['user_agent'] ?? ''), ENT_QUOTES) ?>
                    <?php if ($isCurrent): ?><span class="badge">текущая</span><?php endif; ?>
                </td>
                <td><?= htmlspecialchars((string) ($s['ip_address'] ?? '—'), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) $s['created_at'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) $s['last_seen_at'], ENT_QUOTES) ?></td>
                <td>
                    <?php if (!$isCurrent): ?>
                    <form method="post" action="/admin/profile/sessions/<?= (int) $s['id'] ?>/revoke">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn--small">Отозвать</button>
                    </form>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <form class="u-inline-8a359a76eb" method="post" action="/admin/profile/sessions/revoke-others">
        <?= Csrf::field() ?>
        <button type="submit" class="btn">Выйти на всех других устройствах</button>
    </form>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
