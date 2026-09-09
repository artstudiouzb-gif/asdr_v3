<?php

use App\Core\AdminUi;
use App\Core\Csrf;

$pageTitle = 'Telegram';
$activeNav = 'telegram';
require __DIR__ . '/../layout/header.php';

/** @var bool $botConfigured */
/** @var string $botUsername */
/** @var string $botVerifiedAt */
/** @var bool $botOk */
/** @var bool $linked */
/** @var int $myChatId */
/** @var string|null $linkCode */
/** @var array<string,string> $channel */
/** @var list<array{id:int,title:string,type:string}> $detectedChannels */
/** @var bool $channelOwnTokenConfigured */
/** @var bool $channelEnabled */
/** @var string $notifyChatIds */
/** @var bool $gatewayConfigured */
/** @var bool $setupRestricted */

$channelReady = \App\Core\SocialSettings::isReady('telegram');
$notifyCount = count(\App\Core\FormNotifier::parseChatIds($notifyChatIds));
$detectedChannels = is_array($detectedChannels ?? null) ? array_values($detectedChannels) : [];
$detectedChatId = count($detectedChannels) === 1
    ? (string) $detectedChannels[0]['id']
    : '';

// Значок шага через единый локальный набор Tabler Icons.
$mark = static function (bool $done, bool $started = true): string {
    if (!$started) {
        return '<span class="badge badge--draft">' . AdminUi::icon('info', 12) . ' не настроено</span>';
    }

    return $done
        ? '<span class="badge badge--published">' . AdminUi::icon('check', 12) . ' готово</span>'
        : '<span class="badge badge--danger">' . AdminUi::icon('warning', 12) . ' требует внимания</span>';
};
?>
<p class="form-hint admin-section-intro">
    Единая настройка Telegram: сначала подключите бота, затем привяжите администратора,
    укажите канал публикаций и получателей заявок.
</p>
<nav class="settings-jump-nav" aria-label="Шаги настройки Telegram">
    <a href="#telegram-bot"><?= AdminUi::icon('telegram', 16) ?>1. Бот</a>
    <a href="#telegram-link"><?= AdminUi::icon('lock', 16) ?>2. Двухфакторка</a>
    <a href="#telegram-channel"><?= AdminUi::icon('send', 16) ?>3. Канал</a>
    <a href="#telegram-extras"><?= AdminUi::icon('bell', 16) ?>4. Уведомления</a>
</nav>

<?php // ── Верхняя сводная панель статусов Telegram с Tabler Icons ───────── ?>
<div class="tg-summary-grid">
    <div class="tg-summary-card">
        <div class="tg-summary-card__header">
            <span class="tg-summary-card__title"><?= AdminUi::icon('telegram', 16) ?> Telegram Бот</span>
            <?= $mark($botOk, $botConfigured) ?>
        </div>
        <div class="tg-summary-card__value">
            <?php if ($botOk && $botUsername !== ''): ?>
                <span>@<?= htmlspecialchars($botUsername, ENT_QUOTES) ?></span>
            <?php elseif ($botConfigured): ?>
                <span class="tg-status-pending">Токен сохранён — требуется проверка</span>
            <?php else: ?>
                <span class="u-inline-594b8be61b">Не подключен</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="tg-summary-card">
        <div class="tg-summary-card__header">
            <span class="tg-summary-card__title"><?= AdminUi::icon('lock', 16) ?> 2FA Вход</span>
            <?= $mark($linked, $botConfigured) ?>
        </div>
        <div class="tg-summary-card__value">
            <?php if ($linked): ?>
                <span>Привязан <small class="u-inline-8001b29eb4">(Chat ID: <?= (int) $myChatId ?>)</small></span>
            <?php else: ?>
                <span class="u-inline-594b8be61b">Не привязан</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="tg-summary-card">
        <div class="tg-summary-card__header">
            <span class="tg-summary-card__title"><?= AdminUi::icon('send', 16) ?> Авто-публикация</span>
            <?= $mark($channelReady, $channelEnabled) ?>
        </div>
        <div class="tg-summary-card__value">
            <?php if ($channelReady): ?>
                <span><?= htmlspecialchars((string) ($channel['chat_id'] ?? ''), ENT_QUOTES) ?></span>
            <?php else: ?>
                <span class="u-inline-594b8be61b">Отключено</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="tg-summary-card">
        <div class="tg-summary-card__header">
            <span class="tg-summary-card__title"><?= AdminUi::icon('email', 16) ?> Заявки с сайта</span>
            <?= $mark($notifyCount > 0, $notifyChatIds !== '') ?>
        </div>
        <div class="tg-summary-card__value">
            <?php if ($notifyCount > 0): ?>
                <span><?= $notifyCount ?> получател<?= $notifyCount === 1 ? 'ь' : ($notifyCount < 5 ? 'я' : 'ей') ?></span>
            <?php else: ?>
                <span class="u-inline-594b8be61b">Выключены</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php // ── Шаг 1. Бот ───────────────────────────────────────────────────── ?>
