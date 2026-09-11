<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\BlockData\AdvantagesBlockNormalizer;
use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockData\BlockPresentationNormalizer;
use App\Core\BlockData\ContactCardsBlockNormalizer;
use App\Core\BlockData\CountersBlockNormalizer;
use App\Core\BlockData\FaqBlockNormalizer;
use App\Core\BlockData\HeroBlockNormalizer;
use App\Core\BlockData\SubscribeBlockNormalizer;
use App\Core\BlockData\TestimonialsBlockNormalizer;
use App\Core\BlockTypeRegistry;
use App\Core\BlockVersioning;
use App\Core\ConcurrencyException;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
use App\Core\TextProcessor;
use App\Models\Block;
use App\Models\BlockRevision;
use App\Models\FormDef;
use App\Models\Language;
use App\Models\Page;
use App\Models\Widget;

final class BlockController
{
    /** @param array<string, string> $params */
    public function store(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $pageId = (int) $params['id'];
        $page = Page::findById($pageId);
        if (!$page) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $type = (string) ($_POST['type'] ?? '');
        $lang = (string) ($_POST['block_lang'] ?? Language::defaultCode());
        if (!Language::isActive($lang)) {
            $lang = Language::defaultCode();
        }

        if (!BlockTypeRegistry::has($type)) {
            Flash::error('Неизвестный тип блока.');
            header('Location: ' . \App\Core\BlockOwner::editUrl($pageId, $lang));
            exit;
        }

        // Блок сырого HTML может создавать только супер-администратор.
        if ($type === 'html' && !Auth::isSuperAdmin()) {
            Flash::error('Блок «HTML-код» доступен только супер-администратору.');
            header('Location: ' . \App\Core\BlockOwner::editUrl($pageId, $lang));
            exit;
        }

        // Вложенность в контейнер (колонки, вкладки): блок добавляется внутрь,
        // если пришли parent_block_id + column_index (номер колонки/вкладки).
        $parentBlockId = null;
        $columnIndex = 0;
        $redirectTo = \App\Core\BlockOwner::editUrl($pageId, $lang);
        if (!empty($_POST['parent_block_id'])) {
            $parent = Block::findById((int) $_POST['parent_block_id']);
            if (!$parent || (int) $parent['page_id'] !== $pageId
                || !BlockTypeRegistry::isContainer((string) $parent['type'])
            ) {
                Flash::error('Некорректный родительский блок.');
                header('Location: ' . $redirectTo);
                exit;
            }
            // Запрет контейнера в контейнере.
            if (BlockTypeRegistry::isContainer($type)) {
                Flash::error('Блок «' . (BlockTypeRegistry::TYPE_LABELS[$type] ?? $type)
                    . '» нельзя вкладывать в колонки или вкладки.');
                header('Location: ' . $redirectTo);
                exit;
            }
            $parentBlockId = (int) $parent['id'];
            $columnIndex = max(0, (int) ($_POST['column_index'] ?? 0));
        }

        // Новый блок приходит с образцом наполнения: пустой блок не объясняет,
        // из чего он состоит, и редактор видит на странице пустое место.
        $title = trim((string) ($_POST['title'] ?? ''));
        $sample = \App\Core\BlockSamples::for($type, $lang);
        // Блок формы без выбранной формы показывает заглушку. Если на сайте
        // формы уже есть, подставляем первую — блок сразу рабочий.
        if ($type === 'form') {
            $firstForm = FormDef::all()[0] ?? null;
            if ($firstForm !== null) {
                $sample['form_id'] = (int) $firstForm['id'];
            }
        }
        // Оформление по умолчанию: появление при прокрутке у всех блоков, кроме
        // первого на странице и вложенных (см. newBlockPresentation).
        $presentation = \App\Core\BlockData\BlockPresentationNormalizer::newBlockPresentation(
            $parentBlockId === null && Block::forPage($pageId, $lang) === [],
            $parentBlockId !== null
        );

        $blockId = Block::create(
            $pageId,
            $lang,
            $type,
            $title !== '' ? $title : null,
            array_merge(BlockTypeRegistry::defaultsFor($type), $sample, $presentation),
            '',
            $parentBlockId,
            $columnIndex
        );
        \App\Core\Cache::clearPageCache($pageId);

        Flash::success($sample !== []
            ? 'Блок добавлен с примером наполнения — замените тексты своими.'
            : 'Блок добавлен. Заполните его содержимое.');
        header('Location: /admin/blocks/' . $blockId . '/edit');
        exit;
    }

