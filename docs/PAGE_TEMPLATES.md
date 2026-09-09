# Шаблоны страниц: обмен файлами

Шаблон страницы — это сохранённый набор блоков, который применяется к любой
странице (добавлением к текущим блокам или полной заменой). Раньше шаблон жил
только в базе своего сайта; теперь его можно **скачать файлом** и **загрузить
обратно** — в том числе на другом сайте или полученным от кого-то готовым.

Обе кнопки — в редакторе страницы, в секции «Шаблоны страницы».

## Как это работает

1. **Скачать.** Выбираете шаблон из списка → «Скачать файлом» → получаете JSON.
2. **Загрузить.** «Загрузить из файла» → шаблон появляется в списке шаблонов.
3. **Применить.** Дальше — как обычно: выбрать шаблон, «Добавить к текущим» или
   «Заменить текущие блоки». Перед заменой прежние блоки сами сохраняются как
   «Автокопия: …».

Загрузка **не создаёт страницу** и ничего не заменяет сама — она только
пополняет библиотеку шаблонов. Это сделано намеренно: файл из чужих рук не
должен уметь переписать страницу одним нажатием.

## Формат файла

```json
{
  "kind": "artstudio.page-template",
  "version": 1,
  "name": "Название шаблона",
  "blocks": [
    {
      "type": "text",
      "title": "Заголовок секции (для админки, необязательно)",
      "data": { "content": "<p>Текст</p>" },
      "custom_css": "",
      "is_active": 1
    }
  ]
}
```

- `kind` — обязательная пометка. Без неё файл не примут: иначе об ошибке
  становится известно только после импорта, когда собралось не то.
- `version` — версия формата. Файл более новой версии не примут.
- `name` — название в библиотеке. При загрузке его можно перебить своим.
- `blocks` — список блоков, до 300 штук. Размер файла — до 2 МБ.

### Блок

| Поле | Что это |
| --- | --- |
| `type` | Тип блока из таблицы ниже. Неизвестный тип — блок пропускается. |
| `title` | Заголовок блока в списке админки. Необязателен. |
| `data` | Поля этого типа (таблица ниже) плюс оформление секции (ключи с `_`). |
| `custom_css` | Свой CSS блока. Принимается только у супер-администратора. |
| `is_active` | `1` — блок включён, `0` — выключен. По умолчанию `1`. |
| `children` | Вложенные блоки. Только у контейнеров `columns` и `tabs`. |

У вложенного блока те же поля плюс `column_index` — номер колонки или вкладки
(с нуля). Контейнер в контейнер не вкладывается.

### Оформление секции

Ключи с подчёркиванием в начале — общие для всех типов: `_bg`, `_bg_mode`,
`_bg_color`, `_spacing`, `_pad_top`, `_pad_bottom`, `_fullwidth`, `_surface`,
`_reveal`, `_min_height`, `_watermark*`, `_visible_from`, `_visible_to`,
`_visible_device` и другие. Их можно не указывать — тогда возьмутся умолчания.

Все они проходят через тот же обработчик, что и форма блока в админке: чужое
значение откатывается к умолчанию, опасный адрес картинки вычищается.

## Что проверяется при загрузке

- Тип блока есть в системе.
- Поля `data` есть у этого типа. Лишние отбрасываются — они всё равно
  потерялись бы при первом сохранении в админке.
- Вложенные блоки только у контейнеров, без второго уровня.
- Окно показа не перевёрнуто (`_visible_to` не раньше `_visible_from`).
- Блок «HTML-код» и `custom_css` — только для супер-администратора.

Всё снятое и отброшенное показывается сообщением после загрузки. Молча ничего
не теряется — если сообщение пустое, шаблон приехал целиком.

## Типы блоков и их поля