<div class="form-card tg-step" id="telegram-bot">
    <h2 class="u-inline-8981e56111">
        <?= AdminUi::icon('telegram', 20) ?> 1. Настройка Telegram-бота <?= $mark($botOk, $botConfigured) ?>
    </h2>
    <p class="form-hint">
        Создайте бота у <strong>@BotFather</strong> (команда <code>/newbot</code>) и вставьте токен.
        Токен всегда можно посмотреть в Telegram: <code>/mybots</code> → ваш бот → <em>API Token</em>.
    </p>
    <form method="post" action="/admin/telegram/bot" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="telegram_bot_token">Токен бота (Bot API Token)</label>
            <input type="password" id="telegram_bot_token" name="telegram_bot_token"
                   value=""
                   maxlength="256"
                   placeholder="<?= $botConfigured ? 'Сохранён — оставьте пустым без изменений' : '1234567890:AAH…' ?>"
                   autocomplete="new-password" spellcheck="false">
            <span class="form-hint">
                Формат: цифры, двоеточие, ключ. Без слова «bot» в начале. Этот токен используется для кодов входа и публикаций.
            </span>
            <?php if ($botConfigured && !$setupRestricted): ?>
                <label class="form-hint tg-danger-check">
                    <input type="checkbox" name="clear_telegram_bot_token" value="1" data-tg-clear-token>
                    Удалить сохранённый токен
                </label>
                <div class="tg-clear-confirm" data-tg-clear-confirm hidden>
                    <label for="confirm_clear_bot">Введите <code>REMOVE</code>, чтобы подтвердить удаление</label>
                    <input type="text" id="confirm_clear_bot" name="confirm_clear_bot" maxlength="6"
                           autocomplete="off" autocapitalize="characters" spellcheck="false">
                    <span class="form-hint">Удаление остановит доставку кодов входа всем привязанным администраторам.</span>
                </div>
            <?php endif; ?>
        </div>
        <div class="form-actions u-inline-df20dd0984">
            <button type="submit" class="btn btn--primary"><?= AdminUi::icon('save') ?>Сохранить токен</button>
            <button type="submit" formaction="/admin/telegram/bot/check" class="btn btn--outline">
                <?= AdminUi::icon('check') ?>Проверить бота
            </button>
        </div>
        <?php if ($botOk && $botVerifiedAt !== ''): ?>
            <span class="form-hint">Последняя успешная проверка: <?= htmlspecialchars($botVerifiedAt, ENT_QUOTES) ?></span>
        <?php endif; ?>
    </form>
</div>

