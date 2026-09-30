<?php

declare(strict_types=1);

namespace App\Core\Design;

use App\Core\AccentContrast;
use App\Core\SettingsValidator;
use App\Models\Setting;

/**
 * Тень карточек и подложка текста на фото новостей: цвет и сила из
 * настроек, цвет заголовка поверх подложки считается по контрасту.
 *
 * Вынесено из DesignSettings (там остался фасад с теми же именами).
 */
final class Surfaces
{
    /** Прежний цвет зашитой тени: холодный сине-серый. */
    public const SHADOW_COLOR_DEFAULT = '#101828';

    /** Прежний цвет подложки текста крупных карточек: rgb(6, 14, 28). */
    public const VEIL_COLOR_DEFAULT = '#060e1c';

    /** Плотность подложки при силе 100 % — ровно та, что была зашита в теме. */
    public const VEIL_ALPHA_BASE = 0.88;

    /** Кандидаты в цвет заголовка поверх подложки. */
    public const VEIL_FG_LIGHT = '#ffffff';
    public const VEIL_FG_DARK = '#0b1a30';


    /**
     * Цвет тени карточек. Прежде тень была зашита в код тремя строками
     * `rgba(16,24,40,…)`: «Стиль карточек» выбирал форму, а цвет и силу
     * поменять было нечем — на тёплой или тёмной палитре холодная серо-синяя
     * тень читается грязным пятном.
     */
    public static function shadowColor(): string
    {
        return SettingsValidator::hexColor(
            (string) Setting::get('design_shadow_color', self::SHADOW_COLOR_DEFAULT),
            self::SHADOW_COLOR_DEFAULT
        );
    }

    /**
     * Сила тени в процентах от прежней: 100 — как было, 0 — тени нет вовсе.
     * Верхняя граница 300 % — дальше тень перестаёт быть тенью и становится
     * заливкой под карточкой.
     */
    public static function shadowStrength(): int
    {
        $raw = trim((string) Setting::get('design_shadow_strength', ''));
        if ($raw === '' || !is_numeric($raw)) {
            return 100;
        }

        return max(0, min(300, (int) round((float) $raw)));
    }

    /**
     * Цвет подложки текста на крупных карточках новостей (обложка и широкая).
     *
     * Прежде он был зашит литералом `rgba(6,14,28,…)` в четырёх местах темы:
     * на тёплой или светлой палитре холодная почти-чёрная вуаль читается
     * чужой, а поменять её было нечем — тот же случай, что был с тенью
     * карточек.
     */
    public static function veilColor(): string
    {
        return SettingsValidator::hexColor(
            (string) Setting::get('design_veil_color', self::VEIL_COLOR_DEFAULT),
            self::VEIL_COLOR_DEFAULT
        );
    }

    /**
     * Плотность подложки в процентах от прежней: 100 — как было (.88).
     *
     * Нижняя граница 40 %, а не 0: подложка существует затем, чтобы белый
     * заголовок читался на **любой** фотографии, и прозрачная вуаль вернула бы
     * читаемость в зависимость от кадра. Верхняя — 113 %, дальше плотность
     * упирается в единицу и настройка перестаёт что-либо менять.
     */
    public static function veilStrength(): int
    {
        $raw = trim((string) Setting::get('design_veil_strength', ''));
        if ($raw === '' || !is_numeric($raw)) {
            return 100;
        }

        return max(40, min(113, (int) round((float) $raw)));
    }

    /**
     * Подложка текста крупной карточки: цвет, плотность и цвет самого текста.
     *
     * Цвет текста не задаётся редактором, а **считается по контрасту**: светлая
     * вуаль требует тёмного заголовка, тёмная — светлого, и белый литерал на
     * жёлтой вуали дал бы нечитаемую строку, о которой никто бы не узнал.
     *
     * Контраст проверяется на обоих краях: подложка лежит на фотографии, а та
     * бывает и белой, и чёрной, поэтому годной считается плотность, при которой
     * заголовок проходит 4.5:1 в **худшем** из двух случаев. Если выбранной
     * плотности не хватает, она поднимается до первой достаточной — настройка
     * не должна позволять молча сломать читаемость. Поднятие возвращается
     * отдельным признаком, чтобы форма могла о нём сказать.
     *
     * @return array{rgb: string, alpha: float, fg: string, ratio: float, raised: bool}
     */
    public static function newsVeil(): array
    {
        $veil = AccentContrast::toRgb(self::veilColor());
        $wanted = min(1.0, self::VEIL_ALPHA_BASE * self::veilStrength() / 100);

        $best = static function (float $alpha) use ($veil): array {
            $pick = ['fg' => '#ffffff', 'ratio' => 0.0];
            foreach ([self::VEIL_FG_LIGHT, self::VEIL_FG_DARK] as $fg) {
                // Худший из краёв: подложка на белом кадре и на чёрном.
                $worst = min(
                    AccentContrast::ratio($fg, self::blend($veil, [255, 255, 255], $alpha)),
                    AccentContrast::ratio($fg, self::blend($veil, [0, 0, 0], $alpha))
                );
                if ($worst > $pick['ratio']) {
                    $pick = ['fg' => $fg, 'ratio' => $worst];
                }
            }

            return $pick;
        };

        $alpha = $wanted;
        $pick = $best($alpha);
        while ($pick['ratio'] < AccentContrast::AA_NORMAL && $alpha < 1.0) {
            $alpha = min(1.0, round($alpha + 0.01, 2));
            $pick = $best($alpha);
        }

        return [
            'rgb' => implode(', ', $veil),
            'alpha' => round($alpha, 3),
            'fg' => $pick['fg'],
            'ratio' => round($pick['ratio'], 2),
            'raised' => $alpha > $wanted + 0.0001,
        ];
    }

    /**
     * Цвет вуали плотности $alpha, положенной на кадр цвета $photo.
     *
     * Смешение считается в sRGB — там же, где его считает браузер, — иначе
     * проверка контраста отвечала бы не про то, что видно на экране.
     *
     * @param array{0:int,1:int,2:int} $veil
     * @param array{0:int,1:int,2:int} $photo
     */
    private static function blend(array $veil, array $photo, float $alpha): string
    {
        $mix = [];
        foreach ([0, 1, 2] as $i) {
            $mix[$i] = (int) round($alpha * $veil[$i] + (1 - $alpha) * $photo[$i]);
        }

        return AccentContrast::toHex($mix);
    }

    /** Тень карточек: форма из «Стиля карточек», цвет и сила — из настроек. */
    public static function cardShadow(string $style): string
    {
        if ($style === 'flat') {
            return 'none';
        }
        $strength = self::shadowStrength() / 100;
        if ($strength <= 0) {
            return 'none';
        }
        $color = static function (float $alpha) use ($strength): string {
            $hex = ltrim(self::shadowColor(), '#');
            $alpha = min(1.0, round($alpha * $strength, 3));

            return sprintf(
                'rgba(%d,%d,%d,%s)',
                (int) hexdec(substr($hex, 0, 2)),
                (int) hexdec(substr($hex, 2, 2)),
                (int) hexdec(substr($hex, 4, 2)),
                rtrim(rtrim(number_format($alpha, 3, '.', ''), '0'), '.') ?: '0'
            );
        };

        return $style === 'elevated'
            ? '0 10px 30px ' . $color(0.12)
            : '0 1px 3px ' . $color(0.06) . ', 0 6px 18px ' . $color(0.05);
    }
}
