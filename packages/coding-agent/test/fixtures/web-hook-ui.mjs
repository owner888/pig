import assert from 'node:assert/strict';

// The requests `RpcUi` actually sent, in order, and a `get_commands` answer from `RpcMode`.
const root = process.argv[2];
const fixture = JSON.parse(Buffer.from(process.argv[3], 'base64').toString('utf8'));
const hooks = await import(`${root}/hooks.js`);

const state = hooks.createHookState();
const changed = fixture.requests.map((req) => hooks.applyHookRequest(state, req));
const byMethod = Object.fromEntries(fixture.requests.map((r) => [r.method, r]));

// Widgets: the default placement is above, `below` is below, a cleared key is gone, and a key
// set again keeps its place. Colour escapes are taken off.
assert.deepEqual(hooks.widgetsAt(state, 'above'), [
  { key: 'progress', lines: ['step 2 of 3'] },
  { key: 'plain', lines: ['one', 'two'] },
]);
assert.deepEqual(hooks.widgetsAt(state, 'below'), [{ key: 'colour', lines: ['red text'] }]);

// Statuses: a cleared one goes, the rest are joined as the footer joins them.
assert.equal(hooks.statusLine(state), 'mcp: 2 servers · lint ok');
assert.equal(state.title, 'pig — building');
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