    /** @param array<string, string> $params */
    public function edit(array $params): void
    {
        Auth::requireLogin();

        $block = Block::findById((int) $params['id']);
        if (!$block) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $data = json_decode((string) $block['data'], true) ?: [];

        View::render('admin/pages/block_form', [
            'block' => $block,
            'data' => $data,
            'forms' => $block['type'] === 'form' ? FormDef::all() : [],
            'departments' => self::departmentsFor((string) $block['type']),
            'widgets' => $block['type'] === 'bio_education' ? Widget::all() : [],
        ]);
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $block = Block::findById((int) $params['id']);
        if (!$block) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        // Кастомный CSS может менять только супер-администратор; для редактора
        // сохраняем прежнее значение независимо от присланных данных.
        $customCss = Auth::isSuperAdmin()
            ? (string) ($_POST['custom_css'] ?? '')
            : (string) ($block['custom_css'] ?? '');
        $locale = ((string) $block['lang'] === 'en') ? 'en' : 'ru';
        $data = $this->collectData($block['type'], $locale);
        // Создавать блок «HTML-код» может только супер-администратор — значит и
        // переписывать разметку уже созданного тоже: иначе запрет обходится
        // редактированием блока, добавленного кем-то другим.
        if ((string) $block['type'] === 'html' && !Auth::isSuperAdmin()) {
            $stored = json_decode((string) $block['data'], true);
            $data['html'] = (string) (is_array($stored) ? ($stored['html'] ?? '') : '');
        }
        $data = array_merge($data, BlockPresentationNormalizer::normalize($_POST));

        // Перевёрнутое окно молча не чиним: блок и правда не покажется никогда —
        // честнее предупредить, чем угадывать намерение редактора.
        if (BlockPresentationNormalizer::hasInvalidVisibilityWindow($data)) {
            Flash::error('Условия показа: дата окончания не позже даты начала — блок не будет показан. Проверьте даты.');
        }

        $expectedVersion = (int) ($_POST['expected_lock_version'] ?? 0);
        try {
            BlockVersioning::updateWithSnapshot(
                $block,
                $title !== '' ? $title : null,
                $data,
                $customCss,
                Auth::id(),
                $expectedVersion
            );
        } catch (ConcurrencyException) {
            $block = Block::findById((int) $block['id']) ?? $block;
            View::render('admin/pages/block_form', [
                'block' => $block,
                'data' => json_decode((string) $block['data'], true) ?: [],
                'forms' => $block['type'] === 'form' ? FormDef::all() : [],
                'departments' => self::departmentsFor((string) $block['type']),
                'widgets' => $block['type'] === 'bio_education' ? Widget::all() : [],
                'error' => 'Блок уже был изменён в другой вкладке или другим пользователем. Текущие данные перезагружены; восстановите локальный черновик и проверьте изменения.',
            ]);
            return;
        }
        \App\Core\Cache::clearPageCache((int) $block['page_id']);

        Flash::success('Блок сохранён.');

        // Заполненное, но нерабочее — говорим сразу, а не оставляем редактора
        // выяснять это, открыв сайт и не найдя своего текста.
        foreach (\App\Core\BlockHints::forBlock((string) $block['type'], $data) as $hint) {
            Flash::error($hint);
        }
        if (\App\Core\BlockHints::rendersEmpty([
            'id' => (int) $block['id'],
            'type' => (string) $block['type'],
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'custom_css' => '',
        ])) {
            Flash::error('Блок пока пуст и на сайте не показывается — заполните его поля.');
        }
        header('Location: ' . $this->pageEditUrl($block) . '&draft_saved=block%3A' . (int) $block['id']);
        exit;
    }

    /**
     * История версий блока (группа 5.1).
     * @param array<string, string> $params
     */
    public function revisions(array $params): void
    {
        Auth::requireLogin();
        $block = Block::findById((int) $params['id']);
        if (!$block) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        View::render('admin/blocks/revisions', [
            'block' => $block,
            'revisions' => BlockRevision::forBlock((int) $block['id']),
            'backUrl' => $this->pageEditUrl($block),
        ]);
    }

