<?php

declare(strict_types=1);

use App\Core\SchemaOrg;

test('SchemaOrg::newsArticle: поддерживает расширенные поля dateModified, author, inLanguage, articleSection', function () {
    $article = SchemaOrg::newsArticle(
        'Заголовок важной новости',
        'https://asdr.gov.uz/uz/news/vazhnaya-novost',
        '2026-09-01 12:00:00',
        'Краткий анонс новости для поисковых систем',
        'https://asdr.gov.uz/storage/media/cover.jpg',
        'Агентство стратегических реформ',
        '2026-09-02 15:30:00',
        'Пресс-служба',
        'uz',
        'Экономика'
    );

    assert_same('NewsArticle', $article['@type']);
    assert_same('Заголовок важной новости', $article['headline']);
    assert_true(str_starts_with((string) $article['datePublished'], '2026-09-01T'));
    assert_true(str_starts_with((string) $article['dateModified'], '2026-09-02T'));
    assert_same('Person', $article['author']['@type']);
    assert_same('Пресс-служба', $article['author']['name']);
    assert_same('Organization', $article['publisher']['@type']);
    assert_same('Агентство стратегических реформ', $article['publisher']['name']);
    assert_same('uz', $article['inLanguage']);
    assert_same('Экономика', $article['articleSection']);
    assert_same(['https://asdr.gov.uz/storage/media/cover.jpg'], $article['image']);
    assert_same('https://asdr.gov.uz/uz/news/vazhnaya-novost', $article['mainEntityOfPage']['@id']);
});

test('SchemaOrg::organization: поддерживает sameAs (соцсети)', function () {
    $org = SchemaOrg::organization(
        'Агентство',
        'https://asdr.gov.uz',
        '+998 71 200-00-00',
        'info@asdr.gov.uz',
        'Ташкент',
        'https://asdr.gov.uz/logo.png',
        ['https://t.me/asdruzofficial', 'https://facebook.com/asdruzofficial']
    );

    assert_same('GovernmentOrganization', $org['@type']);
    assert_same(['https://t.me/asdruzofficial', 'https://facebook.com/asdruzofficial'], $org['sameAs']);
});

test('SchemaOrg::governmentService: формирует правильную разметку госуслуги', function () {
    $service = SchemaOrg::governmentService(
        'Подача обращения граждан',
        'https://asdr.gov.uz/uz/contacts/feedback',
        'Электронное обращение',
        'Агентство стратегических реформ',
        'Онлайн-сервис приёма обращений физических и юридических лиц'
    );

    assert_same('GovernmentService', $service['@type']);
    assert_same('Подача обращения граждан', $service['name']);
    assert_same('Электронное обращение', $service['serviceType']);
    assert_same('GovernmentOrganization', $service['provider']['@type']);
    assert_same('Агентство стратегических реформ', $service['provider']['name']);
    assert_same('Uzbekistan', $service['areaServed']['name']);
});
