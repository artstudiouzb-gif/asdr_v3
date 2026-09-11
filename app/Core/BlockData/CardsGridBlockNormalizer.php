<?php

declare(strict_types=1);

namespace App\Core\BlockData;

use App\Core\Icon;
use App\Core\TextProcessor;
use App\Core\UrlGuard;

/**
 * Нормализатор блока «Карточки».
 *
 * Сюда же переехали «Преимущества»: оба блока печатали одну и ту же карточку
 * (`.feature-card` с тем же нутром) из одних и тех же полей — иконка,
 * заголовок, текст, ссылка, — а разошлись только настройками. Пока
 * нормализаторов было два, расходились и они: правка одного до второго не
 * доходила.
 *
 * Элементы «Медиагалереи» собираются по тем же правилам, но у неё карточка
 * живёт без заголовка (одна фотография — уже содержимое), поэтому пустой
 * считается строка без картинки, а не без названия.
 */
final class CardsGridBlockNormalizer
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input, string $locale = 'ru', string $type = 'cards_grid'): array
    {
        $isGallery = $type === 'media_gallery';

        $items = [];
        foreach ((array) ($input['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? $item['label'] ?? ''));
            $image = BlockDataInput::safeMedia($item['image'] ?? '');
            // Карточка без названия — пустая строка репитера: на сайте она
            // заняла бы ячейку сетки и ничем её не заполнила. У галереи роль
            // содержимого играет сам кадр.
            if ($title === '' && (!$isGallery || $image === '')) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));
            $items[] = [
                'icon_svg' => Icon::cleanName($item['icon_svg'] ?? ''),
                'image' => $image,
                'title' => TextProcessor::typographPlain($title, $locale),
                'text' => TextProcessor::typographPlain(trim((string) ($item['text'] ?? '')), $locale),
                'meta' => TextProcessor::typographPlain(trim((string) ($item['meta'] ?? '')), $locale),
                'kind' => ($item['kind'] ?? '') === 'photo' ? 'photo' : 'video',
                'url' => $url !== '' && UrlGuard::isSafeLink($url) ? $url : '',
            ];
        }

        // Имя схемы пишется литералом, а не собирается в переменной: связь
        // «тип → схема» сверяет сторож (тест 275), и по переменной он её не
        // увидит — а значит не заметит и типа, уехавшего мимо схемы.
        $schema = $isGallery
            ? BlockFieldSchema::normalize('media_gallery', $input, $locale)
            : BlockFieldSchema::normalize('cards_grid', $input, $locale);

        return array_merge($schema, ['items' => $items]);
    }
}
