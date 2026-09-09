<?php

declare(strict_types=1);

use App\Core\BlockRenderer;

test('Форма: рендеринг продвинутых типов полей (select, radio, checkbox_group, date, checkbox)', function () {
    ensure_test_db();

    $formId = \App\Models\FormDef::create([
        'name' => 'Анкета вакансии',
        'slug' => 'vacancy-test',
        'fields' => [
            ['name' => 'job', 'label' => 'Должность', 'type' => 'select', 'required' => true, 'options' => 'PHP, QA, PM'],
            ['name' => 'schedule', 'label' => 'График', 'type' => 'radio', 'required' => true, 'options' => 'Офис, Удаленка'],
            ['name' => 'skills', 'label' => 'Навыки', 'type' => 'checkbox_group', 'required' => false, 'options' => 'Git, Docker, CI'],
            ['name' => 'start_date', 'label' => 'Дата начала', 'type' => 'date', 'required' => false],
            ['name' => 'agree_opt', 'label' => 'Доп. соглашение', 'type' => 'checkbox', 'required' => false],
        ],
        'success_message' => '',
        'notify_email' => '',
    ]);

    $form = \App\Models\FormDef::findById($formId);

    $render = BlockRenderer::render([
        'id' => 502,
        'type' => 'form',
        'custom_css' => null,
        'data' => json_encode(['form' => $form]),
    ])['html'];

    // Проверяем select
    assert_contains('name="job"', $render);
    assert_contains('<option value="PHP">PHP</option>', $render);
    assert_contains('<option value="QA">QA</option>', $render);
    assert_contains('<option value="PM">PM</option>', $render);

    // Проверяем radio
    assert_contains('name="schedule"', $render);
    assert_contains('type="radio"', $render);
    assert_contains('value="Офис"', $render);
    assert_contains('value="Удаленка"', $render);

    // Проверяем checkbox_group
    assert_contains('name="skills[]"', $render);
    assert_contains('value="Git"', $render);
    assert_contains('value="Docker"', $render);

    // Проверяем date
    assert_contains('name="start_date"', $render);
    assert_contains('type="date"', $render);

    // Проверяем checkbox (одиночный)
    assert_contains('name="agree_opt"', $render);
    assert_contains('type="checkbox"', $render);
});
