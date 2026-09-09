<?php

use App\Core\Csrf;

$pageTitle = 'Соцсети — авто-публикация';
$activeNav = 'social';
require __DIR__ . '/../layout/header.php';

/** @var array $config */
$labels = [
    'telegram' => 'Telegram-канал',
    'facebook' => 'Facebook (страница)',
    'linkedin' => 'LinkedIn (организация/профиль)',
    'instagram' => 'Instagram (Business-аккаунт)',
];
$fieldLabels = [
    'token' => 'Access Token',
    'chat_id' => 'Chat ID канала (@имя_канала или -100…)',
    'page_id' => 'ID страницы',
    'author' => 'Author URN (напр. urn:li:organization:123)',
    'user_id' => 'IG User ID',
    'signature' => 'Подпись под постом (необязательно)',
];
$signatureHints = [
    'telegram' => 'Допустима HTML-разметка Telegram: &lt;b&gt;, &lt;i&gt;, &lt;a href="…"&gt;, &lt;blockquote expandable&gt;, &lt;tg-spoiler&gt;, &lt;code&gt;. '
        . 'Например: &lt;a href="https://artstudio.uz"&gt;Сайт&lt;/a&gt; | &lt;a href="https://facebook.com/…"&gt;Facebook&lt;/a&gt;',
    'facebook' => 'Только обычный текст — HTML не поддерживается. Ссылки пишите голыми URL, Facebook сам сделает их кликабельными.',
    'linkedin' => 'Только обычный текст — HTML не поддерживается. Ссылки пишите голыми URL, LinkedIn сам сделает их кликабельными.',
    'instagram' => 'Только обычный текст. Ссылки в подписи Instagram НЕ кликабельны — здесь имеют смысл хештеги и @упоминания.',
];

$tokenGuides = [
    'telegram' => [
        'title' => 'Инструкция: Как получить Токен бота и Chat ID для Telegram',
        'steps' => [
            'Откройте бота <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> в Telegram и отправьте команду <code>/newbot</code>.',
            'Задайте название и логин бота, затем скопируйте полученный <b>HTTP API Token</b> (например: <code>123456789:AAF...</code>).',
            'Добавьте вашего бота в <b>Администраторы Telegram-канала</b> с правом <i>«Публикация сообщений»</i>.',
            '<b>Chat ID</b> канала — это его публичный логин вида <code>@название_канала</code> или числовой ID вида <code>-1001234567890</code> (для приватных каналов).',
        ],
    ],
    'facebook' => [
        'title' => 'Инструкция: Как получить Access Token и ID Страницы Facebook',
        'steps' => [
            'Перейдите в инструмент разработчиков Meta: <a href="https://developers.facebook.com/tools/explorer/" target="_blank" rel="noopener">Graph API Explorer</a>.',
            'В поле <b>Meta App</b> выберите ваше приложение, а в выпадающем списке <b>User or Page</b> выберите <i>Маркер доступа страницы (Page Access Token)</i>.',
            'Добавьте разрешения в разделе Permissions: <code>pages_manage_posts</code>, <code>pages_read_engagement</code>, <code>pages_show_list</code>.',
            'Сгенерируйте токен. Для бессрочного токена откройте <a href="https://developers.facebook.com/tools/accesstoken/" target="_blank" rel="noopener">Access Token Tool</a> -> нажмите <i>Debug</i> -> <i>Extend Access Token</i>.',
            '<b>ID Страницы</b> можно узнать в URL вашей страницы Facebook (например `facebook.com/123456789`) или в разделе <i>Информация о странице</i>.',
        ],
    ],
    'linkedin' => [
        'title' => 'Инструкция: Как получить Access Token и Author URN для LinkedIn',
        'steps' => [
            'Зайдите в портал <a href="https://www.linkedin.com/developers/apps" target="_blank" rel="noopener">LinkedIn Developers</a> и создайте приложение (App).',
            'Во вкладке <b>Products</b> запросите доступ к <i>Share on LinkedIn</i> и <i>Community Management API</i>.',
            'Перейдите в генератор маркеров <b>OAuth 2.0 Tools</b> (или сгенерируйте в Postman): запросите права <code>w_member_social</code> (для личного профиля) или <code>w_organization_social</code> (для страницы компании).',
            '<b>Author URN</b> для компании имеет формат <code>urn:li:organization:123456</code> (где <code>123456</code> — числовой ID компании из URL её администрирования). Для личной страницы: <code>urn:li:person:ID</code>.',
        ],
    ],
    'instagram' => [
        'title' => 'Инструкция: Как получить Access Token и IG User ID для Instagram',
        'steps' => [
            'Аккаунт Instagram должен быть переведен в тип <b>Professional/Business</b> и привязан к вашей Странице Facebook.',
            'В <a href="https://developers.facebook.com/tools/explorer/" target="_blank" rel="noopener">Graph API Explorer Meta</a> выберите привязанную Страницу Facebook.',
            'Выполните запрос GET: <code>me/accounts?fields=instagram_business_account</code>. В ответе скопируйте значение <code>instagram_business_account.id</code> — это ваш <b>IG User ID</b>.',
            'Используйте <b>Long-Lived Page Access Token</b> вашей Страницы Facebook — он авторизует публикации и в привязанный Instagram Business аккаунт.',
        ],
    ],
];
?>
<p class="form-hint admin-section-intro">
    Новость ставится в очередь после публикации и отправляется фоновым воркером.
    Здесь настраиваются Facebook, LinkedIn и Instagram; Telegram управляется отдельно.
