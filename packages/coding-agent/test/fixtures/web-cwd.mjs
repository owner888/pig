import assert from 'node:assert/strict';

// `cwd.js`, the picker's path handling, against a real `/api/dirs` listing of a `Projects` folder.
const root = process.argv[2];
const { listing } = JSON.parse(Buffer.from(process.argv[3], 'base64').toString('utf8'));
const { splitTyped, matching, joinPath, folderName } = await import(`${root}/cwd.js`);

// What is typed: the folder to list, and the start of the name inside it.
assert.deepEqual(splitTyped('/Users/me/Dev'), { dir: '/Users/me/', prefix: 'Dev' });
assert.deepEqual(splitTyped('/Users/me/'), { dir: '/Users/me/', prefix: '' });
assert.deepEqual(splitTyped('/'), { dir: '/', prefix: '' });
assert.deepEqual(splitTyped('/Users'), { dir: '/', prefix: 'Users' });
assert.deepEqual(splitTyped('~'), { dir: '~', prefix: '' });
assert.deepEqual(splitTyped('~/De'), { dir: '~/', prefix: 'De' });
assert.deepEqual(splitTyped('  pi  '), { dir: '', prefix: 'pi' }, 'relative, trimmed');

// Completing against the listing, case aside.
assert.deepEqual(matching(listing.dirs, 'pi'), ['pi', 'pig']);
assert.deepEqual(matching(listing.dirs, 'PIG'), ['pig']);
assert.deepEqual(matching(listing.dirs, ''), ['pi', 'pig', 'smart-book']);
assert.deepEqual(matching(listing.dirs, 'x'), []);
assert.deepEqual(matching(undefined, 'x'), []);

assert.equal(joinPath(listing.path, 'pig'), `${listing.path}/pig`);
assert.equal(joinPath('/', 'Users'), '/Users');

assert.equal(folderName(`${listing.path}/pig`), 'pig');
assert.equal(folderName('/Users/me/'), 'me');
assert.equal(folderName('/'), '/');
assert.equal(folderName(''), '~');

console.log('Web cwd: passed');
