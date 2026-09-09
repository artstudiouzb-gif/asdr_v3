<?php

declare(strict_types=1);

use App\Core\DesignSettings;
use App\Models\Setting;

test('Готовые конфигурации убраны из интерфейса', function () {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/design/index.php');
    // Карточек встроенных наборов в форме больше нет.
    assert_not_contains('$presets as $pkey', $view);
    // Проверяем именно разметку заголовка, а не слова: в комментарии рядом
    // объясняется, почему блок убран.
    assert_not_contains('<h2 class="design-section__title">Готовые конфигурации</h2>', $view);
    // Сохранённые администратором конфигурации остаются: это точка возврата.
    assert_contains('Мои конфигурации', $view);
    assert_contains('/admin/design/preset/save', $view);

    $controller = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/DesignController.php');
    // Контроллер не отдаёт встроенные наборы во вью…
    assert_not_contains("'presets' => DesignSettings::PRESETS", $controller);
    // …и отклоняет их применение, даже если запрос придёт из старой вкладки.
    assert_contains("str_starts_with(\$preset, 'user:')", $controller);
    assert_contains('DesignSettings::autoBackupPreset()', $controller);
});

test('Дизайн: автокопия перед применением конфигурации и возврат (БД)', function () {
    ensure_test_db();
    // Тест пишет настройки дизайна в БД, поэтому начинает с чистого состояния
    // и не зависит от соседей (файлы сортируются строкой: 111_ идёт до 33_).
    reset_design_state();
    $backupPresets = Setting::get('design_user_presets', '');

    Setting::set('design_user_presets', json_encode([]));
    DesignSettings::save(['typo_scale' => 'expressive', 'font_size_custom' => '18']);
    $slug = DesignSettings::saveUserPreset('Рабочая тема');
    assert_true($slug !== null);

    // Настройки испортили, затем применили сохранённую конфигурацию.
    DesignSettings::save(['typo_scale' => 'compact', 'font_size_custom' => '14']);
    assert_same('compact', DesignSettings::typoScale());

    $backup = DesignSettings::autoBackupPreset();
    assert_true($backup !== null);
    assert_contains(DesignSettings::DESIGN_BACKUP_PREFIX, (string) $backup);

    DesignSettings::applyUserPreset((string) $slug);
    assert_same('expressive', DesignSettings::typoScale(), 'конфигурация восстановила шкалу');
    assert_same('18px', DesignSettings::fontSizeCustom());

    // Возврат к состоянию до применения — через автокопию.
    $autoSlug = null;
    foreach (DesignSettings::userPresets() as $s => $p) {
        if (str_starts_with((string) $p['label'], DesignSettings::DESIGN_BACKUP_PREFIX)) {
            $autoSlug = $s;
        }
    }
    assert_true($autoSlug !== null, 'автокопия должна лежать в списке конфигураций');
    DesignSettings::applyUserPreset((string) $autoSlug);
    assert_same('compact', DesignSettings::typoScale(), 'автокопия вернула прежнее состояние');

    Setting::set('design_user_presets', (string) $backupPresets);
    reset_design_state(); // не оставляем своих настроек следующим тестам
});

test('Дизайн: автокопии не забивают лимит конфигураций (БД)', function () {
    ensure_test_db();
    reset_design_state();
    $backupPresets = Setting::get('design_user_presets', '');
    Setting::set('design_user_presets', json_encode([]));

    for ($i = 0; $i < 5; $i++) {
        DesignSettings::autoBackupPreset();
    }
    $auto = array_filter(
        DesignSettings::userPresets(),
        static fn (array $p): bool => str_starts_with((string) $p['label'], DesignSettings::DESIGN_BACKUP_PREFIX)
    );
    assert_true(count($auto) <= 2, 'старые автокопии должны вытесняться, лимит наборов невелик');

    Setting::set('design_user_presets', (string) $backupPresets);
});
