<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Input;
use App\Core\Redirect;
use App\Core\TextReplace;
use App\Core\View;

/**
 * «Найти и заменить»: фраза по всему контенту сайта.
 *
 * Только супер-админу: одна операция меняет сотни записей разом, в том числе
 * настройки сайта. Поиск ничего не меняет и идёт обычным GET — его адрес
 * можно сохранить и повторить; замена — POST по отмеченным местам.
 */
final class ReplaceController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();

        $query = Input::query();
        $needle = $query->str('q', '', TextReplace::MAX_LENGTH);
        $form = [
            'q' => $needle,
            'with' => $query->str('with', '', TextReplace::MAX_LENGTH),
            'case' => $query->bool('case'),
            'settings' => $query->bool('settings'),
        ];
        $result = null;
        if (mb_strlen($needle) >= TextReplace::MIN_LENGTH) {
            $result = TextReplace::find($needle, $form['case'], $form['settings']);
        }

        View::render('admin/replace/index', [
            'form' => $form,
            'result' => $result,
            'history' => TextReplace::history(),
        ]);
    }

    public function apply(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $post = Input::post();
        $needle = $post->str('q', '', TextReplace::MAX_LENGTH);
        $replacement = $post->str('with', '', TextReplace::MAX_LENGTH);
        $case = $post->bool('case');
        $settings = $post->bool('settings');
        $back = '/admin/replace?' . http_build_query(array_filter([
            'q' => $needle, 'with' => $replacement, 'case' => $case ? '1' : '', 'settings' => $settings ? '1' : '',
        ]));

        // Замена вставляется и в HTML: тег в ней стал бы разметкой, а не
        // текстом, поэтому заменять можно только текстом.
        if (mb_strlen($needle) < TextReplace::MIN_LENGTH) {
            Flash::error('Фраза для поиска короче ' . TextReplace::MIN_LENGTH . ' знаков.');
            Redirect::to($back);
        }
        if (preg_match('/[<>]/', $replacement) === 1) {
            Flash::error('Замена — только текст: знаки < и > в ней не допускаются.');
            Redirect::to($back);
        }
        if ($replacement === $needle) {
            Flash::error('Фраза и замена совпадают — менять нечего.');
            Redirect::to($back);
        }
        $refs = $post->strings('refs');
        if ($refs === []) {
            Flash::error('Не отмечено ни одного места для замены.');
            Redirect::to($back);
        }

        $result = TextReplace::apply($needle, $replacement, $case, $refs, Auth::id());
        if ($result['fields'] === 0) {
            Flash::error('Ничего не заменено: в отмеченных местах фразы уже нет.');
        } else {
            Flash::success('Заменено: ' . $result['replacements'] . ' раз в ' . $result['fields'] . ' полях. Операцию можно отменить ниже.');
        }

        Redirect::to($back);
    }

    public function undo(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $result = TextReplace::undo(Input::post()->str('log', '', 40));
        if ($result === null) {
            Flash::error('Операция не найдена или уже отменена.');
        } elseif ($result['conflicts'] > 0) {
            Flash::error('Возвращено полей: ' . $result['restored'] . '. Не возвращено ' . $result['conflicts']
                . ': их уже правили после замены — откройте их и поправьте вручную.');
        } else {
            Flash::success('Замена отменена: возвращено полей — ' . $result['restored'] . '.');
        }

        Redirect::to('/admin/replace');
    }
}