</p>
<nav class="settings-jump-nav" aria-label="Разделы авто-публикации">
    <a href="#social-facebook"><?= \App\Core\AdminUi::icon('facebook', 16) ?>Facebook</a>
    <a href="#social-linkedin"><?= \App\Core\AdminUi::icon('linkedin', 16) ?>LinkedIn</a>
    <a href="#social-instagram"><?= \App\Core\AdminUi::icon('instagram', 16) ?>Instagram</a>
    <a href="/admin/telegram#telegram-channel"><?= \App\Core\AdminUi::icon('telegram', 16) ?>Telegram</a>
    <a href="#social-queue"><?= \App\Core\AdminUi::icon('performance', 16) ?>Очередь</a>
</nav>

<div class="form-card" id="social-settings">
    <p class="form-hint">
        Включайте сеть только после заполнения обязательных полей. Настройки
        проверяются до сохранения; токены не отображаются повторно.
    </p>
    <form method="post" action="/admin/social" class="form-grid">
        <?= Csrf::field() ?>
        <?php foreach ($config as $net => $c): ?>
            <fieldset class="u-inline-a9961251f2 settings-group social-section" id="social-<?= $net ?>">
                <legend class="u-inline-1204c3c3eb">
                    <?= htmlspecialchars($labels[$net] ?? $net, ENT_QUOTES) ?>
                    <?php if ($c['enabled'] && !$c['ready']): ?>
                        <span class="badge badge--draft">не заполнено</span>
                    <?php elseif ($c['ready']): ?>
                        <span class="badge badge--published">готово</span>
                    <?php endif; ?>
                </legend>
                <div class="form-field form-field--checkbox">
                    <input type="checkbox" id="<?= $net ?>_enabled" name="<?= $net ?>[enabled]" value="1" <?= $c['enabled'] ? 'checked' : '' ?>>
                    <label for="<?= $net ?>_enabled">Публиковать новости в <?= htmlspecialchars($labels[$net] ?? $net, ENT_QUOTES) ?></label>
                </div>
                <?php foreach ($c['fields'] as $field => $value): ?>
                    <div class="form-field">
                        <label for="<?= $net ?>_<?= $field ?>"><?= htmlspecialchars($fieldLabels[$field] ?? $field, ENT_QUOTES) ?></label>
                        <?php if (in_array($field, \App\Core\SocialSettings::TEXTAREA_FIELDS, true)): ?>
                            <textarea class="u-inline-8ff9961267" id="<?= $net ?>_<?= $field ?>" name="<?= $net ?>[<?= $field ?>]" rows="3"
                                      maxlength="2000"
                                      ><?= htmlspecialchars((string) $value, ENT_QUOTES) ?></textarea>
                            <?php if (!empty($signatureHints[$net])): ?>
                                <span class="form-hint"><?= $signatureHints[$net] ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <input type="<?= $field === 'token' ? 'password' : 'text' ?>" id="<?= $net ?>_<?= $field ?>"
                                   name="<?= $net ?>[<?= $field ?>]" value="<?= htmlspecialchars((string) $value, ENT_QUOTES) ?>"
                                   <?= $field === 'token'
                                       ? 'maxlength="10000" placeholder="' . (!empty($c['tokenConfigured']) ? 'Сохранён — оставьте пустым без изменений' : 'Введите Access Token') . '" autocomplete="new-password"'
                                       : 'maxlength="150" autocomplete="off"' ?>
                                   <?= in_array($field, ['page_id', 'user_id'], true)
                                       ? 'inputmode="numeric" pattern="[0-9]{3,30}"'
                                       : '' ?>>
                            <?php if ($field === 'token' && !empty($c['tokenConfigured'])): ?>
                                <label class="form-hint"><input type="checkbox" name="<?= $net ?>[clear_token]" value="1"> Удалить сохранённый токен</label>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if (!empty($tokenGuides[$net])): ?>
                    <details class="u-inline-c2c51f8917">
                        <summary class="u-inline-8c07d779a6">
                            <?= $tokenGuides[$net]['title'] ?>
                        </summary>
                        <ol class="u-inline-8e39c37b7b">
                            <?php foreach ($tokenGuides[$net]['steps'] as $step): ?>
                                <li class="u-inline-6c002e2180"><?= $step ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </details>
                <?php endif; ?>
            </fieldset>
        <?php endforeach; ?>
        <div class="form-actions form-actions--sticky">
            <button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('save') ?>Сохранить настройки соцсетей</button>
        </div>
    </form>

    <?php // Telegram настраивается в своём разделе — вместе с ботом и кодами входа. ?>
    <div class="form-hint u-inline-1456117e38">
        <strong>Telegram-канал</strong>
        <?php if (!empty($telegramReady)): ?>
            <span class="badge badge--published">публикуется</span>
        <?php elseif (!empty($telegramEnabled)): ?>
            <span class="badge badge--danger">включён, но не настроен</span>
        <?php else: ?>
            <span class="badge badge--draft">выключен</span>
        <?php endif; ?>
        <div class="u-inline-2fd7789b39">
            Настраивается в разделе <a href="/admin/telegram">Telegram</a> — там же бот, коды входа
            в панель и проверка прав бота в канале.
        </div>
    </div>
