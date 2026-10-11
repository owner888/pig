import assert from 'node:assert/strict';

// The requests `RpcUi` actually sent, in order, and a `get_commands` answer from `RpcMode`.
const root = process.argv[2];
const fixture = JSON.parse(Buffer.from(process.argv[3], 'base64').toString('utf8'));
const hooks = await import(`${root}/hooks.js`);

const state = hooks.createHookState();
const changed = fixture.requests.map((req) => hooks.applyHookRequest(state, req));
const byMethod = Object.fromEntries(fixture.requests.map((r) => [r.method, r]));

// Widgets: the default placement is above, `below` is below, a cleared key is gone, and a key
// set again keeps its place. Colour escapes are kept, for `ansiToHtml()` to draw.
assert.deepEqual(hooks.widgetsAt(state, 'above'), [
  { key: 'progress', lines: ['step 2 of 3'] },
  { key: 'plain', lines: ['one', 'two'] },
]);
assert.deepEqual(hooks.widgetsAt(state, 'below'), [{ key: 'colour', lines: ['\x1b[31mred\x1b[39m text'] }]);
// The sixteen colours are the page's theme tokens, as its terminals' palette is.
assert.equal(hooks.ansiToHtml(hooks.widgetsAt(state, 'below')[0].lines[0]), '<span style="color:var(--color-error)">red</span> text');

// Statuses: a cleared one goes, the rest are joined as the footer joins them — as text, and
// coloured, where the theme's own colour is the page theme's token, exactly.
assert.equal(hooks.statusLine(state), 'mcp: 2 servers · lint ok · ok');
assert.equal(
  hooks.statusHtml(state),
  `mcp: 2 servers · <span style="color:var(--color-success)">lint ok</span> · <span style="color:${fixture.successRgb}">ok</span>`,
);
assert.equal(state.title, 'pig — building');

// An extension's `setTheme()`: for the page to apply, not state of the tab.
assert.equal(byMethod.setTheme.theme, 'light');
assert.equal(hooks.applyHookRequest(state, byMethod.setTheme), null);

// What `ansiToHtml()` makes of the rest of SGR, and of text that is not text.
assert.equal(hooks.ansiToHtml('<b>&"'), '&lt;b&gt;&amp;&quot;');
assert.equal(hooks.ansiToHtml('\x1b[1mbold\x1b[22m plain'), '<span style="font-weight:700">bold</span> plain');
assert.equal(hooks.ansiToHtml('\x1b[2;3;4;9mx\x1b[0my'), '<span style="opacity:0.6;font-style:italic;text-decoration:underline line-through">x</span>y');
assert.equal(hooks.ansiToHtml('\x1b[38;5;196mx\x1b[48;5;244my\x1b[m'), '<span style="color:rgb(255, 0, 0)">x</span><span style="color:rgb(255, 0, 0);background-color:rgb(128, 128, 128)">y</span>');
assert.equal(hooks.ansiToHtml('\x1b[38;5;9mx'), '<span style="color:var(--color-error)">x</span>');
assert.equal(hooks.ansiToHtml('\x1b[90mx'), '<span style="color:var(--color-dim)">x</span>');
assert.equal(hooks.ansiToHtml('\x1b[44mx\x1b[49m'), '<span style="background-color:var(--color-info)">x</span>');
assert.equal(hooks.ansiToHtml('\x1b[7mx'), '<span style="color:var(--color-background);background-color:var(--color-text-primary)">x</span>');
assert.equal(hooks.ansiToHtml('\x1b]8;;https://x.test\x07link\x1b]8;;\x07 \x1b[2Kgone'), 'link gone');
assert.ok(changed.includes('widgets') && changed.includes('statuses') && changed.includes('title'));

// What is not state is not taken for it.
assert.equal(hooks.applyHookRequest(state, byMethod.notify), null);
assert.equal(hooks.applyHookRequest(state, byMethod.select), null);

// notify's three levels, as `TerminalUi::notify()` draws them.
assert.deepEqual(fixture.notifies.map(hooks.notifyKind), ['notice', 'warning', 'error']);

// A dialog with a timeout counts down from the request's milliseconds; one without has none.
assert.equal(hooks.timeoutSeconds(byMethod.select), 2);
assert.equal(hooks.timeoutSeconds(byMethod.confirm), null);
assert.equal(hooks.countdownTitle('Pick one', 2), 'Pick one (2s)');

// The commands a conversation offers beyond the built-ins.
assert.deepEqual(hooks.slashCommandsFrom(fixture.commands).map((c) => c.name), ['deploy', 'review', 'skill:release']);
assert.deepEqual(hooks.slashCommandsFrom(null), []);

console.log('Web hook ui: passed');