    /**
     * Восстановление блока из ревизии (создаёт новую ревизию, группа 5.1).
     * @param array<string, string> $params
     */
    public function restoreRevision(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $block = Block::findById((int) $params['id']);
        if (!$block) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        $rev = BlockRevision::findById((int) ($_POST['revision_id'] ?? 0));
        if (!$rev || (int) $rev['block_id'] !== (int) $block['id']) {
            Flash::error('Ревизия не найдена.');
            header('Location: /admin/blocks/' . (int) $block['id'] . '/revisions');
            exit;
        }

        // custom_css трогает только супер-админ; редактору оставляем текущий.
        $customCss = Auth::isSuperAdmin()
            ? ($rev['custom_css'] !== null ? (string) $rev['custom_css'] : '')
            : (string) ($block['custom_css'] ?? '');

        $revData = json_decode((string) $rev['data'], true) ?: [];
        // Разметку блока «HTML-код» правит только супер-админ — откат версии
        // не должен становиться обходным путём.
        if ((string) $block['type'] === 'html' && !Auth::isSuperAdmin()) {
            $stored = json_decode((string) $block['data'], true);
            $revData['html'] = (string) (is_array($stored) ? ($stored['html'] ?? '') : '');
        }

        $expectedVersion = (int) ($_POST['expected_lock_version'] ?? ($block['lock_version'] ?? 1));
        try {
            BlockVersioning::updateWithSnapshot(
                $block,
                $rev['title'] !== null ? (string) $rev['title'] : null,
                $revData,
                $customCss,
                Auth::id(),
                $expectedVersion
            );
        } catch (ConcurrencyException) {
            Flash::error('Блок уже изменился после открытия истории. Проверьте свежую версию и повторите восстановление.');
            header('Location: /admin/blocks/' . (int) $block['id'] . '/revisions');
            exit;
        }
        \App\Core\Cache::clearPageCache((int) $block['page_id']);

        Flash::success('Блок восстановлен из выбранной версии.');
        header('Location: ' . $this->pageEditUrl($block));
        exit;
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $block = Block::findById((int) $params['id']);
        if (!$block) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        Block::delete((int) $block['id']);
        \App\Core\Cache::clearPageCache((int) $block['page_id']);
        Flash::success('Блок удалён.');
        header('Location: ' . $this->pageEditUrl($block));
        exit;
    }