<?php // ── Шаг 2. Привязка администратора ───────────────────────────────── ?>
<div class="form-card u-inline-9eb125f52f tg-step" id="telegram-link">
    <h2 class="u-inline-8981e56111">
        <?= AdminUi::icon('lock', 20) ?> 2. Коды входа и двухфакторка (2FA) <?= $mark($linked, $botConfigured) ?>
    </h2>
    <?php if (!$botConfigured): ?>
        <p class="form-hint">Сначала сохраните токен бота в шаге 1.</p>
    <?php elseif ($linked): ?>
        <p class="form-hint">
            Ваш аккаунт привязан, коды входа приходят в Telegram от бота.
            Ваш <code>chat_id</code>: <strong><?= (int) $myChatId ?></strong> — он пригодится для получения уведомлений о заявках с сайта.
        </p>
    <?php else: ?>
        <p class="form-hint">Инструкция по привязке аккаунта:</p>
        <ol class="form-hint u-inline-f60258483c">
            <li>Откройте бота
                <?php if ($botUsername !== ''): ?>
                    <a href="https://t.me/<?= htmlspecialchars($botUsername, ENT_QUOTES) ?>" target="_blank" rel="noopener"><strong>@<?= htmlspecialchars($botUsername, ENT_QUOTES) ?></strong></a>
                <?php else: ?>
                    в Telegram
                <?php endif; ?>
                и нажмите «Start».
            </li>
            <li>Отправьте боту код привязки ниже:</li>
        </ol>

        <div class="tg-code-box">
            <div>
                <div class="u-inline-afaed7b4de">Код привязки</div>
                <div class="tg-code-val" id="tg_link_code_val"><?= htmlspecialchars((string) $linkCode, ENT_QUOTES) ?></div>
            </div>
            <button type="button" class="btn btn--small btn--outline" data-tg-copy-code>
                <?= AdminUi::icon('copy') ?>Скопировать код
            </button>
        </div>

        <form method="post" action="/admin/telegram/link">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn--primary"><?= AdminUi::icon('check') ?>Проверить привязку аккаунта</button>
        </form>
    <?php endif; ?>
</div>