| `text` | Текст | `variant`, `title`, `content`, `aside_title`, `items`, `quote`, `media_type`, `media_image`, `media_video`, `media_youtube`, `media_alt`, `media_caption`, `image_position`, `image_position_mobile` |
| `html` | Произвольный HTML | `html` |
| `cta` | Призыв к действию | `variant`, `title`, `text`, `icon_svg`, `image`, `image_position`, `image_position_mobile`, `button_text`, `button_url`, `bg_color`, `text_color`, `button_color` |
| `advantages` | Преимущества | `variant`, `title`, `description`, `all_text`, `all_url`, `columns`, `items` |
| `slider` | Слайдер | `title`, `autoplay`, `ratio`, `slides` |
| `form` | Форма | `form_id` |
| `columns` | Колонки | `columns`, `gap`, `ratio` |
| `tabs` | Вкладки | `variant`, `title`, `description`, `align`, `items` |
| `testimonials` | Отзывы | `variant`, `title`, `description`, `columns`, `autoplay`, `items` |
| `counters` | Счётчики | `title`, `card_bg`, `text_color`, `icon_size`, `icon_bg`, `icon_position`, `text_align`, `variant`, `value_size`, `items` |
| `team_list` | Команда | `title`, `limit`, `department`, `group_by_department` |
| `projects_list` | Проекты | `variant`, `title`, `description`, `all_text`, `all_url`, `columns`, `limit`, `autoplay` |
| `news_latest` | Последние новости | `title`, `all_text`, `all_url`, `limit`, `category` |
| `partners` | Партнёры | `title`, `description`, `all_text`, `all_url`, `columns`, `logo_size`, `grayscale`, `autoplay`, `items` |
| `subscribe` | Подписка | `variant`, `title`, `text`, `image`, `placeholder`, `note`, `button_text` |
| `faq` | Вопросы и ответы | `title`, `search_enabled`, `single_open`, `items` |
| `contact_cards` | Контакты | `variant`, `title`, `line_icons`, `icon_size`, `icon_bg`, `items` |
| `hero` | Обложка | `hero_id`, `title`, `eyebrow`, `subtitle`, `bg_type`, `image`, `image_mobile`, `image_position`, `image_position_mobile`, `video_url`, `video_mobile`, `youtube_url`, `bg_color`, `width`, `height`, `custom_height`, `height_mobile`, `custom_height_mobile`, `overlay_enabled`, `overlay_mode`, `overlay_direction`, `overlay_color`, `overlay_opacity`, `text_position`, `text_align_y`, `text_width`, `text_color`, `art_image`, `art_alt`, `art_position`, `art_size`, `button_color`, `panel_enabled`, `panel_color`, `panel_opacity`, `button_text`, `button_url`, `button_icon`, `button_icon_image`, `button2_text`, `button2_url`, `button2_icon`, `button2_icon_image`, `video_button_text`, `video_button_url`, `slides`, `autoplay` |
| `cards_grid` | Карточки | `variant`, `title`, `all_text`, `all_url`, `columns`, `card_bg`, `text_color`, `source`, `limit`, `image_position`, `image_position_mobile`, `items` |
| `media_gallery` | Медиагалерея | `title`, `description`, `all_text`, `all_url`, `source`, `limit`, `paginate`, `columns`, `ratio`, `items` |
| `news_feature` | Новости и аналитика | `variant`, `title`, `all_text`, `all_url`, `limit`, `category` |
| `person_cards` | Карточки персон | `title`, `description`, `all_text`, `all_url`, `columns`, `items` |
| `timeline` | Хронология | `title`, `description`, `items`, `button_text`, `button_url`, `cta_title`, `cta_text`, `cta_button_text`, `cta_button_url`, `cta_image` |
| `news_docs` | Новости и документы | `news_title`, `news_all_text`, `news_all_url`, `limit`, `category`, `docs_title`, `docs_all_text`, `docs_all_url`, `docs` |
| `person_profile` | Профиль персоны | `photo`, `photo_side`, `name`, `position`, `text`, `phone`, `phone_label`, `email`, `email_label`, `button_text`, `button_url`, `button2_text`, `button2_url`, `telegram`, `facebook`, `linkedin`, `x`, `instagram` |
| `bio_education` | Биография и образование | `bio_title`, `bio_text`, `career_title`, `career`, `edu_title`, `edu_items`, `extra_title`, `extra_text`, `widgets_before`, `widgets_after`, `quote_text`, `quote_author` |
| `anchor_nav` | Якорная навигация | `items`, `auto`, `sticky` |
| `stages` | Этапы | `variant`, `title`, `description`, `all_text`, `all_url`, `columns`, `autoplay`, `items` |
| `text_image` | Текст с фото | `title`, `text`, `image`, `image_position`, `image_position_mobile`, `image_side`, `image_ratio`, `image_width`, `button_text`, `button_url`, `items` |
| `docs_list` | Список документов | `variant`, `title`, `all_text`, `all_url`, `columns`, `search_enabled`, `emblem`, `items` |
| `map_point` | Карта | `title`, `image`, `embed_url`, `load_mode`, `card_title`, `address`, `copy_enabled`, `button_text`, `button_url` |
| `org_structure` | Оргструктура | `title`, `layout`, `columns`, `council`, `head_title`, `head_name`, `head_url`, `side_items`, `branches`, `collapsible`, `search`, `notes`, `footnote` |
| `leader_card` | Карточка руководителя | `photo`, `name`, `name_tag`, `position`, `phone`, `email`, `hours`, `facebook`, `x`, `linkedin`, `instagram`, `telegram`, `facts_title`, `facts_icon`, `items`, `bio_title`, `bio_icon`, `bio`, `duties_title`, `duties_icon`, `duties`, `mobile_icons_only` |
| `icon_text` | Иконка и текст | `variant`, `title`, `description`, `icon_position`, `align`, `rows_layout`, `columns`, `items` |

Контейнеры — `columns` и `tabs`: их содержимое лежит в `children`.

## Пример: страница из трёх секций

```json
{
  "kind": "artstudio.page-template",
  "version": 1,
  "name": "Простая страница раздела",
  "blocks": [
    {
      "type": "text",
      "title": "Вступление",
      "data": {
        "title": "О разделе",
        "content": "<p>Короткое вступление.</p>",
        "_bg": "navy",
        "_pad_top": "large"
      }
    },
    {
      "type": "advantages",
      "title": "Направления",
      "data": {
        "title": "Наши направления",
        "columns": 3,
        "items": [
          { "title": "Первое", "text": "Описание" },
          { "title": "Второе", "text": "Описание" },
          { "title": "Третье", "text": "Описание" }
        ]
      }
    },
    {
      "type": "cta",
      "data": {
        "title": "Остались вопросы?",
        "text": "Напишите нам.",
        "button_text": "Контакты",
        "button_url": "/kontakty"
      }
    }
  ]
}
```

## Где это в коде

- Формат и разбор — `App\Core\PageTemplateFile`.
- Выгрузка и загрузка — `App\Controllers\Admin\SnippetController::export/import`.
- Хранение и применение — `App\Models\BlockSnippet` (таблица `block_snippets`).
- Кнопки — `app/Views/admin/pages/_block_editor.php`, секция «Шаблоны страницы».
