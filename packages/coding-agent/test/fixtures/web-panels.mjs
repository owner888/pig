import assert from 'node:assert/strict';

// The Antigravity accounts panel's real blocks (`AccountsPanel`), drawn by the page's `panels.js`.
const root = process.argv[2];
const fixture = JSON.parse(Buffer.from(process.argv[3], 'base64').toString('utf8'));
const { blocksToHtml, panelForCommand, placementOf } = await import(`${root}/panels.js`);

const accounts = blocksToHtml(fixture.accounts);

// Two accounts, the active one marked; every string is text, so the address's `<two>` is escaped.
assert.equal((accounts.match(/class="panel-item[" ]/g) || []).length, 2);
assert.equal((accounts.match(/class="panel-item active"/g) || []).length, 1);
assert.ok(accounts.includes('&lt;two&gt;@example.com'));
assert.ok(!accounts.includes('<two>'));
assert.ok(accounts.includes('&lt;b&gt;bad&lt;/b&gt;'), 'a problem is text too');
assert.ok(accounts.includes('<span class="panel-item-meta tone-error">token expired</span>'));

// The buttons carry their action, arguments and question for `openPanel()`.
assert.ok(accounts.includes('data-action="rotate"'));
assert.ok(accounts.includes(`data-action="activate" data-args="${'{"id":"a2"}'.replace(/"/g, '&quot;')}"`));
assert.equal((accounts.match(/data-action="remove"/g) || []).length, 2);
assert.ok(accounts.includes('class="mini-btn panel-action danger"'));
assert.ok(accounts.includes('data-confirm="Forget &lt;two&gt;@example.com? Its tokens are removed from this machine."'));

// The quota is a section drawn later.
assert.ok(accounts.includes('<div class="panel-lazy" data-section="usage">'));

const usage = blocksToHtml(fixture.usage);
assert.ok(usage.includes('<div class="panel-heading">Claude and GPT models</div>'));
assert.ok(usage.includes('<span class="panel-meter-pct">15%</span>'));
assert.ok(usage.includes('<div class="panel-meter-bar low"><div style="width:15%"></div></div>'));
assert.ok(usage.includes('<div class="panel-meter-bar empty"><div style="width:0%"></div></div>'));
assert.ok(usage.includes('<div class="panel-meter-detail">resets in 1h 30m</div>'));

// What the page does not know, or that is not a block, draws nothing rather than breaking.
assert.equal(blocksToHtml([{ type: 'chart' }, null, 'x', { type: 'lazy' }, { type: 'actions', actions: [{ label: 'no action' }] }]), '<div class="panel-actions"></div>');
assert.equal(blocksToHtml(undefined), '');
assert.ok(blocksToHtml([{ type: 'meter', label: 'over', value: 7 }]).includes('width:100%'));
assert.ok(blocksToHtml([{ type: 'text', text: 'x', tone: 'evil" onclick="' }]).includes('class="panel-text"'), 'an unknown tone is no tone');

// A panel's commands open it: `/accounts` and the extension's own name.
const panels = [{ id: 'antigravity.accounts', commands: ['accounts', 'antigravity.accounts'] }];
assert.equal(panelForCommand(panels, '/accounts')?.id, 'antigravity.accounts');
assert.equal(panelForCommand(panels, ' /antigravity.accounts ')?.id, 'antigravity.accounts');
assert.equal(panelForCommand(panels, '/accounts now'), null);
assert.equal(panelForCommand([], '/accounts'), null);

// Where a panel goes: the person's move, else the extension's placement, else the sidebar.
const top = { id: 'a', placement: 'header' };
assert.equal(placementOf(top, {}), 'header');
assert.equal(placementOf(top, { a: 'sidebar' }), 'sidebar');
assert.equal(placementOf({ id: 'b' }, {}), 'sidebar');
assert.equal(placementOf({ id: 'b', placement: 'footer' }, { b: 'nowhere' }), 'sidebar', 'nonsense is neither');
assert.equal(placementOf({ id: 'b' }, { b: 'header' }), 'header');
assert.equal(placementOf(top, null), 'header');

console.log('Web panels: passed');
