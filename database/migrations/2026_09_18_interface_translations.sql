-- Редактируемые переводы системных строк t().
-- Файлы app/Core/lang/*.php остаются fallback и частью исходного кода.
CREATE TABLE IF NOT EXISTS interface_translations (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lang              VARCHAR(8) NOT NULL,
    translation_key   VARCHAR(500) NOT NULL,
    translation_value TEXT NOT NULL,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_interface_translation (lang, translation_key),
    KEY idx_interface_translation_lang (lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
