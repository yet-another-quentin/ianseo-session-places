// Translations and release versioning: node --test tests/*.test.js
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const I = require('../SessionPlaces/i18n.js');
const { latestVersion } = require('../scripts/latest-version.js');

const read = f => fs.readFileSync(path.join(__dirname, '../SessionPlaces', f), 'utf8');

test('French and English dictionaries have the same keys', () => {
  assert.deepEqual(Object.keys(I.DICTS.fr).sort(), Object.keys(I.DICTS.en).sort());
});

test('every message key used by the admin page exists', () => {
  const used = new Set();
  for (const m of read('admin.js').matchAll(/\b(?:th|t)\('([a-z]+\.[\w.]+)'/g)) used.add(m[1]);
  // keys built at runtime ('image.lang.' + l…) end with a dot and are checked below
  const missing = [...used].filter(k => !k.endsWith('.') && !(k in I.DICTS.en));
  assert.deepEqual(missing, []);
});

test('keys built at runtime exist', () => {
  const keys = ['image.lang.fr', 'image.lang.en', 'update.channel.stable', 'update.channel.latest', 'image.on', 'image.off'];
  assert.deepEqual(keys.filter(k => !(k in I.DICTS.en)), []);
});

test('image texts: same keys in French and English', () => {
  const src = read('core.php');
  const block = lang => new Set([...src.split(`'${lang}' => array(`)[1].split('),')[0].matchAll(/'([\w.]+)' =>/g)].map(m => m[1]));
  assert.deepEqual([...block('fr')].sort(), [...block('en')].sort());
});

test('language follows IANSEO: French for fr*, English otherwise', () => {
  assert.equal(I.make('fr').lang, 'fr');
  assert.equal(I.make('fr-ca').lang, 'fr');
  assert.equal(I.make('it').lang, 'en');
  assert.equal(I.make('').lang, 'en');
});

test('placeholders are replaced, missing keys fall back to the key', () => {
  const fr = I.make('fr');
  assert.equal(fr.t('sessions.label', { n: 2 }), 'Départ 2');
  assert.equal(fr.t('nope.key'), 'nope.key');
});

test('latest version follows the last stable tag', () => {
  assert.equal(latestVersion('v1.2.3', '202610041530', 'abc1234'), '1.2.4-latest.202610041530.abc1234');
  assert.equal(latestVersion('', '202610041530', 'abc1234'), '0.0.1-latest.202610041530.abc1234');
});
