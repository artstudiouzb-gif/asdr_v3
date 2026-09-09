<?php

use App\Core\Csrf;
use App\Models\Language;

$isEdit = !empty($member['id']);
$pageTitle = $isEdit ? 'Редактирование сотрудника' : 'Новый сотрудник';
$activeNav = 'team';
require __DIR__ . '/../layout/header.php';

/** @var array|null $member */
/** @var array $translations */
/** @var string|null $error */

$action = $isEdit ? '/admin/team/' . (int) $member['id'] . '/edit' : '/admin/team/create';
$socials = $member['socials'] ?? [];
$defaultCode = Language::defaultCode();
$translationLangs = array_values(array_filter(
    Language::active(),
    static fn (array $l): bool => (string) $l['code'] !== $defaultCode
));
?>
<?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>

<form method="post" action="<?= $action ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>

    <div class="entry-grid">
        <div class="entry-main">
            <!-- Блок 1: Основная информация сотрудника -->
            <div class="form-card u-inline-8a43589152">
                <?= \App\Core\AdminUi::cardHeader('1. Основная информация о сотруднике', 'users', 'var(--admin-primary,#2563eb)') ?>

                <div class="form-field u-inline-79a1c5a5db">
                    <label class="u-inline-e925a44577">ФИО / Имя сотрудника <span class="u-inline-9dd1207e58">*</span></label>
                    <input class="u-inline-bc64d2d1e3" type="text" id="name" name="name" value="<?= htmlspecialchars($member['name'] ?? '', ENT_QUOTES) ?>" required placeholder="например: Алишер Каримов">
                </div>

                <div class="form-field u-inline-79a1c5a5db">
                    <label class="u-inline-e925a44577">Должность (на основном языке)</label>
                    <input type="text" id="position" name="position" value="<?= htmlspecialchars($member['position'] ?? '', ENT_QUOTES) ?>" placeholder="например: Главный специалист / Руководитель отдела">
                </div>

                <div class="form-field u-inline-79a1c5a5db">
                    <label class="u-inline-e925a44577">Сектор (на основном языке)</label>
                    <input type="text" id="department" name="department" list="team-departments" value="<?= htmlspecialchars($member['department'] ?? '', ENT_QUOTES) ?>" placeholder="например: Сектор анализа и исследований">
                    <datalist id="team-departments">
                        <?php foreach (($departments ?? []) as $dep): ?>
                            <option value="<?= htmlspecialchars((string) $dep['name'], ENT_QUOTES) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <span class="form-hint">Верхний уровень группировки команды. Из схемы оргструктуры на сектор можно сослаться якорем.</span>
                </div>

                <div class="form-field u-inline-79a1c5a5db">
                    <label class="u-inline-e925a44577">Отдел или группа внутри сектора (необязательно)</label>
                    <input type="text" id="unit" name="unit" value="<?= htmlspecialchars($member['unit'] ?? '', ENT_QUOTES) ?>" placeholder="например: первый отдел / группа по работе с кадрами">
                </div>

                <div class="u-inline-8a359a76eb">
                    <?= \App\Core\AdminUi::imageField('photo_url', $member['photo'] ?? '', [
                        'label' => 'Фотография сотрудника',
                        'file' => 'photo_file',
                        'hint' => 'Рекомендуемый размер: портрет 400x500px.',
                    ]) ?>
                </div>
            </div>

            <!-- Блок 2: Контакты и социальные сети -->
            <div class="form-card u-inline-8a43589152">
                <?= \App\Core\AdminUi::cardHeader('2. Контакты и социальные сети', 'send', '#0d9488') ?>

                <div class="form-grid-2col u-inline-df9c7b5690">
                    <div class="form-field">
                        <label class="u-inline-0b87e9e0af">Электронная почта (Email)</label>
                        <input type="email" id="email" name="email" value="<?= htmlspecialchars($member['email'] ?? '', ENT_QUOTES) ?>" placeholder="karimov@organization.uz">
                    </div>
                    <div class="form-field">
                        <label class="u-inline-0b87e9e0af">Телефон</label>
                        <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($member['phone'] ?? '', ENT_QUOTES) ?>" placeholder="+998 90 123-45-67">
                    </div>
                </div>

                <div class="form-grid-2col u-inline-001013efee">
                    <?php foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'telegram' => 'Telegram', 'linkedin' => 'LinkedIn', 'whatsapp' => 'WhatsApp'] as $key => $label): ?>
                        <div class="form-field">
                            <label class="u-inline-0b87e9e0af"><?= $label ?></label>
                            <input type="text" id="social_<?= $key ?>" name="social_<?= $key ?>" value="<?= htmlspecialchars($socials[$key] ?? '', ENT_QUOTES) ?>" placeholder="Ссылка или username">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Блок 3: Переводы на другие языки сайта -->
            <?php if (!empty($translationLangs)): ?>
                <div class="form-card u-inline-8a43589152">
                    <?= \App\Core\AdminUi::cardHeader('3. Переводы на другие языки', 'globe', 'var(--admin-violet)') ?>

                    <?php foreach ($translationLangs as $lang): ?>
                        <?php
                        $code = (string) $lang['code'];
                        $tr = $translations[$code] ?? [];
                        ?>
                        <div class="u-inline-d31dcf37c0">
                            <h4 class="u-inline-c35d85373e">
                                <?= \App\Core\AdminUi::icon('globe') ?> <?= htmlspecialchars($lang['name'], ENT_QUOTES) ?> (<?= strtoupper($code) ?>)
                            </h4>
                            <div class="form-grid-2col u-inline-24981bd547">
                                <div class="form-field">
                                    <label class="u-inline-0b87e9e0af">ФИО / Имя (<?= strtoupper($code) ?>)</label>
                                    <input type="text" name="translations[<?= $code ?>][name]" value="<?= htmlspecialchars($tr['name'] ?? '', ENT_QUOTES) ?>" placeholder="Перевод имени на <?= htmlspecialchars($lang['name'], ENT_QUOTES) ?>">
                                </div>
                                <div class="form-field">
                                    <label class="u-inline-0b87e9e0af">Должность (<?= strtoupper($code) ?>)</label>
                                    <input type="text" name="translations[<?= $code ?>][position]" value="<?= htmlspecialchars($tr['position'] ?? '', ENT_QUOTES) ?>" placeholder="Перевод должности на <?= htmlspecialchars($lang['name'], ENT_QUOTES) ?>">
                                </div>
                                <div class="form-field">
                                    <label class="u-inline-0b87e9e0af">Сектор (<?= strtoupper($code) ?>)</label>
                                    <input type="text" name="translations[<?= $code ?>][department]" value="<?= htmlspecialchars($tr['department'] ?? '', ENT_QUOTES) ?>" placeholder="Перевод названия сектора на <?= htmlspecialchars($lang['name'], ENT_QUOTES) ?>">
                                </div>
                                <div class="form-field">
                                    <label class="u-inline-0b87e9e0af">Отдел / группа (<?= strtoupper($code) ?>)</label>
                                    <input type="text" name="translations[<?= $code ?>][unit]" value="<?= htmlspecialchars($tr['unit'] ?? '', ENT_QUOTES) ?>" placeholder="Перевод названия отдела на <?= htmlspecialchars($lang['name'], ENT_QUOTES) ?>">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Правая колонка параметров публикации -->
        <aside class="entry-side">
            <div class="form-card u-inline-c1563b7411">
                <h3 class="u-inline-3e8ce2fc5a">Параметры публикации</h3>
                <div class="form-grid">
                    <div class="form-field">
                        <label class="u-inline-0b87e9e0af" for="status">Статус</label>
                        <select id="status" name="status">
                            <option value="published" <?= ($member['status'] ?? 'published') === 'published' ? 'selected' : '' ?>>Опубликовано</option>
                            <option value="draft" <?= ($member['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Черновик</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label class="u-inline-0b87e9e0af" for="sort_order">Порядок сортировки</label>
                        <input type="number" id="sort_order" name="sort_order" value="<?= (int) ($member['sort_order'] ?? 0) ?>">
                        <span class="form-hint">Чем меньше число, тем выше в списке.</span>
                    </div>
                </div>

                <div class="form-actions form-actions--sticky u-inline-e7673d9ced">
                    <button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('save') ?>Сохранить</button>
                    <a href="/admin/team" class="btn">Отмена</a>
                </div>
            </div>
        </aside>
    </div>
</form>
<?php require __DIR__ . '/../layout/footer.php'; ?>