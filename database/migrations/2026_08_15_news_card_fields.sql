-- Поля обложки-карточки новости (макет layout_type = 'card').
--
-- Это оформление обложки, а не содержание новости: своя короткая строка
-- заголовка, вторая плашка, показатели, подпись и сноска. Всё переводится —
-- иначе на узбекской версии карточка осталась бы с русскими словами.
ALTER TABLE news
    ADD COLUMN card_title VARCHAR(200) NULL COMMENT 'заголовок на обложке; пусто — обычный заголовок' AFTER badge_color,
    ADD COLUMN card_badge VARCHAR(100) NULL COMMENT 'вторая плашка обложки (контурная)' AFTER card_title,
    ADD COLUMN card_stats TEXT NULL COMMENT 'показатели обложки: JSON [{value,label}], до трёх' AFTER card_badge,
    ADD COLUMN card_signature VARCHAR(80) NULL COMMENT 'подпись рукописным шрифтом' AFTER card_stats,
    ADD COLUMN card_note VARCHAR(120) NULL COMMENT 'сноска внизу обложки' AFTER card_signature;

ALTER TABLE news_translations
    ADD COLUMN card_title VARCHAR(200) NULL AFTER badge,
    ADD COLUMN card_badge VARCHAR(100) NULL AFTER card_title,
    ADD COLUMN card_stats TEXT NULL AFTER card_badge,
    ADD COLUMN card_signature VARCHAR(80) NULL AFTER card_stats,
    ADD COLUMN card_note VARCHAR(120) NULL AFTER card_signature;
