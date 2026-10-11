import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

// Every translation key the page uses is in both of the page's languages, and both have the same
// keys — so no button reads `node_up` in English, or falls back to English in Chinese.
const root = process.argv[2];
globalThis.localStorage = { getItem: () => null, setItem() {} };
Object.defineProperty(globalThis, 'navigator', { value: { language: 'en' }, configurable: true });
globalThis.document = { querySelectorAll: () => [], documentElement: {}, getElementById: () => null };
const { I18N } = await import(path.join(root, 'js/i18n.js'));
const zh = I18N['zh-CN'];
const en = I18N.en;

assert.deepEqual(Object.keys(zh).filter((k) => !(k in en)), [], 'keys only in Chinese');
assert.deepEqual(Object.keys(en).filter((k) => !(k in zh)), [], 'keys only in English');

const files = [];
const walk = (dir) => {
  for (const name of fs.readdirSync(dir)) {
    const p = path.join(dir, name);
    if (fs.statSync(p).isDirectory()) { if (name !== 'vendor') walk(p); }
    else if (/\.(js|html)$/.test(name) && !['i18n.js', 'login.js', 'login.html'].includes(name)) files.push(p);
  }
};
walk(root);

const missing = [];
for (const file of files) {
  fs.readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
    const keys = [];
    // `t("key")` / `__t("key")`; a key built at run time (`sched_freq_${x}`) ends in `_` here and is skipped.
    for (const m of line.matchAll(/(?<![\w.$])(?:__t|t)\(\s*(["'])([a-z0-9_]+)\1/g)) keys.push(m[2]);
    for (const m of line.matchAll(/data-i18n="([^"]+)"/g)) keys.push(m[1]);
    for (const m of line.matchAll(/data-i18n-attr="([^"]+)"/g)) for (const pair of m[1].split(',')) keys.push(pair.split(':')[1]?.trim());
    for (const key of keys) {
      if (!key || key.endsWith('_')) continue;
      if (!(key in zh) || !(key in en)) missing.push(`${path.relative(root, file)}:${i + 1} ${key}`);
    }
  });
}
assert.deepEqual(missing, [], 'keys the page uses that a language lacks');

console.log('Web i18n: passed');
