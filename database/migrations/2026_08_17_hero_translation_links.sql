-- @post-schema
-- Языковые ссылки Hero-слайда: текст и целевой URL могут отличаться по языкам.
ALTER TABLE hero_slide_translations
    ADD COLUMN cta_url VARCHAR(2048) NULL AFTER cta_text,
    ADD COLUMN cta2_url VARCHAR(2048) NULL AFTER cta2_text,
    ADD COLUMN link_url VARCHAR(2048) NULL AFTER cta2_url;
