<?php

use App\Core\Csrf;

/** @var string|null $error */
/** @var array $languages */
/** @var array $timezones */
$step = '3';
require __DIR__ . '/_header.php';
?>
<div class="install-header">
    <h1 class="install-header__title">Настройки веб-портала</h1>
    <p class="install-header__desc">Укажите основную информацию о вашей организации и языковые параметры.</p>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert--error u-inline-d7a4b27f07">
        <?= htmlspecialchars($error, ENT_QUOTES) ?>
    </div>
<?php endif; ?>

<form method="post" action="/install/step3" class="form-grid">
    <?= Csrf::field() ?>

    <div class="form-field">
        <label class="u-inline-aa1820469b" for="site_name">Название организации / портала <span class="u-inline-9dd1207e58">*</span></label>
        <div class="install-input-wrap">
            <?= \App\Core\Icon::render('building-bank', 18, 'install-input-icon') ?>
            <input class="u-inline-1cef6a84e1" type="text" id="site_name" name="site_name" value="Агентство стратегического развития и реформ" required>
        </div>
    </div>

    <div class="form-field u-inline-9eb125f52f">
        <label class="u-inline-aa1820469b" for="site_description">Краткое описание (SEO мета-описание)</label>
        <div class="install-input-wrap">
            <?= \App\Core\Icon::render('file-description', 18, 'install-input-icon u-inline-3c3e97944f') ?>
            <textarea id="site_description" name="site_description" rows="2" placeholder="Официальный веб-портал государственного органа"></textarea>
        </div>
    </div>

    <div class="form-grid-2col u-inline-e6e3fab9a5">
        <div class="form-field">
            <label class="u-inline-aa1820469b" for="timezone">Часовой пояс</label>
            <div class="install-input-wrap">
                <?= \App\Core\Icon::render('clock', 18, 'install-input-icon') ?>
                <select id="timezone" name="timezone">
                    <?php foreach ($timezones as $tz): ?>
                        <option value="<?= htmlspecialchars($tz, ENT_QUOTES) ?>" <?= $tz === 'Asia/Tashkent' ? 'selected' : '' ?>><?= htmlspecialchars($tz, ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-field">
            <label class="u-inline-aa1820469b" for="default_language">Основной язык сайта</label>
            <div class="install-input-wrap">
                <?= \App\Core\Icon::render('world', 18, 'install-input-icon') ?>
                <select id="default_language" name="default_language">
                    <?php foreach ($languages as $lang): ?>
                        <option value="<?= htmlspecialchars($lang['code'], ENT_QUOTES) ?>" <?= $lang['is_default'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($lang['name'], ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn--primary">
            Сохранить и продолжить
            <?= \App\Core\Icon::render('chevron-right', 18, 'btn__icon', 2.5) ?>
        </button>
    </div>
</form>
<?php require __DIR__ . '/_footer.php'; ?>
