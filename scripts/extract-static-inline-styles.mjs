import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const checkOnly = process.argv.includes('--check');
let violationCount = 0;
const groups = [
  {
    roots: ['app/Views/site', 'templates'],
    files: ['app/Core/AppToolbar.php', 'app/Core/DemoSeeder.php'],
    css: 'public/assets/css/frontend.css',
    extractStyleBlocks: true,
  },
  {
    roots: ['app/Views/admin', 'app/Views/install'],
    files: [
      'app/Core/AdminUi.php',
      'app/Core/TranslationGroupHelper.php',
      'public/assets/js/admin.js',
    ],
    css: 'public/assets/css/admin.css',
    extractStyleBlocks: true,
  },
  {
    roots: ['app/Views/repo'],
    files: [],
    css: 'public/assets/css/repo.css',
  },
  {
    roots: ['app/Views/errors'],
    files: [],
    css: 'public/assets/css/system.css',
    extractStyleBlocks: true,
  },
];

const extensions = new Set(['.php', '.html', '.js']);
const unsafeDynamic = /<\?(?:=|php)?|\$\w|['"]\s*\.\s*|`|\$\{/;

function walk(relativePath) {
  const absolutePath = path.join(root, relativePath);
  if (!fs.existsSync(absolutePath)) return [];
  const stat = fs.statSync(absolutePath);
  if (stat.isFile()) return extensions.has(path.extname(absolutePath)) ? [relativePath] : [];
  return fs.readdirSync(absolutePath, { withFileTypes: true }).flatMap((entry) => {
    const child = path.join(relativePath, entry.name);
    return entry.isDirectory() ? walk(child) : (extensions.has(path.extname(entry.name)) ? [child] : []);
  });
}

function normalizeDeclarations(style) {
  return style
    .split(';')
    .map((part) => part.trim())
    .filter(Boolean)
    .map((part) => `${part};`)
    .join('');
}

function classNameFor(style) {
  return `u-inline-${crypto.createHash('sha1').update(style).digest('hex').slice(0, 10)}`;
}

function addClass(tag, className) {
  const classPattern = /\bclass=(["'])(.*?)\1/i;
  if (classPattern.test(tag)) {
    return tag.replace(classPattern, (_match, quote, classes) => `class=${quote}${classes} ${className}${quote}`);
  }
  return tag.replace(/^<([a-z][\w:-]*)/i, `<$1 class="${className}"`);
}

function findHtmlTagEnd(source, start) {
  let quote = '';
  let inPhp = false;
  for (let index = start + 1; index < source.length; index += 1) {
    const pair = source.slice(index, index + 2);
    if (!inPhp && pair === '<?') {
      inPhp = true;
      index += 1;
      continue;
    }
    if (inPhp && pair === '?>') {
      inPhp = false;
      index += 1;
      continue;
    }
    if (inPhp) continue;
    const char = source[index];
    if (quote) {
      if (char === quote) quote = '';
      continue;
    }
    if (char === '"' || char === "'") {
      quote = char;
      continue;
    }
    if (char === '>') return index;
  }
  return -1;
}

function extractFromSource(source, styles) {
  let output = '';
  let cursor = 0;
  const tagStartPattern = /<[a-z][\w:-]*\b/gi;
  for (let match = tagStartPattern.exec(source); match; match = tagStartPattern.exec(source)) {
    const start = match.index;
    const end = findHtmlTagEnd(source, start);
    if (end < 0) break;
    let tag = source.slice(start, end + 1);
    const styleMatch = tag.match(/\sstyle=(["'])(.*?)\1/is);
    if (styleMatch && !unsafeDynamic.test(styleMatch[2])) {
      const normalized = normalizeDeclarations(styleMatch[2]);
      const withoutStyle = tag.replace(/\sstyle=(["'])(.*?)\1/is, '');
      if (normalized) {
        const className = classNameFor(normalized);
        styles.set(className, normalized);
        tag = addClass(withoutStyle, className);
      } else {
        tag = withoutStyle;
      }
    }
    output += source.slice(cursor, start) + tag;
    cursor = end + 1;
    tagStartPattern.lastIndex = cursor;
  }
  return output + source.slice(cursor);
}

function extractStaticStyleBlocks(source, blocks) {
  return source.replace(/\s*<style(?:\s[^>]*)?>([\s\S]*?)<\/style>\s*/gi, (full, css) => {
    if (css.includes('<?')) return full;
    const normalized = css.trim();
    if (normalized) blocks.push(normalized);
    return '\n';
  });
}

for (const group of groups) {
  const files = [...new Set([...group.roots.flatMap(walk), ...group.files])];
  const styles = new Map();
  const styleBlocks = [];
  let changedFiles = 0;

  for (const relativeFile of files) {
    const absoluteFile = path.join(root, relativeFile);
    if (!fs.existsSync(absoluteFile)) continue;
    const before = fs.readFileSync(absoluteFile, 'utf8');
    let after = extractFromSource(before, styles);
    if (group.extractStyleBlocks) {
      after = extractStaticStyleBlocks(after, styleBlocks);
    }
    if (after !== before) {
      if (!checkOnly) fs.writeFileSync(absoluteFile, after);
      changedFiles += 1;
    }
  }

  if (styles.size > 0) {
    violationCount += styles.size;
    const cssPath = path.join(root, group.css);
    const markerStart = '/* generated:inline-utilities:start */';
    const markerEnd = '/* generated:inline-utilities:end */';
    const beforeCss = fs.readFileSync(cssPath, 'utf8');
    const markerPattern = new RegExp(
      `${markerStart.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}([\\s\\S]*?)${markerEnd.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`,
    );
    const oldBlock = beforeCss.match(markerPattern)?.[1]?.trim() ?? '';
    const existingClasses = new Set(
      [...oldBlock.matchAll(/\.([a-z0-9_-]+)\{/gi)].map((match) => match[1]),
    );
    const newRules = [...styles.entries()]
      .filter(([className]) => !existingClasses.has(className))
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([className, declarations]) => `.${className}{${declarations}}`)
      .join('\n');
    const rules = [oldBlock, newRules].filter(Boolean).join('\n');
    const block = `${markerStart}\n${rules}\n${markerEnd}`;
    const nextCss = markerPattern.test(beforeCss)
      ? beforeCss.replace(markerPattern, block)
      : `${beforeCss.trimEnd()}\n\n${block}\n`;
    if (!checkOnly) fs.writeFileSync(cssPath, nextCss);
  }

  if (styleBlocks.length > 0) {
    violationCount += styleBlocks.length;
    const cssPath = path.join(root, group.css);
    const markerStart = '/* generated:embedded-styles:start */';
    const markerEnd = '/* generated:embedded-styles:end */';
    const beforeCss = fs.readFileSync(cssPath, 'utf8');
    const markerPattern = new RegExp(
      `${markerStart.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}([\\s\\S]*?)${markerEnd.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`,
    );
    const oldBlock = beforeCss.match(markerPattern)?.[1]?.trim() ?? '';
    const additions = styleBlocks.filter((block) => !oldBlock.includes(block));
    const rules = [oldBlock, ...additions].filter(Boolean).join('\n\n');
    const block = `${markerStart}\n${rules}\n${markerEnd}`;
    const nextCss = markerPattern.test(beforeCss)
      ? beforeCss.replace(markerPattern, block)
      : `${beforeCss.trimEnd()}\n\n${block}\n`;
    if (!checkOnly) fs.writeFileSync(cssPath, nextCss);
  }

  process.stdout.write(`${group.css}: ${styles.size} attributes and ${styleBlocks.length} blocks extracted from ${changedFiles} files\n`);
}

if (checkOnly) {
  let dynamicInlineStyles = 0;
  let inlineScripts = 0;
  for (const relativeFile of walk('templates/blocks')) {
    const source = fs.readFileSync(path.join(root, relativeFile), 'utf8');
    // Статические style уже учитывает extractor выше. Здесь остаются PHP-
    // выражения, которые автоматическая механическая замена выполнять не
    // должна: их нужно явно вынести в scoped $templateCss.
    const withoutStaticStyles = extractFromSource(source, new Map());
    const styleMatches = withoutStaticStyles.match(/(?:\s|["'])style\s*=/gi) ?? [];
    const scriptMatches = source.match(/<script\b/gi) ?? [];
    if (styleMatches.length > 0) {
      dynamicInlineStyles += styleMatches.length;
      process.stderr.write(`${relativeFile}: ${styleMatches.length} dynamic inline style attribute(s).\n`);
    }
    if (scriptMatches.length > 0) {
      inlineScripts += scriptMatches.length;
      process.stderr.write(`${relativeFile}: ${scriptMatches.length} inline script tag(s).\n`);
    }
  }
  violationCount += dynamicInlineStyles + inlineScripts;

  const demoDirectory = path.join(root, 'database/demo_assets');
  if (fs.existsSync(demoDirectory)) {
    for (const filename of fs.readdirSync(demoDirectory).filter((name) => name.endsWith('.json'))) {
      const source = fs.readFileSync(path.join(demoDirectory, filename), 'utf8');
      const forbidden = source.match(
        /<\s*(?:script|style)\b|(?:\s|["'])style\s*=|\bon[a-z]+\s*=|javascript\s*:/gi,
      ) ?? [];
      if (forbidden.length > 0) {
        violationCount += forbidden.length;
        process.stderr.write(`database/demo_assets/${filename}: ${forbidden.length} forbidden inline marker(s).\n`);
      }
    }
  }
}

if (checkOnly && violationCount > 0) {
  process.stderr.write(`Found ${violationCount} forbidden inline declaration(s).\n`);
  process.exitCode = 1;
}
