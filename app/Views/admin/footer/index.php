<?php

use App\Core\Csrf;
use App\Core\FooterConfig;

$pageTitle = 'Подвал сайта';
$activeNav = 'footer';
require __DIR__ . '/../layout/header.php';

/** @var array $config */
$widgets = FooterConfig::WIDGETS;

/** Рендер select виджета с выбранным значением. */
$widgetSelect = function (string $name, string $current) use ($widgets): string {
    $out = '<select name="' . $name . '">';
    foreach ($widgets as $val => $label) {
        $sel = $current === $val ? ' selected' : '';
        $out .= '<option value="' . htmlspecialchars($val, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
    }
    return $out . '</select>';
};
?>
<div class="admin-builder-workspace">
    <form method="post" action="/admin/footer" class="form-grid">
        <?= Csrf::field() ?>
        <input type="hidden" name="v" value="<?= (int) ($config['v'] ?? FooterConfig::VERSION) ?>">

        <div class="header-builder__group">
            <h3>Стиль подвала</h3>
            <div class="form-field">
                <label for="style">Оформление</label>
                <select id="style" name="style">
                    <option value="columns" <?= $config['style'] === 'columns' ? 'selected' : '' ?>>Колонки (конструктор)</option>
                    <option value="minimal" <?= $config['style'] === 'minimal' ? 'selected' : '' ?>>Минимальный (только копирайт)</option>
                </select>
                <span class="form-hint">В режиме «Колонки» подвал собирается из колонок ниже.</span>
            </div>
        </div>

        <div class="header-builder__group">
            <h3>Колонки подвала</h3>
            <p class="form-hint u-inline-291b7bbb01">
                До <?= FooterConfig::MAX_COLUMNS ?> колонок. Каждая колонка — заголовок и виджет.
                Поле «Текст» работает у двух виджетов: «Логотип и описание» печатает его
                подписью под знаком, «Текст / HTML» — содержимым всей колонки
                (скрипты и небезопасные теги вырезаются). Адрес, телефон и почту виджет
                «Контакты» берёт из «Настроек сайта».
            </p>
            <div data-repeater="footcol" data-repeater-max="<?= FooterConfig::MAX_COLUMNS ?>" class="fb-grid">
                <?php foreach ($config['columns'] as $i => $col): ?>
                    <div class="repeater-row fb-card">
                        <div class="fb-card__head">
                            <span class="fb-card__badge">Колонка</span>
                            <span class="fb-card__tools">
                                <button type="button" class="fb-move" data-fb-move="up" aria-label="Выше/левее" title="Переместить">←</button>
                                <button type="button" class="fb-move" data-fb-move="down" aria-label="Ниже/правее" title="Переместить">→</button>
                            </span>
                        </div>
                        <div class="form-field">
                            <label>Заголовок колонки</label>
                            <input type="text" name="columns[<?= $i ?>][heading]" value="<?= htmlspecialchars($col['heading'], ENT_QUOTES) ?>" placeholder="напр. Разделы">
                        </div>
                        <div class="form-field">
                            <label>Виджет</label>
                            <?= $widgetSelect('columns[' . $i . '][widget]', $col['widget']) ?>
                        </div>
                        <div class="form-field">
                            <label>Текст / HTML (для виджетов «Логотип и описание» и «Текст»)</label>
                            <textarea name="columns[<?= $i ?>][text]" rows="3" placeholder="<p>Произвольный текст…</p>"><?= htmlspecialchars($col['text'], ENT_QUOTES) ?></textarea>
                        </div>
                        <button type="button" class="btn btn--small btn--danger repeater-row__remove" data-repeater-remove>Удалить колонку</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <template data-repeater-template="footcol">
                <div class="fb-card__head">
                    <span class="fb-card__badge">Колонка</span>
                    <span class="fb-card__tools">
                        <button type="button" class="fb-move" data-fb-move="up" aria-label="Выше/левее" title="Переместить">←</button>
                        <button type="button" class="fb-move" data-fb-move="down" aria-label="Ниже/правее" title="Переместить">→</button>
                    </span>
                </div>
                <div class="form-field">
                    <label>Заголовок колонки</label>
                    <input type="text" name="columns[__INDEX__][heading]" placeholder="напр. Разделы">
                </div>
                <div class="form-field">
                    <label>Виджет</label>
                    <?= $widgetSelect('columns[__INDEX__][widget]', 'menu') ?>
                </div>
                <div class="form-field">
                    <label>Текст / HTML (для виджетов «Логотип и описание» и «Текст»)</label>
                    <textarea name="columns[__INDEX__][text]" rows="3" placeholder="<p>Произвольный текст…</p>"></textarea>
                </div>
                <button type="button" class="btn btn--small btn--danger repeater-row__remove" data-repeater-remove>Удалить колонку</button>
            </template>
            <div class="repeater-actions">
                <button type="button" class="btn btn--small" data-repeater-add="footcol"><?= \App\Core\AdminUi::icon('plus') ?>Добавить колонку</button>
            </div>
        </div>

        <div class="header-builder__group">
            <h3>Нижняя строка</h3>
            <div class="form-field">
                <?php
                // Фон подвала: те же режимы, что и у секции страницы.
                $fbg = (array) ($config['background'] ?? []);
                $fbgMode = (string) ($fbg['_bg_mode'] ?? 'preset');
                $fbgModes = [
                    'preset' => 'Как в теме',
                    'color' => 'Свой цвет',
                    'gradient' => 'Градиент',
                    'image' => 'Фотография или плитка-узор',
                    'pattern' => 'Встроенный узор',
                ];
                $fbgPatterns = ['dots' => 'Точки', 'grid' => 'Сетка', 'diagonal' => 'Диагональ', 'emblem' => 'Гирих (эмблема)'];
                ?>
                <div class="form-field">
                    <label for="bg_mode">Фон подвала</label>
                    <select id="bg_mode" name="bg_mode">
                        <?php foreach ($fbgModes as $v => $l): ?><option value="<?= $v ?>" <?= $fbgMode === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
                    </select>
                    <span class="form-hint">Фон подвала не грузится лениво — тяжёлую фотографию сюда лучше не ставить.</span>
                </div>
                <div data-bg-group="color pattern">
                <?= \App\Core\AdminUi::colorField('bg_color', $fbg['_bg_color'] ?? '', 'Цвет фона (и подложка узора)', '#0f2b46', 'Из темы') ?>
                </div>
                <div data-bg-group="gradient">
                <div class="form-grid-2col">
                    <?= \App\Core\AdminUi::colorField('bg_gradient_from', $fbg['_bg_gradient_from'] ?? '', 'Градиент: от', '#0f2b46', 'Не задан') ?>
                    <?= \App\Core\AdminUi::colorField('bg_gradient_to', $fbg['_bg_gradient_to'] ?? '', 'Градиент: до', '#009bbe', 'Не задан') ?>
                </div>
                <div class="form-field">
                    <label for="bg_gradient_angle">Градиент: угол</label>
                    <input type="number" id="bg_gradient_angle" name="bg_gradient_angle" min="0" max="360" step="5" value="<?= (int) ($fbg['_bg_gradient_angle'] ?? 135) ?>">
                </div>
                </div>
                <div data-bg-group="image">
                <?= \App\Core\AdminUi::imageField('bg_image', (string) ($fbg['_bg_image'] ?? ''), [
                    'label' => 'Фон: изображение',
                    'hint' => 'Фотография во всю ширину или небольшая плитка узора (PNG/SVG).',
                ]) ?>
                <div class="form-field">
                    <label for="bg_repeat">Изображение: как показывать</label>
                    <select id="bg_repeat" name="bg_repeat">
                        <option value="cover" <?= (string) ($fbg['_bg_repeat'] ?? 'cover') !== 'tile' ? 'selected' : '' ?>>Фотография — на всю ширину</option>
                        <option value="tile" <?= (string) ($fbg['_bg_repeat'] ?? '') === 'tile' ? 'selected' : '' ?>>Плитка — повторять узором</option>
                    </select>
                </div>
                <div class="form-grid-2col">
                    <div class="form-field">
                        <label for="bg_tile_size">Плитка: размер, px</label>
                        <input type="number" id="bg_tile_size" name="bg_tile_size" min="16" max="600" step="4" value="<?= (int) ($fbg['_bg_tile_size'] ?? 120) ?>">
                    </div>
                    <div class="form-field">
                        <label for="bg_overlay">Фотография: затемнение, %</label>
                        <input type="number" id="bg_overlay" name="bg_overlay" min="0" max="80" step="5" value="<?= (int) ($fbg['_bg_overlay'] ?? 45) ?>">
                    </div>
                </div>
                </div>
                <div data-bg-group="pattern">
                <div class="form-field">
                    <label for="bg_pattern">Встроенный узор</label>
                    <select id="bg_pattern" name="bg_pattern">
                        <?php foreach ($fbgPatterns as $v => $l): ?><option value="<?= $v ?>" <?= (string) ($fbg['_bg_pattern'] ?? 'dots') === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-grid-2col">
                    <?= \App\Core\AdminUi::colorField('bg_pattern_color', $fbg['_bg_pattern_color'] ?? '', 'Цвет узора', '#ffffff', 'Акцент сайта') ?>
                    <div class="form-field">
                        <label for="bg_pattern_size">Узор: шаг, px</label>
                        <input type="number" id="bg_pattern_size" name="bg_pattern_size" min="8" max="240" step="2" value="<?= (int) ($fbg['_bg_pattern_size'] ?? 28) ?>">
                    </div>
                </div>
                <div class="form-field">
                    <label for="bg_pattern_opacity">Узор: заметность, %</label>
                    <input type="number" id="bg_pattern_opacity" name="bg_pattern_opacity" min="3" max="60" step="1" value="<?= (int) ($fbg['_bg_pattern_opacity'] ?? 22) ?>">
                </div>
                </div>

                <label for="bottom">Копирайт / текст</label>
                <input type="text" id="bottom" name="bottom" value="<?= htmlspecialchars($config['bottom'], ENT_QUOTES) ?>">
                <span class="form-hint">Плейсхолдеры: <code>{year}</code> — текущий год, <code>{site}</code> — название сайта.</span>
            </div>
            <?php $bottomLinks = (array) ($config['bottom_links'] ?? []); ?>
            <p class="form-hint">
                Ссылки справа в строке копирайта — до <?= FooterConfig::MAX_BOTTOM_LINKS ?> штук
                (условия использования, карта сайта, обратная связь). Внутренний адрес
                пишется от корня (<code>/sitemap</code>) и сам получает языковой префикс;
                внешний — полностью, с <code>https://</code>. Ссылка без подписи или без
                адреса не выводится.
            </p>
            <div data-repeater="footlink" data-repeater-max="<?= FooterConfig::MAX_BOTTOM_LINKS ?>" class="fb-grid">
                <?php foreach ($bottomLinks as $i => $link): ?>
                    <div class="repeater-row fb-card">
                        <div class="fb-card__head">
                            <span class="fb-card__badge">Ссылка</span>
                        </div>
                        <div class="form-field">
                            <label>Подпись</label>
                            <input type="text" name="bottom_links[<?= $i ?>][label]" value="<?= htmlspecialchars((string) $link['label'], ENT_QUOTES) ?>" placeholder="напр. Карта сайта">
                        </div>
                        <div class="form-field">
                            <label>Адрес</label>
                            <input type="text" name="bottom_links[<?= $i ?>][url]" value="<?= htmlspecialchars((string) $link['url'], ENT_QUOTES) ?>" placeholder="/sitemap">
                        </div>
                        <button type="button" class="btn btn--small btn--danger repeater-row__remove" data-repeater-remove>Удалить ссылку</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <template data-repeater-template="footlink">
                <div class="fb-card__head">
                    <span class="fb-card__badge">Ссылка</span>
                </div>
                <div class="form-field">
                    <label>Подпись</label>
                    <input type="text" name="bottom_links[__INDEX__][label]" placeholder="напр. Карта сайта">
                </div>
                <div class="form-field">
                    <label>Адрес</label>
                    <input type="text" name="bottom_links[__INDEX__][url]" placeholder="/sitemap">
                </div>
                <button type="button" class="btn btn--small btn--danger repeater-row__remove" data-repeater-remove>Удалить ссылку</button>
            </template>
            <div class="repeater-actions">
                <button type="button" class="btn btn--small" data-repeater-add="footlink"><?= \App\Core\AdminUi::icon('plus') ?>Добавить ссылку</button>
            </div>
        </div>

        <div class="form-actions form-actions--sticky">
            <button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('save') ?>Сохранить подвал</button>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>