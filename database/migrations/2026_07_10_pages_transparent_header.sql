-- Прозрачная шапка включается точечно на страницах (флаг страницы);
-- глобальный переключатель в конструкторе задаёт доступность режима.
ALTER TABLE pages ADD COLUMN transparent_header TINYINT(1) NOT NULL DEFAULT 0 AFTER hide_chrome;
