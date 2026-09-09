-- Защита блоков конструктора от незаметной перезаписи параллельных правок.
ALTER TABLE blocks ADD COLUMN lock_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER updated_at;
