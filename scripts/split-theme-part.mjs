/**
 * Выносит семейство правил из общей темы в отдельный файл части темы
 * (public/assets/css/blocks/*.css, подключается через
 * AssetCollector::THEME_PART_MAP).
 *
 *   node scripts/split-theme-part.mjs <имя-файла> <regexp> [regexp-исключение]
 *
 * Пример:
 *   node scripts/split-theme-part.mjs org-structure 'orgstruct'
 *
 * Правила разбора, за которые пришлось заплатить отладкой:
 *  - «Селектор» — это текст от конца прошлого правила до «{», то есть вместе
 *    с комментариями. Проверять на @media/@supports и на совпадение нужно по
 *    тексту БЕЗ комментариев, иначе закомментированный блок не распознаётся
 *    как at-rule и уезжает целиком — вместе с чужими правилами внутри.
 *  - Исключение выигрывает: в групповом селекторе достаточно одного чужого
 *    класса, чтобы правило осталось в теме. Оставить лишнее безопасно,
 *    унести чужое — нет.
 */
import { readFile, writeFile } from 'node:fs/promises';
import process from 'node:process';

const [name, pattern, exclude] = process.argv.slice(2);
if (!name || !pattern) {
    console.error('usage: node scripts/split-theme-part.mjs <name> <regexp> [exclude-regexp]');
    process.exit(1);
}
const MATCH = new RegExp(pattern);
const KEEP = exclude ? new RegExp(exclude) : null;
const SOURCES = ['public/assets/css/frontend.css', 'public/assets/css/gov-theme.css'];
const OUT = `public/assets/css/blocks/${name}.css`;

const bare = (sel) => sel.replace(/\/\*[\s\S]*?\*\//g, '').trim();

/**
 * Правило уезжает, только если ВСЕ части группового селектора принадлежат
 * выносимому семейству. Проверять группу целиком нельзя: строка вида
 * «.newsdetail-photos__item:hover, .album-card:hover, .btn:hover» содержит
 * искомое и уехала бы вместе с чужими карточками и кнопками — на остальных
 * страницах они молча теряют своё правило.
 */
const movable = (sel) => {
    const s = bare(sel);
    if (s === '') return false;
    const parts = s.split(',').map((p) => p.trim()).filter(Boolean);
    if (parts.length === 0) return false;

    return parts.every((p) => MATCH.test(p) && !(KEEP && KEEP.test(p)));
};

function splitRules(text) {
    const out = [];
    let i = 0;
    while (i < text.length) {
        const b = text.indexOf('{', i);
        if (b < 0) {
            if (text.slice(i).trim()) out.push([null, text.slice(i)]);
            break;
        }
        let depth = 1;
        let j = b + 1;
        while (j < text.length && depth > 0) {
            if (text[j] === '{') depth++;
            else if (text[j] === '}') depth--;
            j++;
        }
        out.push([text.slice(i, b), text.slice(i, j)]);
        i = j;
    }
    return out;
}

const moved = [];
for (const src of SOURCES) {
    const text = await readFile(src, 'utf8');
    const kept = [];
    const mine = [];
    for (const [sel, body] of splitRules(text)) {
        if (sel === null) { kept.push(body); continue; }
        const s = bare(sel);
        if (s.startsWith('@media') || s.startsWith('@supports')) {
            const head = body.slice(0, body.indexOf('{') + 1);
            const inner = body.slice(body.indexOf('{') + 1, body.lastIndexOf('}'));
            const parts = splitRules(inner);
            const hit = parts.filter(([s2]) => s2 !== null && movable(s2)).map(([, b2]) => b2);
            const rest = parts.filter(([s2]) => s2 === null || !movable(s2)).map(([, b2]) => b2);
            if (hit.length) mine.push(`${head}${hit.join('')}\n}\n`);
            if (hit.length && rest.length) kept.push(`${head}${rest.join('')}\n}\n`);
            else if (!hit.length) kept.push(body);
            continue;
        }
        (movable(sel) ? mine : kept).push(body);
    }
    await writeFile(src, kept.join(''), 'utf8');
    if (mine.length) moved.push(`/* из ${src} */\n${mine.join('')}`);
    console.log(`${src}: вынесено ${mine.length}`);
}

await writeFile(OUT, moved.join('\n'), 'utf8');
console.log(`-> ${OUT}`);
