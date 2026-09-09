<?php

/**
 * Стрелки и точки адаптивной карусели. Разметка одна на все варианты блока
 * «Карточки»: движок (`data-carousel` в frontend.js) ищет её по атрибутам, и
 * вторая копия разъехалась бы с первой при первой правке. Панель приходит
 * скрытой — JS раскрывает её, только когда карточки действительно не
 * помещаются: без прокрутки стрелкам нечем управлять.
 */
?>
<span class="carousel-nav" data-carousel-nav hidden>
    <button type="button" class="carousel-nav__btn" data-carousel-prev aria-label="<?= htmlspecialchars(t('Назад'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('chevron-left', 18) ?></button>
    <span class="carousel-nav__dots" data-carousel-dots role="group" aria-label="<?= htmlspecialchars(t('Выбор слайда'), ENT_QUOTES) ?>"></span>
    <button type="button" class="carousel-nav__btn" data-carousel-next aria-label="<?= htmlspecialchars(t('Вперёд'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('chevron-right', 18) ?></button>
</span>
