<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\Media;
use App\Models\Goal;

/**
 * Случайная цель для виджета-карусели.
 *
 * Отдельный адрес нужен из-за кэша: страница кэшируется общим ключом, и цель,
 * выбранная при её сборке, была бы одной и той же для всех посетителей до
 * сброса кэша. Поэтому страница отдаёт запасную цель, а скрипт просит свежую
 * здесь — этот ответ не кэшируется никогда.
 *
 * Ответ отдаётся JSON'ом из двух готовых кусков разметки — текста цели и её
 * кадров. Одной строкой HTML их не склеить: скрипт кладёт их в разные места
 * (текст над каруселью, кадры в дорожку), и разбирать склейку пришлось бы на
 * стороне браузера.
 */
final class GoalController
{
    public function random(): void
    {
        $random = Goal::random(\App\Core\Locale::current());

        header('Content-Type: application/json; charset=utf-8');
        // Ответ обязан быть свежим у каждого запроса — иначе браузер или
        // прокси вернут ту же цель, и вся затея теряет смысл.
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex');

        if ($random === null) {
            http_response_code(204);
            return;
        }

        $images = $random['images'];
        $total = count($images);
        $html = '';
        foreach ($images as $index => $image) {
            $html .= '<div class="block-slider__slide' . ($index === 0 ? ' is-active' : '') . '"'
                . ' role="group" aria-roledescription="' . htmlspecialchars(t('Слайд'), ENT_QUOTES) . '"'
                . ' aria-label="' . ($index + 1) . ' ' . htmlspecialchars(t('из'), ENT_QUOTES) . ' ' . $total . '"'
                . ' aria-hidden="' . ($index === 0 ? 'false' : 'true') . '">'
                . Media::picture(
                    (string) $image['image'],
                    (string) $image['alt'],
                    null,
                    null,
                    '',
                    $index !== 0,
                    '(max-width: 900px) 100vw, 320px'
                )
                . '</div>';
        }

        echo json_encode([
            'text' => $this->text($random['goal']),
            'slides' => $html,
            // Подпись карусели меняется вместе с целью: без неё диктор
            // продолжал бы объявлять предыдущую.
            'label' => (string) ($random['goal']['name'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Название и описание цели — та же разметка, что и в шаблоне виджета.
     *
     * @param array<string, mixed> $goal
     */
    private function text(array $goal): string
    {
        $name = trim((string) ($goal['name'] ?? ''));
        $description = trim((string) ($goal['description'] ?? ''));

        $html = '';
        if ($name !== '') {
            $html .= '<p class="goal-carousel__name">' . htmlspecialchars($name, ENT_QUOTES) . '</p>';
        }
        if ($description !== '') {
            $html .= '<p class="goal-carousel__desc">' . htmlspecialchars($description, ENT_QUOTES) . '</p>';
        }

        return $html;
    }
}