<?php // ── Шаг 3. Канал ─────────────────────────────────────────────────── ?>
<div class="form-card u-inline-9eb125f52f tg-step" id="telegram-channel">
    <?php
    $chatIdVal = trim((string) ($channel['chat_id'] ?? ''));
    $channelBadge = '';
    if ($chatIdVal === '') {
        $channelBadge = '<span class="badge badge--draft">' . AdminUi::icon('info', 12) . ' не настроено</span>';
    } elseif ($channelEnabled) {
        $channelBadge = '<span class="badge badge--published">' . AdminUi::icon('check', 12) . ' включено (' . htmlspecialchars($chatIdVal, ENT_QUOTES) . ')</span>';
    } else {
        $channelBadge = '<span class="badge badge--warning">' . AdminUi::icon('pause', 12) . ' сохранен (' . htmlspecialchars($chatIdVal, ENT_QUOTES) . '), выключен</span>';
    }
    ?>
    <h2 class="u-inline-8981e56111">
        <?= AdminUi::icon('send', 20) ?> 3. Публикация новостей в Telegram-канал <?= $channelBadge ?>
    </h2>
    <p class="form-hint">
        Бот должен быть <strong>администратором канала</strong> с правом «Публикация сообщений».
        Посты в канале отображаются от имени канала.
    </p>
    <form method="post" action="/admin/telegram/channel" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="tg_enabled" name="enabled" value="1" <?= $channelEnabled ? 'checked' : '' ?>>
            <label for="tg_enabled">Включить авто-публикацию новостей в канал</label>
        </div>
        <div class="form-field">
            <label for="tg_chat_id">Канал</label>
            <input type="text" id="tg_chat_id" name="chat_id"
                   value="<?= htmlspecialchars($detectedChatId !== '' ? $detectedChatId : (string) ($channel['chat_id'] ?? ''), ENT_QUOTES) ?>"
                   maxlength="40"
                   placeholder="@имя_канала или -1001234567890" autocomplete="off" spellcheck="false">
            <?php if ($detectedChatId !== ''): ?>
                <span class="form-hint">
                    ID найденного канала уже вставлен. Проверьте его и нажмите «Сохранить канал».
                </span>
            <?php elseif (count($detectedChannels) > 1): ?>
                <div class="form-hint" data-tg-detected-channels>
                    <strong>Найдено несколько каналов — выберите нужный:</strong>
                    <div class="form-actions">
                        <?php foreach ($detectedChannels as $detectedChannel): ?>
                            <?php // id канала — число (у приватного отрицательное), и «нет id»
                                  // это ноль, а не пустая строка: прежняя проверка на ''
                                  // не срабатывала никогда и пропускала кнопку с id «0». ?>
                            <?php $candidateId = (int) $detectedChannel['id']; ?>
                            <?php if ($candidateId === 0) { continue; } ?>
                            <?php $candidateId = (string) $candidateId; ?>
                            <button type="button" class="btn btn--small btn--outline"
                                    aria-pressed="false"
                                    data-tg-use-channel-id="<?= htmlspecialchars($candidateId, ENT_QUOTES) ?>">
                                <?= htmlspecialchars($detectedChannel['title'] !== '' ? $detectedChannel['title'] : $candidateId, ENT_QUOTES) ?>
                                <code><?= htmlspecialchars($candidateId, ENT_QUOTES) ?></code>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <span class="form-hint">
                Публичный канал — <code>@имя_канала</code>, приватный — <code>-100…</code>.
                У закрытого канала имени <code>@…</code> не существует: добавьте бота администратором,
                напишите в канал любое сообщение и нажмите «Определить ID канала».
            </span>
        </div>

        <div class="form-field">
            <label for="tg_format">Формат поста</label>
            <?php $fmt = (string) ($channel['format'] ?? 'auto'); ?>
            <select id="tg_format" name="format">
                <option value="auto" <?= $fmt === 'auto' ? 'selected' : '' ?>>Расширенный, с откатом на обычный (рекомендуется)</option>
                <option value="rich" <?= $fmt === 'rich' ? 'selected' : '' ?>>Только расширенный</option>
                <option value="classic" <?= $fmt === 'classic' ? 'selected' : '' ?>>Только обычный (фото с подписью)</option>
            </select>
            <span class="form-hint">
                Расширенный формат (Bot API 10.1+) снимает лимит подписи в 1024 символа: текст уходит целиком,
                обе языковые версии — в одном посте, галерея — слайд-шоу. Если Telegram его не примет,
                вариант «с откатом» опубликует пост прежним способом.
            </span>
        </div>

        <div class="form-field">
            <label for="tg_second_lang">Вторая языковая версия</label>
            <?php $second = (string) ($channel['second_lang'] ?? '') === 'details' ? 'details' : 'inline'; ?>
            <select id="tg_second_lang" name="second_lang">
                <option value="inline" <?= $second === 'inline' ? 'selected' : '' ?>>Подряд в тексте поста</option>
                <option value="details" <?= $second === 'details' ? 'selected' : '' ?>>Свёрнуто, под «развернуть»</option>
            </select>
            <span class="form-hint">
                Перевод из вкладки языка в самой новости уходит тем же постом, после разделителя.
                Фотографии общие — они берутся из основной новости.
            </span>
        </div>

        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="tg_silent" name="silent" value="1" <?= !empty($channel['silent']) ? 'checked' : '' ?>>
            <label for="tg_silent">Публиковать без звука</label>
        </div>
        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="tg_buttons" name="buttons" value="1" <?= (string) ($channel['buttons'] ?? '') === '1' ? 'checked' : '' ?>>
            <label for="tg_buttons">Кнопки со ссылкой под постом</label>
            <span class="form-hint">
                По умолчанию выключены: ссылка на каждую версию уже стоит в тексте своего блока,
                а при двух языках кнопок становится две. К альбому Telegram кнопку не принимает —
                в обычном формате они появлялись через раз.
            </span>
        </div>

        <div class="form-field">
            <label for="tg_signature">Подпись под постом (необязательно)</label>
            
            <?php // Интерактивная панель вставки HTML-тегов для Telegram ?>
            <div class="tg-toolbar">
                <span class="tg-toolbar__title">Вставить HTML-тег:</span>
                <button type="button" class="tg-tag-btn" data-tg-tag-start="&lt;b&gt;" data-tg-tag-end="&lt;/b&gt;"><b>B</b> Жирный</button>
                <button type="button" class="tg-tag-btn" data-tg-tag-start="&lt;i&gt;" data-tg-tag-end="&lt;/i&gt;"><i>I</i> Курсив</button>
                <button type="button" class="tg-tag-btn" data-tg-tag-start="&lt;code&gt;" data-tg-tag-end="&lt;/code&gt;"><?= AdminUi::icon('code', 13) ?> <code>Код</code></button>
                <button type="button" class="tg-tag-btn" data-tg-tag-start="&lt;blockquote&gt;" data-tg-tag-end="&lt;/blockquote&gt;"><?= \App\Core\AdminUi::icon('message', 15) ?> Цитата</button>
                <button type="button" class="tg-tag-btn" data-tg-tag-start="&lt;tg-spoiler&gt;" data-tg-tag-end="&lt;/tg-spoiler&gt;"><?= \App\Core\AdminUi::icon('eye', 15) ?> Спойлер</button>
                <button type="button" class="tg-tag-btn" data-tg-tag-start="&lt;a href=&quot;https://example.com&quot;&gt;" data-tg-tag-end="&lt;/a&gt;"><?= AdminUi::icon('external', 13) ?> Ссылка</button>
            </div>

            <textarea class="u-inline-8ff9961267" id="tg_signature" name="signature" rows="3"
                      data-tg-signature maxlength="2000"><?= htmlspecialchars((string) ($channel['signature'] ?? ''), ENT_QUOTES) ?></textarea>
            <span class="form-hint">
                Поддерживаются HTML-теги Telegram: <code>&lt;b&gt;</code>, <code>&lt;i&gt;</code>, <code>&lt;code&gt;</code> (автокопирование по клику), <code>&lt;blockquote&gt;</code>, <code>&lt;tg-spoiler&gt;</code>, <code>&lt;a href="..."&gt;</code>.
                Видимый текст — не более 500 символов. <span data-tg-signature-count></span>
            </span>
        </div>

        <details class="form-section">
            <summary>Отдельный бот для публикаций <span class="form-section__hint">(опционально)</span></summary>
            <div class="form-section__body">
                <div class="form-field">
                    <label for="tg_own_token">Токен бота-публикатора</label>
                    <input type="password" id="tg_own_token" name="own_token"
                           value=""
                           maxlength="256"
                           placeholder="<?= $channelOwnTokenConfigured ? 'Сохранён — оставьте пустым без изменений' : 'Пусто — использовать основного бота' ?>"
                           autocomplete="new-password" spellcheck="false">
                    <span class="form-hint">
                        Если не заполнено — публикации отправляются основным ботом.
                    </span>
                    <?php if ($channelOwnTokenConfigured): ?>
                        <label class="form-hint"><input type="checkbox" name="clear_own_token" value="1"> Удалить отдельный токен</label>
                    <?php endif; ?>
                </div>
            </div>
        </details>

        <div class="form-actions u-inline-df20dd0984">
            <button type="submit" class="btn btn--primary"><?= AdminUi::icon('save') ?>Сохранить канал</button>
            <button type="submit" formaction="/admin/telegram/channel/check" class="btn btn--outline">
                <?= AdminUi::icon('check') ?>Проверить канал и права бота
            </button>
            <button type="submit" formaction="/admin/telegram/channel/detect" class="btn btn--outline" formnovalidate>
                <?= AdminUi::icon('search') ?>Определить ID канала
            </button>
        </div>
    </form>
