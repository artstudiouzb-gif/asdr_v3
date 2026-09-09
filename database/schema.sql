-- ASDR CMS — полная схема базы данных
-- MySQL 8.0+ / MariaDB 10.5+
-- Кодировка: utf8mb4, движок: InnoDB

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- Пользователи админ-панели
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(60)  NOT NULL,
    email           VARCHAR(190) NOT NULL,
    phone           VARCHAR(20)  NULL COMMENT 'телефон в формате E.164 (+998...) для кода входа через Telegram',
    telegram_chat_id BIGINT      NULL COMMENT 'chat_id привязанного Telegram-аккаунта (коды входа через бота)',
    password_hash   VARCHAR(255) NOT NULL,
    totp_secret     TEXT         NULL,
    totp_enabled    TINYINT(1)   NOT NULL DEFAULT 0,
    totp_last_step  BIGINT       NULL DEFAULT NULL COMMENT 'последний принятый шаг TOTP (защита от повтора кода)',
    role            ENUM('admin', 'editor') NOT NULL DEFAULT 'admin',
    admin_lang      VARCHAR(8) NULL COMMENT 'предпочитаемый язык интерфейса админки (ru, uz, en)',
    last_login_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Попытки входа (для Rate Limiting / защиты от перебора)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier      VARCHAR(190) NOT NULL COMMENT 'ip|username или ip|2fa|username',
    ip_address      VARCHAR(45)  NOT NULL,
    success         TINYINT(1)   NOT NULL DEFAULT 0,
    attempted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_identifier (identifier, attempted_at),
    KEY idx_login_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Категории новостей (slug один на все языки, название переводится)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS news_categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL COMMENT 'название на основном языке',
    slug        VARCHAR(150) NOT NULL COMMENT 'один на все языки: адрес и выборки',
    icon        VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'ключ иконки Tabler',
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_news_categories_slug (slug),
    KEY idx_news_categories_order (is_active, sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_category_translations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    lang        VARCHAR(8) NOT NULL,
    name        VARCHAR(150) NULL,
    UNIQUE KEY uq_news_category_translations (category_id, lang),
    CONSTRAINT fk_news_category_translations_category
        FOREIGN KEY (category_id) REFERENCES news_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Обложки (Hero) — отдельный тип контента: запись со своим набором слайдов.
-- Блок страницы только выбирает обложку (blocks.data.hero_id), поэтому одну
-- обложку можно вывести на нескольких страницах, не размножая содержимое.
-- ---------------------------------------------------------------------------
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
    watermark    VARCHAR(120) NULL,
    UNIQUE KEY uq_hero_slide_translations (slide_id, lang),
    CONSTRAINT fk_hero_slide_translations_slide
        FOREIGN KEY (slide_id) REFERENCES hero_slides(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Новости
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS news (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(255) NOT NULL,
    slug            VARCHAR(255) NOT NULL,
    excerpt         TEXT NULL,
    lead_html       LONGTEXT NULL COMMENT 'форматированный лид; excerpt — его текстовая версия',
    badge           VARCHAR(100) NULL COMMENT 'визуальная метка («Важно», «Анонс»); не категория',
    badge_color     VARCHAR(9) NULL COMMENT 'цвет фона метки (#rrggbb); пусто — цвет темы',
    card_title     VARCHAR(200) NULL COMMENT 'заголовок на обложке; пусто — обычный заголовок',
    card_badge    VARCHAR(100) NULL COMMENT 'вторая плашка обложки (контурная)',
    card_stats     TEXT NULL COMMENT 'показатели обложки: JSON [{value,label}], до трёх',
    card_signature VARCHAR(80) NULL COMMENT 'подпись рукописным шрифтом',
    card_note      VARCHAR(120) NULL COMMENT 'сноска внизу обложки',
    category_id     INT UNSIGNED NULL COMMENT 'категория новости',
    content         LONGTEXT NULL,
    image           VARCHAR(255) NULL,
    video_url       VARCHAR(255) NULL,
    audio_url       VARCHAR(500) NULL COMMENT 'ссылка на аудиозапись / подкаст',
    audio_title     VARCHAR(255) NULL COMMENT 'название аудиозаписи',
    hashtags        VARCHAR(500) NULL COMMENT 'хештеги новости (#тег1 #тег2)',
    press_release_url VARCHAR(255) NULL,
    key_points      TEXT NULL COMMENT 'ключевые тезисы, по одному на строку',
    event_meta      TEXT NULL COMMENT 'карточка «О мероприятии», по строке на пункт',
    timeline_json   JSON NULL COMMENT 'хроника событий [{date, title, text}]',
    docs            TEXT NULL COMMENT 'JSON-список документов [{title, meta, url}]',
    source_note     VARCHAR(255) NULL COMMENT 'подпись источника (пресс-служба)',
    views           INT UNSIGNED NOT NULL DEFAULT 0,
    layout_type     ENUM('standard','gallery','video','side_image','premium','card') NOT NULL DEFAULT 'standard',
    sidebar_layout  ENUM('no_sidebar','left_sidebar','right_sidebar') NOT NULL DEFAULT 'right_sidebar',
    focal_x         TINYINT UNSIGNED NULL COMMENT 'фокальная точка обложки X, %',
    focal_y         TINYINT UNSIGNED NULL COMMENT 'фокальная точка обложки Y, %',
    meta_title      VARCHAR(255) NULL,
    meta_description VARCHAR(500) NULL,
    status          ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
    published_at    DATETIME NULL,
    author_id       INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    lock_version    INT UNSIGNED NOT NULL DEFAULT 1,
    deleted_at      DATETIME NULL COMMENT 'мягкое удаление (корзина)',
    lang            VARCHAR(8) NOT NULL DEFAULT 'ru',
    translation_group_id INT UNSIGNED NULL,
    UNIQUE KEY uq_news_slug_lang (slug, lang),
    KEY idx_news_listing (status, deleted_at, published_at),
    KEY idx_news_category_listing (category_id, status, deleted_at, published_at),
    KEY idx_news_lang_group (translation_group_id, lang),
    CONSTRAINT fk_news_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_news_category FOREIGN KEY (category_id) REFERENCES news_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Переводы новостей (для НЕ-дефолтных языков)
CREATE TABLE IF NOT EXISTS news_translations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id         INT UNSIGNED NOT NULL,
    lang            VARCHAR(8) NOT NULL,
    title           VARCHAR(255) NULL,
    badge           VARCHAR(100) NULL COMMENT 'бейдж категории',
    card_title     VARCHAR(200) NULL,
    card_badge    VARCHAR(100) NULL,
    card_stats     TEXT NULL,
    card_signature VARCHAR(80) NULL,
    card_note      VARCHAR(120) NULL,
    excerpt         TEXT NULL,
    lead_html       LONGTEXT NULL COMMENT 'форматированный лид перевода',
    content         LONGTEXT NULL,
    hashtags        VARCHAR(500) NULL COMMENT 'хештеги новости',
    key_points      TEXT NULL COMMENT 'ключевые тезисы, по одному на строку',
    event_meta      TEXT NULL COMMENT 'карточка О мероприятии',
    timeline_json   JSON NULL COMMENT 'хроника событий',
    docs            TEXT NULL COMMENT 'JSON-список документов',
    poll_question   VARCHAR(255) NULL COMMENT 'вопрос опроса',
    poll_options_json JSON NULL COMMENT 'варианты ответов опроса',
    meta_title      VARCHAR(255) NULL,
    meta_description VARCHAR(500) NULL,
    UNIQUE KEY uq_news_translations (news_id, lang),
    CONSTRAINT fk_news_translations_news FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Галерея фотографий новости (этап 12.1)
CREATE TABLE IF NOT EXISTS news_images (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id     INT UNSIGNED NOT NULL,
    path        VARCHAR(255) NOT NULL,
    alt_text    VARCHAR(255) NULL,
    caption     VARCHAR(255) NULL COMMENT 'видимая подпись под фото',
    credit      VARCHAR(255) NULL COMMENT 'автор или источник (Фото: …)',
    focal_x     TINYINT UNSIGNED NULL COMMENT 'фокальная точка X, %',
    focal_y     TINYINT UNSIGNED NULL COMMENT 'фокальная точка Y, %',
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_news_images_news (news_id, sort_order),
    CONSTRAINT fk_news_images_news FOREIGN KEY (news_id) REFERENCES news (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Опросы в новостях
CREATE TABLE IF NOT EXISTS news_polls (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id     INT UNSIGNED NOT NULL,
    question    VARCHAR(255) NOT NULL,
    options_json JSON NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_news_polls_news (news_id),
    CONSTRAINT fk_news_polls_news FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_poll_votes (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    poll_id      INT UNSIGNED NOT NULL,
    option_index INT UNSIGNED NOT NULL,
    voter_hash   VARCHAR(64) NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_news_poll_voter (poll_id, voter_hash),
    KEY idx_news_poll_votes_poll (poll_id, option_index),
    CONSTRAINT fk_news_poll_votes_poll FOREIGN KEY (poll_id) REFERENCES news_polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Языки сайта (управляемый список для мультиязычности)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS languages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(8) NOT NULL COMMENT 'ISO-код: ru, uz, en',
    name            VARCHAR(60) NOT NULL COMMENT 'отображаемое название: Русский, Oʻzbekcha',
    short_name      VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'короткое название для переключателя: Рус, Oʻzb',
    is_default      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'язык по умолчанию (URL без префикса)',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    sort_order      INT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_languages_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO languages (code, name, short_name, is_default, is_active, sort_order) VALUES
    ('ru', 'Русский', 'Рус', 1, 1, 0),
    ('uz', 'Oʻzbekcha', 'Oʻzb', 0, 1, 1)
ON DUPLICATE KEY UPDATE code = code;

-- ---------------------------------------------------------------------------
-- Страницы (статические, собираются из блоков)
-- Каждый язык = отдельная строка pages; версии связаны translation_group_id.
-- page_translations оставлена только для совместимости со старыми установками.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(255) NOT NULL COMMENT 'заголовок на языке по умолчанию',
    slug            VARCHAR(255) NOT NULL,
    entity_type     ENUM('page', 'project') NOT NULL DEFAULT 'page' COMMENT 'подтип записи: обычная страница или проект',
    section         VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'раздел, шапкой которого служит страница: news|projects',
    meta_title      VARCHAR(255) NULL,
    meta_description VARCHAR(500) NULL,
    `lead`          TEXT NULL COMMENT 'видимый лид/подзаголовок страницы; у проекта — анонс для карточки',
    cover_image     VARCHAR(255) NULL COMMENT 'обложка (проекты)',
    is_featured     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'показывать на главном (проекты)',
    sort_order      INT NOT NULL DEFAULT 0 COMMENT 'ручной порядок (проекты)',
    status          ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
    is_home         TINYINT(1) NOT NULL DEFAULT 0,
    layout_type     ENUM('no_sidebar', 'left_sidebar', 'right_sidebar') NOT NULL DEFAULT 'no_sidebar',
    hide_chrome     TINYINT(1) NOT NULL DEFAULT 0,
    transparent_header TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'прозрачная шапка на этой странице',
    custom_css      TEXT NULL COMMENT 'постраничный CSS / внешние стили',
    custom_js       TEXT NULL COMMENT 'постраничный JS / внешние скрипты',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    lock_version    INT UNSIGNED NOT NULL DEFAULT 1,
    deleted_at      DATETIME NULL COMMENT 'мягкое удаление (корзина)',
    lang            VARCHAR(8) NOT NULL DEFAULT 'ru',
    translation_group_id INT UNSIGNED NULL,
    parent_id       INT UNSIGNED NULL COMMENT 'родительская страница; URL страницы остаётся плоским',
    UNIQUE KEY uq_pages_type_slug_lang (entity_type, slug, lang),
    KEY idx_pages_lang_group (translation_group_id, lang),
    KEY idx_pages_parent (parent_id),
    KEY idx_pages_projects (entity_type, status, deleted_at, is_featured, sort_order),
    KEY idx_pages_section (section, lang, status),
    CONSTRAINT fk_pages_parent FOREIGN KEY (parent_id) REFERENCES pages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- is_home = 1 хранится у канонической страницы; её языковые версии находятся
-- по translation_group_id и общему slug.

-- Устаревшее хранилище переводов заголовка/мета для совместимости.
CREATE TABLE IF NOT EXISTS page_translations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id         INT UNSIGNED NOT NULL,
    lang            VARCHAR(8) NOT NULL,
    title           VARCHAR(255) NULL,
    meta_title      VARCHAR(255) NULL,
    meta_description VARCHAR(500) NULL,
    `lead`          TEXT NULL,
    UNIQUE KEY uq_page_translations (page_id, lang),
    CONSTRAINT fk_page_translations_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Блоки конструктора страниц (Page Builder)
-- Каждый блок принадлежит паре (page_id, lang): у каждого языка свой стек блоков.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS blocks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id         INT UNSIGNED NOT NULL,
    parent_block_id INT UNSIGNED NULL COMMENT 'родительский блок columns (группа 4.1); NULL = верхний уровень',
    column_index    INT NOT NULL DEFAULT 0 COMMENT 'номер колонки внутри родителя columns',
    lang            VARCHAR(8) NOT NULL DEFAULT '' COMMENT 'код языка стека блоков',
    type            VARCHAR(60) NOT NULL COMMENT 'Block type registered in App\\Core\\BlockTypeRegistry',
    title           VARCHAR(255) NULL COMMENT 'внутреннее название блока для админки',
    data            JSON NOT NULL COMMENT 'структурированные данные блока',
    custom_css      TEXT NULL COMMENT 'CSS блока, изолируется при рендере через #block-{id}',
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'блок выводится на сайте (0 — скрыт, но не удалён)',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    lock_version    INT UNSIGNED NOT NULL DEFAULT 1,
    KEY idx_blocks_page (page_id, lang, sort_order),
    KEY idx_blocks_parent (parent_block_id, column_index, sort_order),
    CONSTRAINT fk_blocks_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
    CONSTRAINT fk_blocks_parent FOREIGN KEY (parent_block_id) REFERENCES blocks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- История версий блоков (группа 5.1). Снимок состояния блока перед каждой
-- перезаписью; хранятся последние 20 ревизий на блок.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS block_revisions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    block_id        INT UNSIGNED NOT NULL,
    title           VARCHAR(255) NULL,
    data            JSON NOT NULL,
    custom_css      TEXT NULL,
    created_by      INT UNSIGNED NULL COMMENT 'автор изменения (users.id), NULL если неизвестен',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_block_revisions_block (block_id, id),
    KEY idx_block_revisions_user (created_by),
    CONSTRAINT fk_block_revisions_block FOREIGN KEY (block_id) REFERENCES blocks(id) ON DELETE CASCADE,
    CONSTRAINT fk_block_revisions_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Полная история версий сущностей контента (страницы, новости, проекты).
CREATE TABLE IF NOT EXISTS content_revisions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type     VARCHAR(20) NOT NULL,
    entity_id       INT UNSIGNED NOT NULL,
    snapshot        LONGTEXT NOT NULL,
    snapshot_hash   CHAR(64) NOT NULL,
    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_content_revisions_entity (entity_type, entity_id, id),
    KEY idx_content_revisions_created (created_at),
    CONSTRAINT fk_content_revisions_user
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Проекты (портфолио)
--
-- Отдельных таблиц у проектов нет: проект — это страница с подтипом
-- (`pages.entity_type = 'project'`). У него тот же конструктор блоков, те же
-- ревизии и тот же механизм языков (отдельная запись своего языка, связанная
-- через translation_group_id). Обложка, отметка «на главном» и ручной порядок
-- живут колонками pages, анонс карточки — в pages.lead.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- Команда
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS team_members (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(190) NOT NULL,
    position        VARCHAR(190) NULL,
    department      VARCHAR(190) NULL COMMENT 'Сектор — верхний уровень группировки раздела «Команда»',
    unit            VARCHAR(190) NULL COMMENT 'Отдел или группа внутри сектора',
    photo           VARCHAR(255) NULL,
    email           VARCHAR(190) NULL,
    phone           VARCHAR(60) NULL,
    socials_json    JSON NULL COMMENT '{"facebook": "...", "instagram": "...", "telegram": "..."}',
    status          ENUM('draft', 'published') NOT NULL DEFAULT 'published',
    sort_order      INT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_team_members_listing (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Переводы сотрудников команды (имя, должность и подразделение на неосновных языках)
CREATE TABLE IF NOT EXISTS team_member_translations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id   INT UNSIGNED NOT NULL,
    lang        VARCHAR(8) NOT NULL,
    name        VARCHAR(190) NULL,
    position    VARCHAR(190) NULL,
    department  VARCHAR(190) NULL,
    unit        VARCHAR(190) NULL,
    UNIQUE KEY uq_team_member_translations (member_id, lang),
    CONSTRAINT fk_team_member_translations_member FOREIGN KEY (member_id) REFERENCES team_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Конструктор форм обратной связи
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS forms (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(190) NOT NULL,
    slug            VARCHAR(190) NOT NULL,
    fields_json     JSON NOT NULL COMMENT '[{"name":"phone","label":"Телефон","type":"tel","required":true}, ...]',
    notify_email    VARCHAR(190) NULL,
    success_message VARCHAR(500) NULL DEFAULT 'Спасибо! Ваша заявка отправлена.',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_forms_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Заявки, отправленные через формы
CREATE TABLE IF NOT EXISTS form_submissions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id         INT UNSIGNED NOT NULL,
    data_json       JSON NOT NULL,
    ip_address      VARCHAR(45) NULL,
    user_agent      VARCHAR(500) NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_form_submissions_form (form_id, created_at),
    CONSTRAINT fk_form_submissions_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Глобальные настройки сайта (логотип, цвета, шрифты, контакты, счётчики)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`           VARCHAR(100) NOT NULL,
    `value`         LONGTEXT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_settings_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (`key`, `value`) VALUES
    ('site_name', 'Агентство стратегического развития и реформ'),
    ('logo_url', ''),
    -- Цвета и шрифты намеренно не сеются: пустое значение означает «взять
    -- значение по умолчанию из кода» (SiteThemeCss), поэтому фирменная пара
    -- задаётся в одном месте, а не дублируется в дампе схемы.
    ('contact_phone', ''),
    ('contact_email', ''),
    ('contact_address', ''),
    ('counter_codes', ''),
    ('telegram_gateway_token', ''),
    ('telegram_bot_token', ''),
    ('header_config', '{"logo_position":"left","menu_position":"right","language_switcher":{"enabled":true,"format":"code"},"social_buttons":[],"cta":{"enabled":false,"text":"","url":"","style":"filled"}}')
ON DUPLICATE KEY UPDATE `key` = `key`;

-- ---------------------------------------------------------------------------
-- Файловый менеджер (публичные и защищённые файлы)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS files (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_name   VARCHAR(255) NOT NULL,
    stored_name     VARCHAR(255) NOT NULL COMMENT 'имя файла на диске (случайное, без user input)',
    mime_type       VARCHAR(120) NOT NULL,
    size            BIGINT UNSIGNED NOT NULL,
    access_type     ENUM('public', 'protected') NOT NULL DEFAULT 'public',
    access_token    VARCHAR(64) NULL COMMENT 'токен для доступа к protected-файлу без сессии',
    download_count  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by     INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_files_access_type (access_type),
    CONSTRAINT fk_files_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Пункты меню шапки (конструктор меню)
-- lang: обязательный код языка пункта. Пустое значение поддерживается только
-- для чтения старых данных и не показывается в публичном меню.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS menu_items (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lang            VARCHAR(8) NOT NULL DEFAULT '' COMMENT 'код языка; пусто только для старых данных',
    title           VARCHAR(190) NOT NULL,
    icon_svg        TEXT NULL COMMENT 'ключ локальной иконки Tabler',
    badge_text      VARCHAR(100) NULL COMMENT 'текст бейджа/плашки (напр. АКТУАЛЬНО!, NEW)',
    badge_color     VARCHAR(30) NULL DEFAULT 'red' COMMENT 'цвет бейджа (red, green, blue, orange, purple)',
    badge_pos       VARCHAR(20) NOT NULL DEFAULT 'right' COMMENT 'позиция бейджа (right, left, center)',
    is_divider      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'пункт-разделитель меню',
    hide_title      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'скрыть текст пункта, оставив иконку',
    url_type        ENUM('page', 'news_index', 'custom') NOT NULL DEFAULT 'custom',
    url_value       VARCHAR(500) NULL COMMENT 'slug страницы или произвольный URL',
    parent_id       INT UNSIGNED NULL,
    mega_columns    TINYINT NOT NULL DEFAULT 0 COMMENT '0 — обычное подменю, 2..4 — мега-меню в N колонок',
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_menu_items_lang (lang, sort_order),
    CONSTRAINT fk_menu_items_parent FOREIGN KEY (parent_id) REFERENCES menu_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Модульные боковые виджеты (Sidebar Engine)
-- sidebar: в какую колонку; lang: пусто = все языки, иначе конкретный язык.
-- data: JSON-настройки виджета (зависят от type).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS widgets (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sidebar         ENUM('left', 'right') NOT NULL,
    type            VARCHAR(60) NOT NULL COMMENT 'latest_news, contacts, custom_html, projects_list, team_list',
    title           VARCHAR(190) NULL COMMENT 'заголовок виджета на сайте',
    lang            VARCHAR(8) NOT NULL DEFAULT '' COMMENT 'код языка или пусто для всех',
    data            JSON NOT NULL,
    sort_order      INT NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_widgets_sidebar (sidebar, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Подписчики email-дайджеста новостей
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscribers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(190) NOT NULL,
    token           VARCHAR(64) NOT NULL,
    status          ENUM('active', 'unsubscribed') NOT NULL DEFAULT 'active',
    lang            VARCHAR(8) NOT NULL DEFAULT 'ru',
    source          VARCHAR(32) NOT NULL DEFAULT 'website',
    consent_at      DATETIME NULL,
    unsubscribed_at DATETIME NULL,
    last_digest_at  DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_subscribers_email (email),
    UNIQUE KEY uniq_subscribers_token (token),
    KEY idx_subscribers_status_lang (status, lang, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Очередь исходящих писем (обрабатывается CLI-воркером по Cron)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mail_queue (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    to_email        VARCHAR(190) NOT NULL,
    to_name         VARCHAR(190) NULL,
    subscriber_id   INT UNSIGNED NULL,
    purpose         VARCHAR(30) NOT NULL DEFAULT 'transactional',
    dedupe_key      VARCHAR(190) NULL,
    subject         VARCHAR(255) NOT NULL,
    body            LONGTEXT NOT NULL,
    status          ENUM('pending', 'sent', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    attempts        INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until    DATETIME NULL,
    last_error      VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at         DATETIME NULL,
    KEY idx_mail_queue_status (status, created_at),
    KEY idx_mail_queue_subscriber (subscriber_id, purpose, status),
    UNIQUE KEY uq_mail_queue_dedupe (dedupe_key),
    CONSTRAINT fk_mail_queue_subscriber FOREIGN KEY (subscriber_id) REFERENCES subscribers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Безопасность (Блок 11): сброс пароля, backup-коды 2FA, реестр сессий.
-- Все токены/коды хранятся как SHA-256 хеши; сравнение через hash_equals.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64)     NOT NULL COMMENT 'sha256(token)',
    expires_at  DATETIME     NOT NULL,
    used_at     DATETIME     NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_password_resets_hash (token_hash),
    KEY idx_password_resets_user (user_id),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_codes (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    code_hash   CHAR(64)     NOT NULL COMMENT 'sha256(code)',
    used_at     DATETIME     NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_backup_codes_user (user_id),
    CONSTRAINT fk_backup_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sessions (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    sid_hash     CHAR(64)     NOT NULL COMMENT 'sha256(session_id)',
    ip_address   VARCHAR(45)  NULL,
    user_agent   VARCHAR(255) NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_sessions_sid (sid_hash),
    KEY idx_user_sessions_user (user_id),
    CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Очередь авто-публикаций в соцсети (этап 13, обрабатывается CLI-воркером)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS social_posts (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id     INT UNSIGNED NOT NULL,
    network     ENUM('telegram','facebook','linkedin','instagram') NOT NULL,
    status      ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    scheduled_at DATETIME NULL COMMENT 'не отправлять раньше этого времени; NULL — при ближайшем запуске',
    attempts    INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    remote_id   VARCHAR(190) NULL COMMENT 'id опубликованного поста в сети',
    last_error  VARCHAR(500) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at     DATETIME NULL,
    UNIQUE KEY uq_social_posts_news_network (news_id, network),
    KEY idx_social_posts_status (status, created_at),
    KEY idx_social_posts_scheduled (status, scheduled_at),
    CONSTRAINT fk_social_posts_news FOREIGN KEY (news_id) REFERENCES news (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Исходящие вебхуки (этап 16.2)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS webhooks (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type  VARCHAR(60)  NOT NULL,
    url         VARCHAR(500) NOT NULL,
    secret      TEXT         NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_webhooks_event (event_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    webhook_id    BIGINT UNSIGNED NOT NULL,
    event_type    VARCHAR(60)  NOT NULL,
    payload_json  LONGTEXT     NOT NULL,
    status        ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts      INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until  DATETIME     NULL,
    response_code INT          NULL,
    last_error    VARCHAR(500) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at       DATETIME     NULL,
    KEY idx_webhook_deliveries_status (status, created_at),
    KEY idx_webhook_deliveries_hook (webhook_id, created_at),
    CONSTRAINT fk_webhook_deliveries_hook FOREIGN KEY (webhook_id) REFERENCES webhooks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Библиотека шаблонов блоков (сниппеты, этап 16.1)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS block_snippets (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(190) NOT NULL,
    blocks_json LONGTEXT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Журнал действий администраторов (аудит): кто, что (метод + путь), когда,
-- с какого IP. Пишется центрально для всех изменяющих запросов /admin.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NULL,
    username   VARCHAR(100) NOT NULL DEFAULT '',
    method     VARCHAR(8) NOT NULL DEFAULT 'POST',
    path       VARCHAR(255) NOT NULL,
    ip         VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_user (user_id, created_at),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Журнал ошибок сайта (хранение 7 дней либо ручная очистка из панели)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS error_log (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level      VARCHAR(10) NOT NULL DEFAULT 'ERROR',
    human      VARCHAR(500) NOT NULL DEFAULT '',
    message    TEXT NOT NULL,
    file       VARCHAR(500) NOT NULL DEFAULT '',
    line       INT UNSIGNED NOT NULL DEFAULT 0,
    url        VARCHAR(500) NOT NULL DEFAULT '',
    ip         VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_error_created (created_at),
    KEY idx_error_level (level, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Менеджер 301/302-редиректов: переезд со старого сайта без потери ссылок
-- ---------------------------------------------------------------------------
-- История проверок индексации: без неё нельзя ответить на вопрос «стало хуже
-- или так было всегда», а именно он возникает первым.
CREATE TABLE IF NOT EXISTS seo_audits (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    errors      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    warnings    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    findings    LONGTEXT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_seo_audits_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS redirects (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_path   VARCHAR(255) NOT NULL,
    to_url      VARCHAR(500) NOT NULL,
    code        SMALLINT UNSIGNED NOT NULL DEFAULT 301,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    hits        INT UNSIGNED NOT NULL DEFAULT 0,
    last_hit_at DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_redirects_from (from_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Фотоальбомы: галереи изображений с обложкой (/albums)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS photo_albums (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    slug         VARCHAR(255) NOT NULL,
    description  TEXT NULL,
    cover_url    VARCHAR(500) NOT NULL DEFAULT '',
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    is_featured  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'показывать на главной (блок Медиа)',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_albums_slug (slug),
    KEY idx_albums_listing (is_published, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS photo_album_images (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    album_id   INT UNSIGNED NOT NULL,
    image_url  VARCHAR(500) NOT NULL,
    caption    VARCHAR(255) NOT NULL DEFAULT '',
    credit     VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'автор или источник (Фото: …)',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_album_images (album_id, sort_order, id),
    CONSTRAINT fk_album_images FOREIGN KEY (album_id) REFERENCES photo_albums (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Переводы фотоальбомов (заголовок и описание на неосновных языках)
CREATE TABLE IF NOT EXISTS photo_album_translations (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    album_id     INT UNSIGNED NOT NULL,
    lang         VARCHAR(8) NOT NULL,
    title        VARCHAR(255) NULL,
    description  TEXT NULL,
    UNIQUE KEY uq_photo_album_translations (album_id, lang),
    CONSTRAINT fk_photo_album_translations_album FOREIGN KEY (album_id) REFERENCES photo_albums(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Видеозаписи: обложка + ссылка на видео (YouTube/внешнее). Блок «Медиа» на
-- главной собирает отмеченные (is_featured) автоматически.
CREATE TABLE IF NOT EXISTS videos (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    slug         VARCHAR(255) NOT NULL,
    description  TEXT NULL,
    cover_url    VARCHAR(500) NOT NULL DEFAULT '',
    video_url    VARCHAR(500) NOT NULL DEFAULT '',
    youtube_id   VARCHAR(32) NOT NULL DEFAULT '' COMMENT 'id ролика YouTube — ключ повторного импорта',
    source       VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'происхождение: пусто — вручную, youtube — импорт',
    published_at DATETIME NULL COMMENT 'дата публикации ролика на канале',
    duration     VARCHAR(20) NOT NULL DEFAULT '',
    is_published TINYINT(1) NOT NULL DEFAULT 1,
    is_featured  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'показывать на главной (блок Медиа)',
    sort_order   INT NOT NULL DEFAULT 0,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_videos_slug (slug),
    KEY idx_videos_listing (is_published, sort_order, created_at),
    KEY idx_videos_youtube (youtube_id),
    KEY idx_videos_dates (is_published, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Переводы видео (заголовок и описание на неосновных языках)
CREATE TABLE IF NOT EXISTS video_translations (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    video_id     INT UNSIGNED NOT NULL,
    lang         VARCHAR(8) NOT NULL,
    title        VARCHAR(255) NULL,
    description  TEXT NULL,
    UNIQUE KEY uq_video_translations (video_id, lang),
    CONSTRAINT fk_video_translations_video FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 404-трекер: кандидаты в 301-редиректы (страница «Редиректы»)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS not_found_log (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    path         VARCHAR(255) NOT NULL,
    hits         INT UNSIGNED NOT NULL DEFAULT 1,
    last_referer VARCHAR(500) NULL,
    first_hit_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_hit_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_not_found_path (path),
    KEY idx_not_found_hits (hits)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Конструктор произвольных типов контента (этап 16.4)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS content_types (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug             VARCHAR(60)  NOT NULL,
    name             VARCHAR(190) NOT NULL,
    description      VARCHAR(255) NOT NULL DEFAULT '',
    icon             VARCHAR(60)  NOT NULL DEFAULT '' COMMENT 'имя иконки Tabler для пункта меню',
    has_translations TINYINT(1)   NOT NULL DEFAULT 0,
    is_public        TINYINT(1)   NOT NULL DEFAULT 1,
    root_url         TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'адрес без префикса /catalog',
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_content_types_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_type_fields (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type_id     INT UNSIGNED NOT NULL,
    name        VARCHAR(60)  NOT NULL,
    label       VARCHAR(190) NOT NULL,
    field_type  ENUM('text','textarea','number','date','image','file','relation') NOT NULL DEFAULT 'text',
    required    TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order  INT          NOT NULL DEFAULT 0,
    options     LONGTEXT     NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_content_type_fields_type (type_id, sort_order),
    CONSTRAINT fk_content_type_fields_type FOREIGN KEY (type_id) REFERENCES content_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_entries (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type_id     INT UNSIGNED NOT NULL,
    title       VARCHAR(255) NOT NULL,
    slug        VARCHAR(255) NOT NULL,
    status      ENUM('draft','published') NOT NULL DEFAULT 'draft',
    data        LONGTEXT     NOT NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME     NULL,
    UNIQUE KEY uq_content_entries_slug (type_id, slug),
    KEY idx_content_entries_type (type_id, status),
    CONSTRAINT fk_content_entries_type FOREIGN KEY (type_id) REFERENCES content_types (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_entry_translations (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_id    BIGINT UNSIGNED NOT NULL,
    lang        VARCHAR(8)   NOT NULL,
    title       VARCHAR(255) NULL,
    data        LONGTEXT     NULL,
    UNIQUE KEY uq_content_entry_translations (entry_id, lang),
    CONSTRAINT fk_content_entry_translations_entry FOREIGN KEY (entry_id) REFERENCES content_entries (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Стартовые публичные типы контента государственного сайта (редактируемы)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO content_types (slug, name, description, icon, has_translations, is_public, created_at) VALUES
    ('documenty', 'Документы', 'Официальные документы, приказы и постановления', 'file-text', 1, 1, NOW()),
    ('vakansii',  'Вакансии',  'Открытые вакансии организации', 'briefcase', 1, 1, NOW()),
    ('tendery',   'Тендеры',   'Актуальные тендеры и закупки', 'gavel', 1, 1, NOW());

INSERT INTO content_type_fields (type_id, name, label, field_type, required, sort_order, created_at)
SELECT t.id, f.name, f.label, f.field_type, f.required, f.sort_order, NOW()
FROM content_types t
JOIN (
    SELECT 'documenty' AS slug, 'doc_number' AS name, 'Номер документа' AS label, 'text' AS field_type, 0 AS required, 0 AS sort_order
    UNION ALL SELECT 'documenty', 'doc_date',  'Дата',              'date',     0, 1
    UNION ALL SELECT 'documenty', 'category',  'Категория',         'text',     0, 2
    UNION ALL SELECT 'documenty', 'summary',   'Краткое описание',  'textarea', 0, 3
    UNION ALL SELECT 'documenty', 'file',      'Файл документа',    'file',     1, 4
    UNION ALL SELECT 'vakansii',  'department','Отдел',             'text',     0, 0
    UNION ALL SELECT 'vakansii',  'salary',    'Зарплата',          'text',     0, 1
    UNION ALL SELECT 'vakansii',  'deadline',  'Приём заявок до',   'date',     0, 2
    UNION ALL SELECT 'vakansii',  'requirements','Требования',      'textarea', 0, 3
    UNION ALL SELECT 'vakansii',  'duties',    'Обязанности',       'textarea', 0, 4
    UNION ALL SELECT 'tendery',   'tender_number','Номер тендера',  'text',     0, 0
    UNION ALL SELECT 'tendery',   'budget',    'Бюджет',            'text',     0, 1
    UNION ALL SELECT 'tendery',   'start_date','Дата публикации',   'date',     0, 2
    UNION ALL SELECT 'tendery',   'deadline',  'Приём заявок до',   'date',     0, 3
    UNION ALL SELECT 'tendery',   'summary',   'Описание',          'textarea', 0, 4
    UNION ALL SELECT 'tendery',   'file',      'Тендерная документация', 'file', 0, 5
) f ON f.slug = t.slug
WHERE NOT EXISTS (SELECT 1 FROM content_type_fields x WHERE x.type_id = t.id);

-- Календарь мероприятий: тип «Мероприятия» (страница /calendar)
INSERT IGNORE INTO content_types (slug, name, description, has_translations, is_public, created_at) VALUES
    ('meropriyatiya', 'Мероприятия', 'Календарь событий и мероприятий организации', 1, 1, NOW());

INSERT INTO content_type_fields (type_id, name, label, field_type, required, sort_order, created_at)
SELECT t.id, f.name, f.label, f.field_type, f.required, f.sort_order, NOW()
FROM content_types t
JOIN (
    SELECT 'meropriyatiya' AS slug, 'event_date' AS name, 'Дата проведения' AS label, 'date' AS field_type, 1 AS required, 0 AS sort_order
    UNION ALL SELECT 'meropriyatiya', 'event_time', 'Время',            'text',     0, 1
    UNION ALL SELECT 'meropriyatiya', 'location',   'Место проведения', 'text',     0, 2
    UNION ALL SELECT 'meropriyatiya', 'banner_image','Баннер мероприятия','image',   0, 3
    UNION ALL SELECT 'meropriyatiya', 'summary',    'Описание',         'textarea', 0, 4
) f ON f.slug = t.slug
WHERE NOT EXISTS (SELECT 1 FROM content_type_fields x WHERE x.type_id = t.id);

-- ---------------------------------------------------------------------------
-- Защищённое файловое хранилище (репозиторий) с собственной авторизацией
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS repo_users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(60)  NOT NULL,
    full_name       VARCHAR(190) NOT NULL DEFAULT '',
    organization    VARCHAR(190) NOT NULL DEFAULT '',
    email           VARCHAR(190) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    totp_secret     TEXT         NULL,
    totp_enabled    TINYINT(1)   NOT NULL DEFAULT 0,
    totp_last_step  BIGINT       NULL DEFAULT NULL COMMENT 'последний принятый шаг TOTP (защита от повтора кода)',
    telegram_chat_id BIGINT      NULL COMMENT '2FA через Telegram-бота (NULL — не привязан)',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_repo_users_username (username),
    UNIQUE KEY uq_repo_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS repo_categories (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id  BIGINT UNSIGNED NULL,
    name       VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_repo_categories_parent (parent_id),
    CONSTRAINT fk_repo_categories_parent FOREIGN KEY (parent_id) REFERENCES repo_categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS repo_files (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(255) NOT NULL,
    description     TEXT NULL,
    category_id     BIGINT UNSIGNED NULL,
    stored_name     VARCHAR(255) NOT NULL COMMENT 'случайное имя на диске (без user input)',
    original_name   VARCHAR(255) NOT NULL,
    mime_type       VARCHAR(120) NOT NULL,
    size            BIGINT UNSIGNED NOT NULL,
    download_count  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status          VARCHAR(16) NOT NULL DEFAULT 'approved' COMMENT 'pending — ждёт одобрения админа',
    uploaded_by     INT UNSIGNED NULL COMMENT 'id администратора-загрузчика (users.id)',
    uploaded_by_repo_user INT UNSIGNED NULL COMMENT 'id пользователя портала (repo_users.id)',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_repo_files_category_id (category_id),
    KEY idx_repo_files_created (created_at),
    KEY idx_repo_files_status (status),
    CONSTRAINT fk_repo_files_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_repo_files_category FOREIGN KEY (category_id) REFERENCES repo_categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_repo_files_repo_user FOREIGN KEY (uploaded_by_repo_user) REFERENCES repo_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Стартовая главная страница (hero + быстрые ссылки + последние новости)
-- ---------------------------------------------------------------------------
INSERT INTO pages (title, slug, status, is_home, layout_type, created_at)
SELECT 'Главная', 'home', 'published', 1, 'no_sidebar', NOW()
WHERE NOT EXISTS (SELECT 1 FROM pages WHERE is_home = 1);

SET @home := (SELECT id FROM pages WHERE slug = 'home' AND is_home = 1 ORDER BY id ASC LIMIT 1);
SET @seed := IF(@home IS NOT NULL AND (SELECT COUNT(*) FROM blocks WHERE page_id = @home) = 0, 1, 0);

INSERT INTO blocks (page_id, lang, type, title, data, sort_order, is_active, created_at)
SELECT @home, 'ru', 'cta', 'Hero',
    '{"title":"Официальный сайт организации","text":"Актуальная информация, документы, новости и услуги в одном месте.","button_text":"Последние новости","button_url":"/news","_spacing":"max"}',
    1, 1, NOW()
FROM DUAL WHERE @seed = 1;

INSERT INTO blocks (page_id, lang, type, title, data, sort_order, is_active, created_at)
SELECT @home, 'ru', 'columns', 'Быстрые ссылки', '{"columns":3,"gap":"medium","_spacing":"premium"}', 2, 1, NOW()
FROM DUAL WHERE @seed = 1;
SET @cols := IF(@seed = 1, LAST_INSERT_ID(), NULL);

INSERT INTO blocks (page_id, parent_block_id, column_index, lang, type, title, data, sort_order, is_active, created_at)
SELECT @home, @cols, 0, 'ru', 'cta', NULL, '{"title":"Документы","text":"Приказы, постановления и официальные документы.","button_text":"Открыть раздел","button_url":"/catalog/documenty"}', 1, 1, NOW()
FROM DUAL WHERE @seed = 1 AND @cols IS NOT NULL;
INSERT INTO blocks (page_id, parent_block_id, column_index, lang, type, title, data, sort_order, is_active, created_at)
SELECT @home, @cols, 1, 'ru', 'cta', NULL, '{"title":"Вакансии","text":"Открытые вакансии и условия работы.","button_text":"Открыть раздел","button_url":"/catalog/vakansii"}', 1, 1, NOW()
FROM DUAL WHERE @seed = 1 AND @cols IS NOT NULL;
INSERT INTO blocks (page_id, parent_block_id, column_index, lang, type, title, data, sort_order, is_active, created_at)
SELECT @home, @cols, 2, 'ru', 'cta', NULL, '{"title":"Тендеры","text":"Актуальные тендеры и закупки.","button_text":"Открыть раздел","button_url":"/catalog/tendery"}', 1, 1, NOW()
FROM DUAL WHERE @seed = 1 AND @cols IS NOT NULL;

INSERT INTO blocks (page_id, lang, type, title, data, sort_order, is_active, created_at)
SELECT @home, 'ru', 'news_latest', 'Последние новости', '{"title":"Последние новости","limit":3,"_spacing":"premium"}', 3, 1, NOW()
FROM DUAL WHERE @seed = 1;

-- Корень каждой независимой языковой группы должен быть ненулевым уже после
-- чистой установки, до первого открытия редактора.
UPDATE news SET translation_group_id = id
WHERE translation_group_id IS NULL OR translation_group_id = 0;
UPDATE pages SET translation_group_id = id
WHERE translation_group_id IS NULL OR translation_group_id = 0;



-- ---------------------------------------------------------------------------
-- Персональный Центр уведомлений администраторов и редакторов
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(40) NOT NULL DEFAULT 'system',
    severity VARCHAR(16) NOT NULL DEFAULT 'info',
    title VARCHAR(190) NOT NULL,
    message TEXT NOT NULL,
    action_url VARCHAR(500) NULL,
    dedupe_key VARCHAR(190) NULL,
    requires_ack TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notifications_dedupe (dedupe_key),
    KEY idx_notifications_created (created_at),
    KEY idx_notifications_category_severity (category, severity),
    CONSTRAINT fk_notifications_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_recipients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    notification_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    read_at DATETIME NULL,
    acknowledged_at DATETIME NULL,
    dismissed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notification_recipient (notification_id, user_id),
    KEY idx_notification_recipient_unread (user_id, read_at, dismissed_at),
    KEY idx_notification_recipient_ack (user_id, acknowledged_at),
    CONSTRAINT fk_notification_recipients_notification
        FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_recipients_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_preferences (
    user_id INT UNSIGNED PRIMARY KEY,
    email_enabled TINYINT(1) NOT NULL DEFAULT 0,
    telegram_enabled TINYINT(1) NOT NULL DEFAULT 0,
    minimum_severity VARCHAR(16) NOT NULL DEFAULT 'warning',
    quiet_start TIME NULL,
    quiet_end TIME NULL,
    digest_mode VARCHAR(16) NOT NULL DEFAULT 'none',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_preferences_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_id BIGINT UNSIGNED NOT NULL,
    channel VARCHAR(16) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    locked_until DATETIME NULL,
    last_error TEXT NULL,
    sent_at DATETIME NULL,
    idempotency_key VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notification_delivery_idempotency (idempotency_key),
    KEY idx_notification_delivery_queue (status, next_attempt_at, locked_until, created_at),
    CONSTRAINT fk_notification_deliveries_recipient
        FOREIGN KEY (recipient_id) REFERENCES notification_recipients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Применённые миграции (для CLI database/migrate.php)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS migrations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename        VARCHAR(255) NOT NULL,
    applied_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_migrations_filename (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webpush: подписки браузеров и очередь уведомлений о новостях.
CREATE TABLE IF NOT EXISTS webpush_subscriptions (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    endpoint      VARCHAR(1000) NOT NULL,
    endpoint_hash CHAR(40) NOT NULL COMMENT 'sha1(endpoint) для уникального индекса',
    p256dh        VARCHAR(255) NOT NULL,
    auth          VARCHAR(64) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_webpush_endpoint (endpoint_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webpush_queue (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_id    INT UNSIGNED NOT NULL,
    status     ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts   INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at    DATETIME NULL,
    UNIQUE KEY uniq_webpush_queue_news (news_id),
    CONSTRAINT fk_webpush_queue_news FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Этот schema.sql уже содержит структуру всех существующих миграций, поэтому
-- для свежей установки помечаем их как применённые — database/migrate.php не
-- будет пытаться накатить их повторно. (Старые установки, созданные на схеме
-- этапов 1–2, накатят их через migrate.php.)

-- «Цели» (Maqsadlar) — тип контента, у которого нет текста наружу: цель это
-- набор снимков, и на сайте она показывается только каруселью в виджете.
--
-- Название и описание видны на сайте и переводятся (механизм А, таблица
-- goal_translations): набор снимков без единого слова не сообщает посетителю
-- ни что за объект, ни зачем он. Отдельной страницы и slug'а у цели нет —
-- публичного адреса, который надо было бы держать стабильным, не существует,
-- поэтому языковая версия остаётся переводом строки, а не отдельной записью.
CREATE TABLE IF NOT EXISTS goals (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL COMMENT 'название цели: видно на сайте и переводится',
    description TEXT NULL COMMENT 'описание под названием; необязательно',
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_goals_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Снимки цели. Порядок задаётся редактором; случайным бывает выбор самой
-- цели, а не кадров внутри неё — иначе история, рассказанная слайдами, каждый
-- раз рассыпалась бы.
CREATE TABLE IF NOT EXISTS goal_images (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    goal_id    INT NOT NULL,
    image      VARCHAR(500) NOT NULL,
    alt        VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'описание для диктора; на экране не видно',
    sort_order INT NOT NULL DEFAULT 0,
    INDEX idx_goal_images_goal (goal_id, sort_order),
    CONSTRAINT fk_goal_images_goal FOREIGN KEY (goal_id) REFERENCES goals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Перевод названия и описания цели. Пустое поле откатывается к основному
-- языку — как у альбомов и видео.
CREATE TABLE IF NOT EXISTS goal_translations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    goal_id     INT NOT NULL,
    lang        VARCHAR(5) NOT NULL,
    name        VARCHAR(255) NULL,
    description TEXT NULL,
    UNIQUE KEY uniq_goal_translation (goal_id, lang),
    CONSTRAINT fk_goal_translations_goal FOREIGN KEY (goal_id) REFERENCES goals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO migrations (filename) VALUES
    ('2026_07_05_block5_multilang_header_widgets.sql'),
    ('2026_07_05_soft_deletes.sql'),
    ('2026_07_05_mail_queue.sql'),
    ('2026_07_05_security_block11.sql'),
    ('2026_07_05_news_media.sql'),
    ('2026_07_05_social_posts.sql'),
    ('2026_07_06_block_snippets.sql'),
    ('2026_07_06_webhooks.sql'),
    ('2026_07_06_content_types.sql'),
    ('2026_07_06_block_revisions.sql'),
    ('2026_07_06_block_columns.sql'),
    ('2026_07_06_page_landing.sql'),
    ('2026_07_06_file_repository.sql'),
    ('2026_07_06_content_frontend.sql'),
    ('2026_07_07_block_active.sql'),
    ('2026_07_07_home_page.sql'),
    ('2026_07_08_telegram_gateway_2fa.sql'),
    ('2026_07_08_telegram_bot_login.sql'),
    ('2026_07_08_audit_log.sql'),
    ('2026_07_08_redirects.sql'),
    ('2026_07_08_events_calendar.sql'),
    ('2026_07_08_photo_albums.sql'),
    ('2026_07_08_subscribers.sql'),
    ('2026_07_08_queue_locks.sql'),
    ('2026_07_08_not_found_log.sql'),
    ('2026_07_09_menu_icons_dividers.sql'),
    ('2026_07_09_news_detail_extras.sql'),
    ('2026_07_09_news_premium_layout.sql'),
    ('2026_07_10_pages_transparent_header.sql'),
    ('2026_07_10_social_telegram.sql'),
    ('2026_07_10_webpush.sql'),
    ('2026_07_11_pages_lead.sql'),
    ('2026_07_11_featured_home.sql'),
    ('2026_07_12_videos.sql'),
    ('2026_07_13_content_revisions.sql'),
    ('2026_07_13_content_locking.sql'),
    ('2026_07_13_encrypt_secrets.sql'),
    ('2026_07_15_news_sidebar_layout.sql'),
    ('2026_07_16_error_log.sql'),
    ('2026_07_16_repo_categories.sql'),
    ('2026_07_16_repo_user_uploads.sql'),
    ('2026_07_16_repo_telegram_2fa.sql'),
    ('2026_07_17_project_translations.sql'),
    ('2026_07_17_team_translations.sql'),
    ('2026_07_17_album_translations.sql'),
    ('2026_07_17_video_translations.sql'),
    ('2026_07_18_menu_per_language.sql'),
    ('2026_07_18_menu_mega.sql'),
    ('2026_07_19_news_translation_badge.sql'),
    ('2026_07_23_block_locking.sql'),
    ('2026_07_25_menu_badge.sql'),
    ('2026_07_25_news_audio.sql'),
    ('2026_07_25_news_hashtags.sql'),
    ('2026_07_25_news_polls_and_timeline.sql'),
    ('2026_07_25_translation_group.sql'),
    ('2026_07_25_user_admin_lang.sql'),
    ('2026_07_25_search_log.sql'),
    ('2026_07_25_news_views.sql'),
    ('2026_07_25_menu_badge_pos.sql'),
    ('2026_07_26_menu_language_isolation.sql'),
    ('2026_07_28_menu_hide_title.sql'),
    ('2026_07_29_page_hierarchy.sql'),
    ('2026_07_29_tabler_icon_keys.sql'),
    ('2026_07_29_translation_group_integrity.sql'),
    ('2026_07_30_database_integrity.sql'),
    ('2026_07_30_digest_subscriber_lifecycle.sql'),
    ('2026_07_30_event_banner.sql'),
    ('2026_07_30_news_translation_details.sql'),
    ('2026_07_30_remove_header_layout.sql'),
    ('2026_07_30_translation_group_indexes.sql'),
    ('2026_07_31_team_departments.sql'),
    ('2026_08_01_media_captions.sql'),
    ('2026_08_02_news_rich_lead.sql'),
    ('2026_08_02_social_scheduled_at.sql'),
    ('2026_08_03_notification_center.sql'),
    ('2026_08_05_page_custom_assets.sql'),
    ('2026_08_11_web_vitals.sql'),
    ('2026_08_11_public_listing_indexes.sql'),
    ('2026_08_11_news_categories.sql'),
    ('2026_08_11_news_badge_color.sql'),
    ('2026_08_13_projects_into_pages.sql'),
    ('2026_08_13_news_category_icon.sql'),
    ('2026_08_13_page_sections.sql'),
    ('2026_08_13_language_short_name.sql'),
    ('2026_08_14_heroes.sql'),
    ('2026_08_14_hero_watermark.sql'),
    ('2026_08_15_news_layout_card.sql'),
    ('2026_08_15_news_card_fields.sql'),
    ('2026_08_21_videos_youtube_import.sql'),
    ('2026_08_23_totp_replay_guard.sql'),
    ('2026_08_26_goals.sql'),
    ('2026_08_27_goal_texts.sql'),
    ('2026_08_29_content_type_icon.sql'),
    ('2026_09_01_seo_audits.sql'),
    ('2026_09_08_content_type_root_url.sql')
ON DUPLICATE KEY UPDATE filename = filename;

CREATE TABLE IF NOT EXISTS search_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    query VARCHAR(190) NOT NULL,
    results_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_search_log_created (created_at),
    KEY idx_search_log_query (query)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица отслеживания просмотров новостей по дням
CREATE TABLE IF NOT EXISTS news_views (
    news_id INT UNSIGNED NOT NULL,
    view_date DATE NOT NULL,
    views_count INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (news_id, view_date),
    KEY idx_news_views_date (view_date, views_count),
    CONSTRAINT fk_news_views_news FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ломало бы общий кеш (см. историю с Vary: Cookie).
CREATE TABLE IF NOT EXISTS web_vitals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    metric VARCHAR(8) NOT NULL,
    value DOUBLE NOT NULL,
    rating VARCHAR(16) NOT NULL DEFAULT '',
    page_kind VARCHAR(24) NOT NULL DEFAULT 'other',
    device VARCHAR(8) NOT NULL DEFAULT 'desktop',
    lang VARCHAR(8) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_web_vitals_metric (metric, created_at),
    KEY idx_web_vitals_slice (metric, page_kind, device, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