    /** AJAX-сохранение нового порядка блоков (drag-and-drop, задача 134). */
    public function reorder(): void
    {
        Auth::requireLogin();
        header('Content-Type: application/json; charset=UTF-8');

        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'CSRF']);
            return;
        }

        $pageId = (int) ($_POST['page_id'] ?? 0);
        $lang = (string) ($_POST['block_lang'] ?? Language::defaultCode());
        if (!Language::isActive($lang)) {
            $lang = Language::defaultCode();
        }
        $order = array_map('intval', (array) ($_POST['order'] ?? []));

        if ($pageId <= 0 || $order === []) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bad params']);
            return;
        }

        Block::reorder($pageId, $lang, $order);
        \App\Core\Cache::clearPageCache($pageId);

        echo json_encode(['ok' => true]);
    }

    /** @param array<string, string> $params */
    public function move(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $block = Block::findById((int) $params['id']);
        if (!$block) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $lang = (string) $block['lang'];
        $direction = $_POST['direction'] ?? '';
        if ($direction === 'up') {
            Block::moveUp((int) $block['id'], (int) $block['page_id'], $lang);
        } elseif ($direction === 'down') {
            Block::moveDown((int) $block['id'], (int) $block['page_id'], $lang);
        }
        \App\Core\Cache::clearPageCache((int) $block['page_id']);

        header('Location: ' . $this->pageEditUrl($block));
        exit;
    }

    /**
     * Включение/отключение вывода блока на сайте (без удаления).
     * @param array<string, string> $params
     */
    public function toggle(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $block = Block::findById((int) $params['id']);
        if (!$block) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $newState = (int) ($block['is_active'] ?? 1) !== 1;
        Block::setActive((int) $block['id'], $newState);
        \App\Core\Cache::clearPageCache((int) $block['page_id']);

        Flash::success($newState ? 'Блок включён и снова выводится на сайте.' : 'Блок отключён — он скрыт на сайте, но сохранён.');
        header('Location: ' . $this->pageEditUrl($block));
        exit;
    }

    private function pageEditUrl(array $block): string
    {
        return \App\Core\BlockOwner::editUrl((int) $block['page_id'], (string) $block['lang']);
    }

    private function collectData(string $type, string $locale = 'ru'): array
    {
        switch ($type) {
            case 'text':
                $items = [];
                foreach ((array) ($_POST['items'] ?? []) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $itemTitle = trim((string) ($item['title'] ?? ''));
                    if ($itemTitle === '') {
                        continue;
                    }
                    $items[] = [
                        'icon_svg' => \App\Core\Icon::cleanName($item['icon_svg'] ?? ''),
                        'title' => TextProcessor::typographPlain($itemTitle, $locale),
                    ];
                }
                $textVariant = (string) ($_POST['variant'] ?? 'default');
                $mediaType = (string) ($_POST['media_type'] ?? 'none');
                $mediaType = in_array($mediaType, ['none', 'image', 'video', 'youtube'], true)
                    ? $mediaType
                    : 'none';
                $mediaImage = \App\Core\BlockData\BlockDataInput::safeMedia($_POST['media_image'] ?? '');
                $mediaVideo = \App\Core\BlockData\BlockDataInput::safeMedia($_POST['media_video'] ?? '');
                $mediaYoutube = trim((string) ($_POST['media_youtube'] ?? ''));
                if ($mediaType === 'none') {
                    if (\App\Core\Video::youtubeId($mediaYoutube) !== null) {
                        $mediaType = 'youtube';
                    } elseif ($mediaVideo !== '') {
                        $mediaType = 'video';
                    } elseif ($mediaImage !== '') {
                        $mediaType = 'image';
                    }
                }
                return [
                    'variant' => in_array($textVariant, ['default', 'section', 'intro', 'system', 'spotlight'], true) ? $textVariant : 'default',
                    'title' => TextProcessor::typographPlain(trim((string) ($_POST['title_field'] ?? '')), $locale),
                    'content' => TextProcessor::process(
                        \App\Core\HtmlSanitizer::sanitizeText((string) ($_POST['content'] ?? '')),
                        $locale
                    ),
                    'aside_title' => TextProcessor::typographPlain(trim((string) ($_POST['aside_title'] ?? '')), $locale),
                    'items' => $items,
                    'quote' => TextProcessor::typographPlain(trim((string) ($_POST['quote'] ?? '')), $locale),
                    'quote_bg' => \App\Core\BlockData\BlockDataInput::optionalColor($_POST, 'quote_bg'),
                    'quote_color' => \App\Core\BlockData\BlockDataInput::optionalColor($_POST, 'quote_color'),
                    'quote_mark' => \App\Core\BlockData\BlockDataInput::enum($_POST, 'quote_mark', ['text', 'icon', 'none'], 'text'),
                    // Символ набирается редактором: подрезаем до одного знака —
                    // в углу карточки стоит кавычка, а не строка текста.
                    'quote_mark_text' => mb_substr(trim((string) ($_POST['quote_mark_text'] ?? '')), 0, 2),
                    'quote_mark_icon' => \App\Core\Icon::cleanName($_POST['quote_mark_icon'] ?? ''),
                    'quote_mark_size' => \App\Core\BlockData\BlockDataInput::int($_POST, 'quote_mark_size', 0, 240, 0),
                    'quote_mark_color' => \App\Core\BlockData\BlockDataInput::optionalColor($_POST, 'quote_mark_color'),
                    'quote_mark_position' => \App\Core\BlockData\BlockDataInput::enum(
                        $_POST,
                        'quote_mark_position',
                        ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'above'],
                        'top-left'
                    ),
                    'media_type' => $mediaType,
                    'media_image' => $mediaImage,
                    'media_video' => $mediaVideo,
                    'media_youtube' => \App\Core\Video::youtubeId($mediaYoutube) !== null ? $mediaYoutube : '',
                    'media_alt' => TextProcessor::typographPlain(trim((string) ($_POST['media_alt'] ?? '')), $locale),
                    'media_caption' => TextProcessor::typographPlain(trim((string) ($_POST['media_caption'] ?? '')), $locale),
                    'image_position' => \App\Core\MediaPosition::normalize($_POST['image_position'] ?? null),
                    'image_position_mobile' => \App\Core\MediaPosition::normalize($_POST['image_position_mobile'] ?? null),
                ];
            case 'html':
                // Даже супер-администратор сохраняет только безопасную
                // разметку: скрипты, inline-стили, on* и опасные URI запрещены.
                $rawHtml = (string) ($_POST['html'] ?? '');
                return [
                    'html' => \App\Core\HtmlSanitizer::sanitize($rawHtml),
                ];
            case 'cta':
                return BlockFieldSchema::normalize('cta', $_POST, $locale);
            case 'advantages':
                return AdvantagesBlockNormalizer::normalize($_POST, $locale);
            case 'slider':
                $slides = [];
                foreach ((array) ($_POST['slides'] ?? []) as $slide) {
                    $image = trim((string) ($slide['image'] ?? ''));
                    if ($image === '') {
                        continue;
                    }
                    $slideUrl = trim((string) ($slide['url'] ?? ''));
                    $slides[] = [
                        'image' => $image,
                        'alt' => trim((string) ($slide['alt'] ?? '')),
                        'caption' => trim((string) ($slide['caption'] ?? '')),
                        'url' => ($slideUrl !== '' && \App\Core\UrlGuard::isSafeLink($slideUrl)) ? $slideUrl : '',
                    ];
                }
                return array_merge(
                    BlockFieldSchema::normalize('slider', $_POST, $locale),
                    ['slides' => $slides]
                );
            case 'form':
                $formId = (int) ($_POST['form_id'] ?? 0);
                $layout = in_array($_POST['layout'] ?? '1col', ['1col', '2col'], true) ? (string) $_POST['layout'] : '1col';
                return [
                    'form_id' => $formId > 0 ? $formId : null,
                    'layout' => $layout,
                ];
            case 'columns':
                $columnsData = BlockFieldSchema::normalize('columns', $_POST, $locale);
                // Ширина колонок идёт мимо схемы: список допустимых пропорций
                // свой у каждого числа колонок, то есть значение зависит от
                // соседнего поля.
                $columnsData['ratio'] = \App\Core\ColumnRatio::normalize(
                    (string) ($_POST['ratio'] ?? ''),
                    (int) $columnsData['columns']
                );
                return $columnsData;
            case 'tabs':
                // Содержимое вкладок — вложенные блоки, здесь только подписи.
                $tabItems = [];
                foreach ((array) ($_POST['items'] ?? []) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $tabTitle = \App\Core\BlockData\BlockDataInput::plain($item, 'title', $locale);
                    if ($tabTitle === '') {
                        continue;
                    }
                    $tabItems[] = [
                        'title' => $tabTitle,
                        'icon' => \App\Core\Icon::cleanName($item['icon'] ?? ''),
                        'text' => \App\Core\BlockData\BlockDataInput::plain($item, 'text', $locale),
                    ];
                }
                return array_merge(
                    BlockFieldSchema::normalize('tabs', $_POST, $locale),
                    ['items' => array_slice($tabItems, 0, 10)]
                );
            case 'testimonials':
                return TestimonialsBlockNormalizer::normalize($_POST, $locale);
            case 'collage':
                return \App\Core\BlockData\CollageBlockNormalizer::normalize($_POST, $locale);
            case 'table':
                return BlockFieldSchema::normalize('table', $_POST, $locale);
            case 'image':
                return BlockFieldSchema::normalize('image', $_POST, $locale);
            case 'embed':
                return BlockFieldSchema::normalize('embed', $_POST, $locale);
            case 'chart':
                return BlockFieldSchema::normalize('chart', $_POST, $locale);
            case 'divider':
                return BlockFieldSchema::normalize('divider', $_POST, $locale);
            case 'buttons':
                return \App\Core\BlockData\ButtonsBlockNormalizer::normalize($_POST, $locale);
            case 'counters':
                return CountersBlockNormalizer::normalize($_POST, $locale);
            case 'team_list':
                return array_merge(
                    BlockFieldSchema::normalize('team_list', $_POST, $locale),
                    // Сектор — ссылка на запись в БД, поэтому мимо схемы.
                    ['department' => trim((string) ($_POST['department'] ?? ''))]
                );
            case 'projects_list':
                return BlockFieldSchema::normalize('projects_list', $_POST, $locale);
            case 'news_latest':
                return array_merge(
                    BlockFieldSchema::normalize('news_latest', $_POST, $locale),
                    ['category' => max(0, (int) ($_POST['category'] ?? 0))]
                );
            case 'partners':
                $items = [];
                foreach ((array) ($_POST['items'] ?? []) as $item) {
                    $logo = trim((string) ($item['logo'] ?? ''));
                    if ($logo === '') {
                        continue;
                    }
                    $url = trim((string) ($item['url'] ?? ''));
                    if ($url !== '' && !\App\Core\UrlGuard::isSafeLink($url)) {
                        $url = '';
                    }
                    $items[] = [
                        'logo' => $logo,
                        'name' => trim((string) ($item['name'] ?? '')),
                        'url' => $url,
                    ];
                }
                return array_merge(
                    BlockFieldSchema::normalize('partners', $_POST, $locale),
                    ['items' => $items]
                );
            case 'subscribe':
                return SubscribeBlockNormalizer::normalize($_POST, $locale);
            case 'faq':
                return FaqBlockNormalizer::normalize($_POST, $locale);
            case 'contact_cards':
                return ContactCardsBlockNormalizer::normalize($_POST, $locale);
            case 'hero':
                return HeroBlockNormalizer::normalize($_POST, $locale);
            case 'cards_grid':
            case 'media_gallery':
                $items = [];
                foreach ((array) ($_POST['items'] ?? []) as $item) {
                    $label = trim((string) ($item['title'] ?? $item['label'] ?? ''));
                    $image = trim((string) ($item['image'] ?? ''));
                    if ($image !== '' && !\App\Core\UrlGuard::isSafeMedia($image)) {
                        $image = '';
                    }
                    if ($label === '' && ($type !== 'media_gallery' || $image === '')) {
                        continue;
                    }
                    $url = trim((string) ($item['url'] ?? ''));
                    if ($url !== '' && !\App\Core\UrlGuard::isSafeLink($url)) {
                        $url = '';
                    }
                    $iconSvg = \App\Core\Icon::cleanName($item['icon_svg'] ?? '');
                    $items[] = [
                        'icon_svg' => $iconSvg,
                        'image' => $image,
                        'title' => TextProcessor::typographPlain($label, $locale),
                        'text' => TextProcessor::typographPlain(trim((string) ($item['text'] ?? '')), $locale),
                        'meta' => TextProcessor::typographPlain(trim((string) ($item['meta'] ?? '')), $locale),
                        'kind' => ($item['kind'] ?? '') === 'photo' ? 'photo' : 'video',
                        'url' => $url,
                    ];
                }
                $collected = array_merge(
                    $type === 'cards_grid'
                        ? BlockFieldSchema::normalize('cards_grid', $_POST, $locale)
                        : BlockFieldSchema::normalize('media_gallery', $_POST, $locale),
                    ['items' => $items]
                );
                // Проекты собираются с обложками, поэтому вариант без фото им
                // не подходит; но выбор между текстом на фото и текстом под ним
                // остаётся за редактором. Это зависимость одного поля от
                // другого — схема такое не выражает.
                if (($collected['source'] ?? '') === 'projects'
                    && !in_array($collected['variant'] ?? '', ['image', 'image_below'], true)
                ) {
                    $collected['variant'] = 'image';
                }
                return $collected;
            case 'news_feature':
                return array_merge(
                    BlockFieldSchema::normalize('news_feature', $_POST, $locale),
                    ['category' => max(0, (int) ($_POST['category'] ?? 0))]
                );
            case 'person_cards':
                $items = [];
                foreach ((array) ($_POST['items'] ?? []) as $item) {
                    $name = trim((string) ($item['name'] ?? ''));
                    $role = trim((string) ($item['role'] ?? ''));
                    if ($name === '' && $role === '') {
                        continue;
                    }
                    $url = trim((string) ($item['url'] ?? ''));
                    if ($url !== '' && !\App\Core\UrlGuard::isSafeLink($url)) {
                        $url = '';
                    }
                    $items[] = [
                        'photo' => trim((string) ($item['photo'] ?? '')),
                        'name' => TextProcessor::typographPlain($name, $locale),
                        'role' => TextProcessor::typographPlain($role, $locale),
                        'phone' => trim((string) ($item['phone'] ?? '')),
                        'email' => trim((string) ($item['email'] ?? '')),
                        'url' => $url,
                    ];
                }
                return array_merge(
                    BlockFieldSchema::normalize('person_cards', $_POST, $locale),
                    ['items' => $items]
                );
            case 'timeline':
                $items = [];
                foreach ((array) ($_POST['items'] ?? []) as $item) {
                    $year = trim((string) ($item['year'] ?? ''));
                    $text = trim((string) ($item['text'] ?? ''));
                    if ($year === '' && $text === '') {
                        continue;
                    }
                    $items[] = [
                        'year' => $year,
                        'text' => TextProcessor::typographPlain($text, $locale),
                        'status' => in_array($item['status'] ?? '', ['done', 'active', 'planned'], true)
                            ? $item['status']
                            : 'planned',
                    ];
                }
                return array_merge(
                    BlockFieldSchema::normalize('timeline', $_POST, $locale),
                    ['items' => $items]
                );
            case 'news_docs':
                $docs = [];
                foreach ((array) ($_POST['docs'] ?? []) as $doc) {
                    $docTitle = trim((string) ($doc['title'] ?? ''));
                    if ($docTitle === '') {
                        continue;
                    }
                    $url = trim((string) ($doc['url'] ?? ''));
                    if ($url !== '' && !\App\Core\UrlGuard::isSafeLink($url)) {
                        $url = '';
                    }
                    $docs[] = [
                        'title' => TextProcessor::typographPlain($docTitle, $locale),
                        'meta' => trim((string) ($doc['meta'] ?? '')),
                        'url' => $url,
                    ];
                }
                return array_merge(
                    BlockFieldSchema::normalize('news_docs', $_POST, $locale),
                    [
                        'category' => max(0, (int) ($_POST['category'] ?? 0)),
                        'docs' => $docs,
                    ]
                );
            case 'icon_text':
                $iconRows = [];
                foreach ((array) ($_POST['items'] ?? []) as $item) {
                    $rows = trim((string) ($item['rows'] ?? ''));
                    $icon = \App\Core\Icon::cleanName($item['icon_svg'] ?? '');
                    if ($rows === '' && $icon === '') {
                        continue;
                    }
                    $iconRows[] = [
                        'icon_svg' => $icon,
                        // Пустой цвет = оттенок акцента сайта. Мусор в поле не
                        // должен попасть в разметку, поэтому нормализуем тем же
                        // помощником, что и цвет метки новости.
                        'icon_color' => \App\Core\NewsBadge::normalizeColor($item['icon_color'] ?? ''),
                        'rows' => TextProcessor::typographPlain($rows, $locale),
                    ];
                }

                return array_merge(
                    BlockFieldSchema::normalize('icon_text', $_POST, $locale),
                    [
                        // Мимо схемы: значение зависит от выравнивания (см. EXTRA).
                        'icon_position' => \App\Core\BlockData\BlockDataInput::enum($_POST, 'icon_position', ['left', 'top', 'right'], 'left'),
                        'items' => $iconRows,
                    ]
                );
            case 'leader_card':
                $facts = [];
                foreach ((array) ($_POST['items'] ?? []) as $item) {
                    $label = trim((string) ($item['label'] ?? ''));
                    $value = trim((string) ($item['value'] ?? ''));
                    if ($label === '' && $value === '') {
                        continue;
                    }
                    $facts[] = [
                        'icon_svg' => \App\Core\Icon::cleanName($item['icon_svg'] ?? ''),
                        'label' => TextProcessor::typographPlain($label, $locale),
                        'value' => TextProcessor::typographPlain($value, $locale),
                    ];
                }

                return array_merge(
                    BlockFieldSchema::normalize('leader_card', $_POST, $locale),
                    ['items' => $facts]
                );
            case 'person_profile':
                return BlockFieldSchema::normalize('person_profile', $_POST, $locale);
            case 'bio_education':
                $collect = static function (string $key, array $fields) use ($locale): array {
                    $rows = [];
                    foreach ((array) ($_POST[$key] ?? []) as $row) {
                        $vals = [];
                        $empty = true;
                        foreach ($fields as $f) {
                            $v = trim((string) ($row[$f] ?? ''));
                            if ($v !== '') {
                                $empty = false;
                            }
                            $vals[$f] = $f === 'years' ? $v : TextProcessor::typographPlain($v, $locale);
                        }
                        if (!$empty) {
                            $rows[] = $vals;
                        }
                    }
                    return $rows;
                };
                $collectWidgetIds = static function (string $key): array {
                    $ids = [];
                    foreach ((array) ($_POST[$key] ?? []) as $rawId) {
                        if (!is_scalar($rawId)) {
                            continue;
                        }
                        $id = (int) $rawId;
                        if ($id > 0 && !in_array($id, $ids, true)) {
                            $ids[] = $id;
                        }
                        if (count($ids) >= 12) {
                            break;
                        }
                    }
                    return $ids;
                };
                $widgetsBefore = $collectWidgetIds('widgets_before');
                $widgetsAfter = array_values(array_diff(
                    $collectWidgetIds('widgets_after'),
                    $widgetsBefore
                ));
                return array_merge(
                    BlockFieldSchema::normalize('bio_education', $_POST, $locale),
                    [
                        'career' => $collect('career', ['years', 'text']),
                        'edu_items' => $collect('edu_items', ['years', 'title', 'org']),
                        'widgets_before' => $widgetsBefore,
                        'widgets_after' => $widgetsAfter,
                    ]
                );
            case 'anchor_nav':
                $items = [];
                foreach ((array) ($_POST['items'] ?? []) as $item) {
                    $label = trim((string) ($item['label'] ?? ''));
                    $url = trim((string) ($item['url'] ?? ''));
                    if ($label === '') {
                        continue;
                    }
                    // Разрешаем якоря #... и обычные безопасные ссылки.
                    if ($url !== '' && $url[0] !== '#' && !\App\Core\UrlGuard::isSafeLink($url)) {
                        $url = '';
                    }
                    $items[] = ['label' => TextProcessor::typographPlain($label, $locale), 'url' => $url !== '' ? $url : '#'];
                }
                return array_merge(
                    BlockFieldSchema::normalize('anchor_nav', $_POST, $locale),
                    ['items' => $items]
                );
            case 'stages':
                $items = [];
                foreach ((array) ($_POST['items'] ?? []) as $item) {
                    $year = trim((string) ($item['year'] ?? ''));
                    $itemTitle = trim((string) ($item['title'] ?? ''));
                    if ($year === '' && $itemTitle === '') {
                        continue;
                    }
                    $items[] = [
                        'year' => $year,
                        'stage' => trim((string) ($item['stage'] ?? '')),
                        'title' => TextProcessor::typographPlain($itemTitle, $locale),
                        'text' => TextProcessor::typographPlain(trim((string) ($item['text'] ?? '')), $locale),
                        'status' => in_array($item['status'] ?? '', ['done', 'active', 'planned'], true) ? $item['status'] : 'planned',
                        'status_text' => trim((string) ($item['status_text'] ?? '')),
                        'url' => \App\Core\BlockData\BlockDataInput::safeLink($item['url'] ?? ''),
                    ];
                }
                return array_merge(
                    BlockFieldSchema::normalize('stages', $_POST, $locale),
                    ['items' => $items]
                );
            case 'text_image':
                $items = [];
                foreach ((array) ($_POST['items'] ?? []) as $item) {
                    $label = trim((string) ($item['label'] ?? ''));
                    if ($label === '') {
                        continue;
                    }
                    $iconSvg = \App\Core\Icon::cleanName($item['icon_svg'] ?? '');
                    $items[] = ['icon_svg' => $iconSvg, 'label' => TextProcessor::typographPlain($label, $locale)];
                }
                return array_merge(
                    BlockFieldSchema::normalize('text_image', $_POST, $locale),
                    ['items' => $items]
                );
            case 'docs_list':
                $items = [];
                foreach ((array) ($_POST['items'] ?? []) as $item) {
                    $itemTitle = trim((string) ($item['title'] ?? ''));
                    if ($itemTitle === '') {
                        continue;
                    }
                    $url = trim((string) ($item['url'] ?? ''));
                    if ($url !== '' && !\App\Core\UrlGuard::isSafeLink($url)) {
                        $url = '';
                    }
                    $items[] = [
                        'title' => TextProcessor::typographPlain($itemTitle, $locale),
                        'meta' => trim((string) ($item['meta'] ?? '')),
                        'url' => $url,
                        // Реквизиты акта: показываются только в варианте
                        // «Правовые акты», в остальных лежат про запас.
                        'number' => trim((string) ($item['number'] ?? '')),
                        'date' => trim((string) ($item['date'] ?? '')),
                    ];
                }
                return array_merge(
                    BlockFieldSchema::normalize('docs_list', $_POST, $locale),
                    ['items' => $items]
                );
            case 'map_point':
                return array_merge(
                    BlockFieldSchema::normalize('map_point', $_POST, $locale),
                    // Адрес карты принимается и ссылкой, и целым тегом <iframe>.
                    ['embed_url' => \App\Core\MapEmbedUrl::normalize($_POST['embed_url'] ?? '')]
                );
            case 'org_structure':
                $branches = [];
                foreach ((array) ($_POST['branches'] ?? []) as $branch) {
                    $bTitle = trim((string) ($branch['title'] ?? ''));
                    $bName = trim((string) ($branch['name'] ?? ''));
                    $bUnits = trim((string) ($branch['units'] ?? ''));
                    if ($bTitle === '' && $bName === '' && $bUnits === '') {
                        continue;
                    }
                    $branchUrl = trim((string) ($branch['url'] ?? ''));
                    if ($branchUrl !== '' && !\App\Core\UrlGuard::isSafeLink($branchUrl)) {
                        $branchUrl = '';
                    }
                    $branches[] = [
                        'title' => TextProcessor::typographPlain($bTitle, $locale),
                        'name' => trim($bName),
                        'url' => $branchUrl,
                        'units' => $bUnits,
                    ];
                }
                // Построчные поля сохраняются как есть: типографика съела бы
                // служебные маркеры разметки («- группа», «* проектный офис»).
                return array_merge(
                    BlockFieldSchema::normalize('org_structure', $_POST, $locale),
                    ['branches' => $branches]
                );
            default:
                return [];
        }
    }

    /**
     * Секторы команды нужны формам блоков «Команда» (фильтр) и «Оргструктура»
     * (готовые якори для ссылок). Для остальных типов запрос не делаем.
     *
     * @return list<array{name: string, slug: string, count: int}>
     */
    private static function departmentsFor(string $type): array
    {
        return in_array($type, ['team_list', 'org_structure'], true)
            ? \App\Models\TeamMember::departments()
            : [];
    }
}
