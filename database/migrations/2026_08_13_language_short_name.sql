-- Короткое название языка для компактного переключателя. До сих пор там стоял
-- код страницы (RU, UZ, EN) — это идентификатор, а не название языка.
ALTER TABLE languages
    ADD COLUMN short_name VARCHAR(16) NOT NULL DEFAULT '' COMMENT 'короткое название для переключателя: Рус, Oʻzb' AFTER name;

UPDATE languages SET short_name = 'Рус' WHERE code = 'ru' AND short_name = '';
UPDATE languages SET short_name = 'Oʻzb' WHERE code = 'uz' AND short_name = '';
UPDATE languages SET short_name = 'Eng' WHERE code = 'en' AND short_name = '';
