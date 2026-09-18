<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Карточка поля в конструкторе форм.
 *
 * Разметка нужна и существующим полям, и шаблону `<template>` для новых,
 * поэтому печатается она одной функцией: две копии расходились бы при первой
 * правке — ровно так в прежнем редакторе подсказка про условие показа
 * осталась только у существующих строк, а у новых её не было.
 */
final class FormBuilder
{
    /**
     * @param array<string, mixed> $field
     * @param string $index Номер строки репитера или `__INDEX__` в шаблоне.
     */
    public static function fieldCard(array $field, string $index): string
    {
        $width = FormLayout::widthOf($field);
        $type = FormLayout::normalizeType((string) ($field['type'] ?? 'text'));
        $label = (string) ($field['label'] ?? '');
        $condition = is_array($field['condition'] ?? null) ? $field['condition'] : [];

        $name = static fn (string $key): string => 'fields[' . $index . '][' . $key . ']';
        $esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES);

        $typeOptions = '';
        foreach (FormLayout::TYPES as $value => $title) {
            // Признак «у типа есть варианты выбора» едет в разметке, а не
            // вторым списком в скрипте: набор типов объявлен один раз.
            $hasOptions = in_array($value, FormLayout::OPTION_TYPES, true) ? ' data-has-options="1"' : '';
            $typeOptions .= '<option value="' . $esc($value) . '"' . $hasOptions
                . ($type === $value ? ' selected' : '') . '>' . $esc($title) . '</option>';
        }

        $widthOptions = '';
        foreach (FormLayout::WIDTHS as $value => $meta) {
            $widthOptions .= '<option value="' . $esc($value) . '"'
                . ($width === $value ? ' selected' : '') . '>' . $esc($meta['label']) . '</option>';
        }

        $optionsHidden = in_array($type, FormLayout::OPTION_TYPES, true) ? '' : ' is-hidden';
        $checked = !empty($field['required']) ? ' checked' : '';
        $grip = AdminUi::icon('grip-vertical');
        $title = $label !== '' ? $label : 'Новое поле';

        return <<<HTML
        <details class="formb-card">
            <summary class="formb-card__head">
                <span class="formb-card__grip" aria-hidden="true">{$grip}</span>
                <span class="formb-card__title" data-fb-title>{$esc($title)}</span>
                <span class="formb-card__type" data-fb-type-label>{$esc(FormLayout::TYPES[$type])}</span>
            </summary>
            <div class="formb-card__body">
                <div class="form-field">
                    <label>Подпись поля</label>
                    <input type="text" name="{$name('label')}" value="{$esc($label)}" data-fb-label placeholder="Ваше имя">
                </div>
                <div class="form-field">
                    <label>Имя поля (латиница, для базы и писем)</label>
                    <input type="text" name="{$name('name')}" value="{$esc((string) ($field['name'] ?? ''))}" placeholder="name">
                </div>
                <div class="form-field">
                    <label>Тип поля</label>
                    <select name="{$name('type')}" data-fb-type>{$typeOptions}</select>
                </div>
                <div class="form-field">
                    <label>Ширина поля</label>
                    <select name="{$name('width')}" data-fb-width>{$widthOptions}</select>
                    <span class="form-hint">Доля ряда. На телефоне любое поле занимает ряд целиком.</span>
                </div>
                <div class="form-field formb-card__full{$optionsHidden}" data-field-options-container>
                    <label>Варианты выбора (через запятую)</label>
                    <input type="text" name="{$name('options')}" value="{$esc((string) ($field['options'] ?? ''))}" placeholder="Вариант 1, Вариант 2, Вариант 3">
                </div>
                <div class="form-field form-field--checkbox formb-card__full">
                    <input type="checkbox" name="{$name('required')}" value="1" id="formb-req-{$index}"{$checked}>
                    <label for="formb-req-{$index}">Обязательное поле</label>
                </div>
                <div class="form-field formb-card__full">
                    <label>Условие показа (необязательно)</label>
                    <div class="formb-card__cond">
                        <input type="text" name="{$name('condition_field')}" placeholder="имя другого поля" value="{$esc((string) ($condition['field'] ?? ''))}">
                        <input type="text" name="{$name('condition_value')}" placeholder="= значение" value="{$esc((string) ($condition['value'] ?? ''))}">
                    </div>
                    <span class="form-hint">Поле показывается, только если названное поле равно значению.</span>
                </div>
            </div>
        </details>
        <div class="formb-cell__actions">
            <button type="button" class="btn btn--small" data-repeater-move="up" title="Выше" aria-label="Переместить поле выше">&uarr;</button>
            <button type="button" class="btn btn--small" data-repeater-move="down" title="Ниже" aria-label="Переместить поле ниже">&darr;</button>
            <button type="button" class="btn btn--small btn--danger" data-repeater-remove>Удалить</button>
        </div>
        HTML;
    }

    /** Класс ячейки сетки конструктора: ширина карточки равна ширине поля. */
    public static function cellClass(string $width): string
    {
        return 'repeater-row formb-cell ' . FormLayout::widthClass($width, 'formb-cell');
    }
}
