-- Перевод формы обратной связи (механизм А: перевод накладывается на базовую
-- строку). Прежде у формы был один набор подписей, и для каждого языка
-- заводили отдельную форму: заявки расходились по разным журналам, а правка
-- полей в одной копии не доходила до остальных. Переводится то, что видит
-- посетитель, — название, сообщение после отправки, подписи полей и варианты
-- выбора; имена полей, типы и условия показа общие для всех языков.
CREATE TABLE IF NOT EXISTS form_translations (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id         INT UNSIGNED NOT NULL,
    lang            VARCHAR(5) NOT NULL,
    name            VARCHAR(190) NULL,
    success_message VARCHAR(500) NULL,
    fields_json     JSON NULL COMMENT '{"phone":{"label":"Telefon","options":"…"}, ...} — по имени поля',
    UNIQUE KEY uq_form_translations (form_id, lang),
    CONSTRAINT fk_form_translations_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
