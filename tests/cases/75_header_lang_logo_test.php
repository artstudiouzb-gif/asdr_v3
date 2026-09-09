<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\HeaderConfig;

// Логотип шапки для каждого языка: нормализация карты «код => URL».

test('HeaderConfig: logo_by_lang нормализуется (коды, пустые, чужие значения)', function () {
    if (!Database::isConnected()) {
        return; // normalizeLangMap фильтрует по активным языкам (нужна БД)
    }
    $cfg = HeaderConfig::get();
    $cfg['logo_by_lang'] = [
        'ru' => '  /uploads/public/ru.svg  ', // trim
        'uz' => '/uploads/public/uz.svg',
        'xx' => '/uploads/public/x.svg',       // неактивный/неизвестный язык — отбрасывается
        'bad key' => '/y.svg',                 // некорректный код — отбрасывается
        'en' => '',                            // пусто — отбрасывается
    ];
    HeaderConfig::save($cfg);
    $back = HeaderConfig::get();

    assert_same('/uploads/public/ru.svg', $back['logo_by_lang']['ru'] ?? null, 'ru сохранён и обрезан');
    assert_same('/uploads/public/uz.svg', $back['logo_by_lang']['uz'] ?? null, 'uz сохранён');
    assert_true(!isset($back['logo_by_lang']['xx']), 'неактивный язык отброшен');
    assert_true(!isset($back['logo_by_lang']['bad key']), 'некорректный код отброшен');
    assert_true(!isset($back['logo_by_lang']['en']), 'пустое значение отброшено');

    // Уборка: возвращаем пустую карту, чтобы не влиять на другие тесты/стенд.
    $cfg2 = HeaderConfig::get();
    $cfg2['logo_by_lang'] = [];
    $cfg2['logo_light_by_lang'] = [];
    HeaderConfig::save($cfg2);
});
