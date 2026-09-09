<?php

declare(strict_types=1);

use App\Core\BlockRenderer;
use App\Models\Setting;

test('Форма: чекбокс согласия появляется только при включённой настройке (БД)', function () {
    ensure_test_db();

    // Форма для рендера блока.
    $formId = \App\Models\FormDef::create([
        'name' => 'Тест', 'slug' => 'consent-form-test',
        'fields' => [['name' => 'name', 'label' => 'Имя', 'type' => 'text', 'required' => true]],
        'success_message' => '', 'notify_email' => '',
    ]);
    $form = \App\Models\FormDef::findById($formId);

    $render = static fn (): string => BlockRenderer::render([
        'id' => 501, 'type' => 'form', 'custom_css' => null,
        'data' => json_encode(['form' => $form]),
    ])['html'];

    // Выключено — чекбокса нет.
    Setting::set('form_consent_enabled', '0');
    assert_true(!str_contains($render(), 'name="_consent"'), 'без настройки согласия нет');

    // Включено — чекбокс обязателен и с текстом.
    Setting::set('form_consent_enabled', '1');
    Setting::set('form_consent_text', 'Согласен на обработку ПДн');
    $html = $render();
    assert_contains('name="_consent"', $html);
    assert_true((bool) preg_match('/_consent[^>]*required/', $html), 'чекбокс обязателен');
    assert_contains('Согласен на обработку ПДн', $html);

    Setting::set('form_consent_enabled', '0');
});
