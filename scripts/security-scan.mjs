import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { extname } from 'node:path';

const binaryExtensions = new Set([
  '.avif', '.bmp', '.doc', '.docx', '.eot', '.gif', '.ico', '.jpeg', '.jpg',
  '.mp3', '.mp4', '.ogg', '.pdf', '.png', '.ttf', '.wav', '.webm', '.webp',
  '.woff', '.woff2', '.xls', '.xlsx', '.zip',
]);

const excludedFiles = new Set([
  'composer.lock',
  'package-lock.json',
]);

const rules = [
  ['private key', /-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/g],
  ['AWS access key', /\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/g],
  ['GitHub token', /\bgh(?:p|o|u|s|r)_[A-Za-z0-9]{30,}\b/g],
  ['Slack token', /\bxox(?:b|p|a|r|s)-[A-Za-z0-9-]{20,}\b/g],
  ['Stripe live secret', /\bsk_live_[A-Za-z0-9]{20,}\b/g],
  ['OpenAI API key', /\bsk-(?:proj-)?[A-Za-z0-9_-]{40,}\b/g],
  ['Google API key', /\bAIza[A-Za-z0-9_-]{35}\b/g],
  ['Telegram bot token', /\b[0-9]{8,12}:[A-Za-z0-9_-]{35}\b/g],
];

function trackedFiles() {
  return execFileSync('git', ['ls-files', '-z'], { encoding: 'utf8' })
    .split('\0')
    .filter(Boolean);
}

const findings = [];
for (const file of trackedFiles()) {
  if (excludedFiles.has(file) || binaryExtensions.has(extname(file).toLowerCase())) {
    continue;
  }

  let source;
  try {
    source = readFileSync(file, 'utf8');
  } catch {
    continue;
  }
  if (source.includes('\0')) {
    continue;
  }

  for (const [name, pattern] of rules) {
    pattern.lastIndex = 0;
    for (const match of source.matchAll(pattern)) {
      const line = source.slice(0, match.index).split('\n').length;
      findings.push(`${file}:${line}: ${name}`);
    }
  }
}

if (findings.length > 0) {
  console.error('Potential secrets detected (values are intentionally hidden):');
  for (const finding of findings) {
    console.error(`- ${finding}`);
  }
  process.exit(1);
}

console.log('Security scan passed: no known secret formats in tracked text files.');