</div>

<?php
/** @var array<int, array<string, mixed>> $queueLog */
/** @var array{pending:int,sent:int,failed:int} $queueCounts */
/** @var array{last:?int,age:?int,stale:bool,expected:int}|null $workerStatus */
$queueLog = $queueLog ?? [];
$queueCounts = $queueCounts ?? ['pending' => 0, 'sent' => 0, 'failed' => 0];
$workerStatus = $workerStatus ?? null;

$fmtAge = static function (?int $sec): string {
    if ($sec === null) {
        return '';
    }
    if ($sec < 60) {
        return $sec . ' с назад';
    }
    if ($sec < 3600) {
        return intdiv($sec, 60) . ' мин назад';
    }
    if ($sec < 86400) {
        return intdiv($sec, 3600) . ' ч назад';
    }

    return intdiv($sec, 86400) . ' дн назад';
};
$statusMap = [
    'sent' => ['success', 'отправлено'],
    'pending' => ['draft', 'в очереди'],
    'failed' => ['danger', 'ошибка'],
];
$appRoot = defined('APP_ROOT') ? APP_ROOT : '/path/to/site';
$cronBroken = $workerStatus !== null && ($workerStatus['last'] === null || !empty($workerStatus['stale']));
?>
<div class="form-card u-inline-3343fd6464 social-section" id="social-queue">
    <div class="u-inline-b741d381f1">
        <div>
            <h2 class="u-inline-291b7bbb01">Очередь публикаций и воркер</h2>
            <p class="form-hint u-inline-1da9facb4d">
                Публикации отправляет фоновый воркер по Cron (каждые ~5 минут). Здесь
                видно, что он делает; кнопкой можно обработать до 5 публикаций
                вручную, не дожидаясь расписания.
            </p>
        </div>
        <form method="post" action="/admin/social/run"
              data-confirm="Отправить сейчас всё, что находится в очереди?">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn--primary">Запустить отправку сейчас</button>
        </form>
    </div>

    <div class="u-inline-1755fec80e">
        <div>
            <div class="form-hint u-inline-d982937dda">Последний запуск воркера</div>
            <?php if ($workerStatus === null || $workerStatus['last'] === null): ?>
                <span class="badge badge--draft">ни разу не запускался</span>
            <?php elseif (!empty($workerStatus['stale'])): ?>
                <span class="badge badge--danger">молчит</span>
                <span class="u-inline-b9071a39d3"><?= htmlspecialchars(date('d.m.Y H:i', (int) $workerStatus['last']), ENT_QUOTES) ?> · <?= $fmtAge($workerStatus['age']) ?></span>
            <?php else: ?>
                <span class="badge badge--success">активен</span>
                <span class="u-inline-b9071a39d3"><?= htmlspecialchars(date('d.m.Y H:i', (int) $workerStatus['last']), ENT_QUOTES) ?> · <?= $fmtAge($workerStatus['age']) ?></span>
            <?php endif; ?>
        </div>
        <div>
            <div class="form-hint u-inline-d982937dda">Очередь</div>
            <span class="badge badge--draft">в очереди: <?= (int) $queueCounts['pending'] ?></span>
            <span class="badge badge--success">отправлено: <?= (int) $queueCounts['sent'] ?></span>
            <span class="badge badge--danger">ошибок: <?= (int) $queueCounts['failed'] ?></span>
        </div>
    </div>

    <?php if ($cronBroken): ?>
        <p class="form-hint u-inline-1138a90be7">
            Похоже, Cron не настроен или воркер остановлен. Добавьте задание на хостинге (каждые 5 минут):<br>
            <code>*/5 * * * * php <?= htmlspecialchars($appRoot, ENT_QUOTES) ?>/app/Console/social_worker.php &gt;&gt; <?= htmlspecialchars($appRoot, ENT_QUOTES) ?>/storage/logs/social_worker.log 2&gt;&amp;1</code><br>
            Пока Cron нет — публикуйте и жмите «Запустить отправку сейчас».
        </p>
    <?php endif; ?>

    <h3 class="u-inline-a8712c9256">Журнал очереди</h3>
    <table class="data-table">
        <thead><tr><th>Новость</th><th>Сеть</th><th>Статус</th><th>Попыток</th><th>Дата</th><th>Ошибка</th><th>Действие</th></tr></thead>
        <tbody>
            <?php if (empty($queueLog)): ?>
                <tr><td colspan="7" class="data-table__empty">Публикаций пока не было.</td></tr>
            <?php endif; ?>
            <?php foreach ($queueLog as $row): ?>
                <?php
                $st = (string) ($row['status'] ?? '');
                [$cls, $label] = $statusMap[$st] ?? ['draft', $st];
                $when = $row['sent_at'] ?? $row['created_at'] ?? null;
                ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($row['news_title'] ?? ('#' . (int) $row['news_id'])), ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars((string) $row['network'], ENT_QUOTES) ?></td>
                    <td><span class="badge badge--<?= $cls ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></span></td>
                    <td><?= (int) ($row['attempts'] ?? 0) ?></td>
                    <td class="u-inline-d442f29d01"><?= $when ? htmlspecialchars((string) $when, ENT_QUOTES) : '—' ?></td>
                    <td class="u-inline-e129d41383"><?= htmlspecialchars((string) ($row['last_error'] ?? ''), ENT_QUOTES) ?></td>
                    <td>
                        <?php if ($st === 'failed'): ?>
                            <form method="post" action="/admin/social/retry">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button type="submit" class="btn btn--small btn--outline">
                                    <?= \App\Core\AdminUi::icon('reset', 14) ?>Повторить
                                </button>
                            </form>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
