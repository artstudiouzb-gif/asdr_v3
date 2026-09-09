<?php

declare(strict_types=1);

use App\Core\SchemaOrg;

test('Дайджест связан с подписчиком и защищён от повторной постановки в очередь', function () {
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    assert_contains('subscriber_id', $schema);
    assert_contains('dedupe_key', $schema);
    assert_contains("'cancelled'", $schema);
    assert_contains('idx_subscribers_status_lang', $schema);

    $dispatcher = (string) file_get_contents(APP_ROOT . '/app/Core/DigestDispatcher.php');
    assert_contains('Subscriber::active()', $dispatcher);
    assert_contains('MailQueue::enqueueDigest(', $dispatcher);
    assert_contains("'digest:' . \$result['period']", $dispatcher);

    $worker = (string) file_get_contents(APP_ROOT . '/app/Console/mail_worker.php');
    assert_contains('Subscriber::isActive(', $worker);
    assert_contains('MailQueue::markCancelled(', $worker);
});

test('Мероприятие поддерживает необязательный баннер в админке, карточке и detail', function () {
    $migration = (string) file_get_contents(APP_ROOT . '/database/migrations/2026_07_30_event_banner.sql');
    assert_contains("'banner_image'", $migration);
    assert_contains("'image'", $migration);
    assert_contains('0, 3', $migration, 'баннер должен быть необязательным');

    $catalog = (string) file_get_contents(APP_ROOT . '/app/Views/site/_catalog_list.php');
    assert_contains("['data']['banner_image']", $catalog);
    assert_contains('catcard__event-media', $catalog);

    $detail = (string) file_get_contents(APP_ROOT . '/app/Views/site/content_show.php');
    assert_contains('catdetail__event-banner', $detail);
    assert_contains('$ogImage = $eventBanner', $detail);

    $event = SchemaOrg::event(
        'Форум',
        'https://site.uz/catalog/meropriyatiya/forum',
        '2026-11-18T09:30',
        'Ташкент',
        'Описание',
        'https://site.uz/uploads/forum.jpg'
    );
    assert_same(['https://site.uz/uploads/forum.jpg'], $event['image']);
    assert_same('2026-11-18T09:30', $event['startDate']);
});
