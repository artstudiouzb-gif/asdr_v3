-- Обложка (Hero) как отдельный тип контента.
--
-- До этой миграции обложка жила целиком внутри блока страницы: и оформление,
-- и слайды хранились в `blocks.data`. Из-за этого одну и ту же обложку нельзя
-- было показать на двух страницах — её приходилось собирать заново, и дальше
-- две копии расходились. Планировать показ (праздник, кампания) тоже было
-- нечем: расписание есть у блока, но не у набора слайдов.
--
-- Теперь обложка — самостоятельная запись со своим набором слайдов, а блок
-- страницы только выбирает, какую обложку вывести (`blocks.data.hero_id`).
-- Старые блоки с собственными слайдами продолжают работать: hero_id у них
-- пуст, и рендер идёт прежним путём.
--
-- Общие настройки обложки (высота, навигация, автопрокрутка, переход, цветовая
-- схема) и все поля слайда хранятся в JSON: набор полей у конструктора меняется
-- часто, и колонка на каждое поле превратила бы таблицу в свалку ALTER'ов.
-- В колонки вынесено только то, по чему идут выборка и сортировка.

CREATE TABLE IF NOT EXISTS heroes (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(190) NOT NULL COMMENT 'название для админки, на сайт не выводится',
    status         VARCHAR(16) NOT NULL DEFAULT 'draft' COMMENT 'draft|published|scheduled',
    published_from DATETIME NULL COMMENT 'начало показа (для scheduled)',
    published_to   DATETIME NULL COMMENT 'конец показа (для scheduled)',
    priority       INT NOT NULL DEFAULT 0 COMMENT 'больше — важнее при совпадении окон',
    preset         VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'последний применённый пресет',
    settings       LONGTEXT NULL COMMENT 'JSON: общие настройки обложки',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at     DATETIME NULL,
    KEY idx_heroes_listing (deleted_at, status, priority, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Слайд обложки. Порядок хранится числом и переписывается при перетаскивании:
-- сортировка по нему же идёт и на сайте, поэтому «как в админке» = «как на
-- странице», без второго источника правды.
CREATE TABLE IF NOT EXISTS hero_slides (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hero_id    INT UNSIGNED NOT NULL,
    title      VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'заголовок на основном языке (он же подпись в списке)',
    sort_order INT NOT NULL DEFAULT 0,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    data       LONGTEXT NULL COMMENT 'JSON: поля слайда',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_hero_slides_order (hero_id, sort_order, id),
    CONSTRAINT fk_hero_slides_hero FOREIGN KEY (hero_id) REFERENCES heroes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Перевод слайда (механизм А): базовый язык лежит в hero_slides.data, языковые
-- варианты — здесь. Переводится только текст; медиа, цвета и раскладка общие,
-- иначе редактор чинил бы композицию отдельно на каждом языке.
CREATE TABLE IF NOT EXISTS hero_slide_translations (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slide_id     INT UNSIGNED NOT NULL,
    lang         VARCHAR(8) NOT NULL,
    eyebrow      VARCHAR(255) NULL,
    title        VARCHAR(255) NULL,
    subtitle     TEXT NULL,
    cta_text     VARCHAR(190) NULL,
    cta2_text    VARCHAR(190) NULL,
    art_alt      VARCHAR(255) NULL,
    UNIQUE KEY uq_hero_slide_translations (slide_id, lang),
    CONSTRAINT fk_hero_slide_translations_slide
        FOREIGN KEY (slide_id) REFERENCES hero_slides(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
