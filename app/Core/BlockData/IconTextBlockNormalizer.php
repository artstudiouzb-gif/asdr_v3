<?php

declare(strict_types=1);

namespace App\Core\BlockData;

use App\Core\Icon;
use App\Core\TextProcessor;

/**
 * Нормализатор блока «Иконка и текст».
 *
 * Жил веткой в BlockController::collectData(). Вынесен, потому что читателей
 * стало двое: форма и смена типа блока (BlockConversion) — присланные ими
 * данные обязаны проходить одну и ту же проверку, иначе смена типа
 * сохраняла бы то, чего форма не пропустила бы.
 */
final class IconTextBlockNormalizer
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input, string $locale = 'ru'): array
    {
        $iconRows = [];
        foreach ((array) ($input['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rows = trim((string) ($item['rows'] ?? ''));
            $icon = Icon::cleanName($item['icon_svg'] ?? '');
            if ($rows === '' && $icon === '') {
                continue;
            }
            $iconRows[] = [
                'icon_svg' => $icon,
                // Пустой цвет = оттенок акцента сайта. Единый компонент
                // присылает `icon_color_off` при возврате к нему, поэтому
                // читаем цвет тем же способом, что остальные необязательные
                // цвета админки, а не сохраняем показанный образец.
                'icon_color' => BlockDataInput::optionalColor($item, 'icon_color'),
                'rows' => TextProcessor::typographPlain($rows, $locale),
            ];
        }

        return array_merge(
            BlockFieldSchema::normalize('icon_text', $input, $locale),
            [
                // Мимо схемы: значение зависит от выравнивания (см. EXTRA).
                'icon_position' => BlockDataInput::enum($input, 'icon_position', ['left', 'top', 'right'], 'left'),
                'items' => $iconRows,
            ]
        );
    }
}
