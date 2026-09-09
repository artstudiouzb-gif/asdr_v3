-- Общие пункты старых установок больше не показываются во всех локалях.
-- Они остаются в меню основного языка; остальные языки наполняются через
-- явную синхронизацию в конструкторе меню.
UPDATE menu_items
SET lang = COALESCE(
    (SELECT code FROM languages WHERE is_default = 1 ORDER BY id ASC LIMIT 1),
    'ru'
)
WHERE lang = '';
