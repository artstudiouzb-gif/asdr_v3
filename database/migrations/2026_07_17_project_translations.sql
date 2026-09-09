-- Переводы проектов: заголовок и описание на неосновных языках.
-- База (projects) хранит основной язык; переводы — здесь, накладываются
-- на публичке с graceful-fallback к основному языку при пустом поле.
CREATE TABLE IF NOT EXISTS project_translations (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id   INT UNSIGNED NOT NULL,
    lang         VARCHAR(8) NOT NULL,
    title        VARCHAR(255) NULL,
    description  LONGTEXT NULL,
    UNIQUE KEY uq_project_translations (project_id, lang),
    CONSTRAINT fk_project_translations_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