</div>

<?php // ── Шаг 4. Дополнительно (Заявки и Gateway) ───────────────────────── ?>
<div class="form-card u-inline-d8a0156797 tg-step" id="telegram-extras">
    <h2 class="u-inline-8981e56111">
        <?= AdminUi::icon('settings', 20) ?> 4. Уведомления о заявках с сайта и Telegram Gateway
    </h2>
    <?php // enctype нужен для загрузки обложки сводки прямо из формы. ?>
    <form method="post" action="/admin/telegram/extras" class="form-grid" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="telegram_notify_chat_ids">Уведомления о заявках с форм: chat_id получателей</label>
            <div class="u-inline-78cead6503">
                <input class="u-inline-7623f05545" type="text" id="telegram_notify_chat_ids" name="telegram_notify_chat_ids"
                       value="<?= htmlspecialchars($notifyChatIds, ENT_QUOTES) ?>"
                       maxlength="5000"
                       placeholder="123456789, -1001234567890" autocomplete="off" spellcheck="false">
                <?php if ($linked && $myChatId > 0): ?>
                    <button type="button" class="btn btn--small btn--outline" data-tg-add-chat-id="<?= (int) $myChatId ?>" title="Добавить мой Chat ID">
                        + Добавить мой ID (<?= (int) $myChatId ?>)
                    </button>
                <?php endif; ?>
            </div>
            <span class="form-hint">
                Сообщения о новых заявках с сайта приходят на указанные chat_id через запятую. Укажите отрицательный ID для группы.
            </span>
        </div>

        <div class="form-field">
            <label for="telegram_gateway_token">Токен Telegram Gateway API (платный резервный SMS-сервис)</label>
            <input type="password" id="telegram_gateway_token" name="telegram_gateway_token"
                   value=""
                   maxlength="10000"
                   placeholder="<?= $gatewayConfigured ? 'Сохранён — оставьте пустым без изменений' : 'Введите токен Gateway' ?>"
                   autocomplete="new-password" spellcheck="false">
            <?php if ($gatewayConfigured): ?>
                <label class="form-hint"><input type="checkbox" name="clear_telegram_gateway_token" value="1"> Удалить токен Gateway</label>
            <?php endif; ?>
            <span class="form-hint">
                Служба <code>gateway.telegram.org</code> (для отправки кодов входа на телефоны администраторов без привязки бота).
            </span>
        </div>

        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="tg_roundup" name="telegram_roundup" value="1"
                   <?= \App\Core\WeeklyRoundup::isEnabled() ? 'checked' : '' ?>>
            <label for="tg_roundup">Готовить итоги недели</label>
            <span class="form-hint">
                Сводка новостей за семь дней со ссылками, по разделу на каждый язык.
                Отправляется вручную — кнопкой ниже, когда пост готов.
            </span>
        </div>

        <?= \App\Core\AdminUi::imageField('telegram_roundup_image', (string) \App\Models\Setting::get(\App\Core\WeeklyRoundup::COVER_KEY, ''), [
            'label' => 'Обложка сводки (необязательно)',
            'file' => 'telegram_roundup_image_file',
            'hint' => 'Коллаж или заставка над списком. Пусто — возьмём общую картинку для соцсетей; нет и её — пост уйдёт без изображения.',
        ]) ?>

        <div class="form-field">
            <?php $roundupItems = array_sum(array_map('count', \App\Core\WeeklyRoundup::collect())); ?>
            <span class="form-hint">
                <?php if ($roundupItems > 0): ?>
                    Сейчас в сводку попало бы <strong><?= (int) $roundupItems ?></strong> материал(ов) за последние семь дней.
                <?php else: ?>
                    За последние семь дней новостей нет — отправлять нечего.
                <?php endif; ?>
                Сначала сохраните настройки, если меняли обложку.
            </span>
        </div>

        <div class="form-field">
            <label>Сторож тишины</label>
            <?php
            $watch = \App\Core\Watchdog::check();
            $appRootPath = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 4);
            ?>
            <?php if ($watch === []): ?>
                <span class="tg-status-ok">Сейчас всё в порядке: воркеры отвечают, очереди разбираются, место на диске есть.</span>
            <?php else: ?>
                <ul class="tg-watchdog-list">
                    <?php foreach ($watch as $line): ?>
                        <li><?= htmlspecialchars($line, ENT_QUOTES) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <span class="form-hint">
                Сообщает о том, что остановилось без ошибки: cron перестал вызывать воркер, очередь публикаций
                стоит, кончается место. Пишет на те же chat_id — один раз на проблему и отдельно, когда она ушла.
                Добавьте задание в Cron:<br>
                <code>*/15 * * * * php <?= htmlspecialchars($appRootPath, ENT_QUOTES) ?>/app/Console/watchdog.php &gt;&gt; <?= htmlspecialchars($appRootPath, ENT_QUOTES) ?>/storage/logs/watchdog.log 2&gt;&amp;1</code>
            </span>
        </div>

        <div class="form-actions form-actions--sticky">
            <button type="submit" class="btn btn--primary"><?= AdminUi::icon('save') ?>Сохранить настройки Telegram</button>
            <button type="submit" formaction="/admin/telegram/extras/check" class="btn btn--outline">
                <?= AdminUi::icon('send') ?>Отправить тест
            </button>
            <?php // Сводка уходит по кнопке, а не по расписанию: момент выбирает редактор. ?>
            <button type="submit" formaction="/admin/telegram/roundup/send" class="btn btn--outline" formnovalidate
                    data-confirm="Отправить итоги недели в канал прямо сейчас?">
                <?= AdminUi::icon('calendar') ?>Отправить итоги недели
            </button>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
