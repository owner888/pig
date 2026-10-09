import fs from 'node:fs';
import assert from 'node:assert/strict';

const root = process.argv[2];
const fixture = JSON.parse(Buffer.from(process.argv[3], 'base64').toString('utf8'));

class Element {
  constructor(tag = 'div') {
    this.tagName = tag.toUpperCase();
    this.className = '';
    this.id = '';
    this.dataset = {};
    this.style = {};
    this.innerHTML = '';
    this.textContent = '';
    this.title = '';
    this.hidden = false;
    this.open = false;
    this.children = [];
    this.scrollTop = 0;
    this.scrollHeight = 0;
    this.clientHeight = 400;
    this._queries = new Map();
    this.classList = {
      add: (...names) => { for (const n of names) if (!this.className.split(/\s+/).includes(n)) this.className = `${this.className} ${n}`.trim(); },
      remove: (...names) => { this.className = this.className.split(/\s+/).filter(n => n && !names.includes(n)).join(' '); },
      toggle: (name, force) => {
        const has = this.className.split(/\s+/).includes(name);
        const next = force === undefined ? !has : !!force;
        if (next) this.classList.add(name); else this.classList.remove(name);
        return next;
      },
      contains: (name) => this.className.split(/\s+/).includes(name),
    };
  }
  appendChild(child) { this.children.push(child); child.parentNode = this; this.scrollHeight = this.children.length * 100; return child; }
  insertBefore(child) { this.children.unshift(child); child.parentNode = this; return child; }
  addEventListener() {}
  removeEventListener() {}
  remove() { this.removed = true; }
  focus() {}
  closest() { return null; }
  querySelector(selector) {
    if (!this._queries.has(selector)) this._queries.set(selector, new Element('div'));
    return this._queries.get(selector);
  }
  querySelectorAll(selector) {
    if (selector === '[data-summary-key]') {
      return this.children.filter((c) => c.dataset && c.dataset.summaryKey !== undefined);
    }
    return [];
  }
}

const byId = new Map();
function el(id) { if (!byId.has(id)) byId.set(id, new Element('div')); return byId.get(id); }

globalThis.document = { createElement: (tag) => new Element(tag), body: new Element('body'), getElementById: el };
globalThis.window = globalThis;
globalThis.localStorage = { getItem: () => null, setItem() {} };
Object.defineProperty(globalThis, 'navigator', { value: { language: 'en', clipboard: { writeText: async () => {} } }, configurable: true });
globalThis.requestAnimationFrame = (f) => f();
globalThis.fetch = async () => ({ ok: true, json: async () => ({ success: true, locales: {} }) });

const { escapeHtml } = await import(`${root}/utils.js`);
const { t } = await import(`${root}/i18n.js`);
const { renderMarkdown } = await import(`${root}/markdown.js`);
const { ToolCard } = await import(`${root}/components/ToolCard.js`);
const { ThinkingBlock } = await import(`${root}/components/ThinkingBlock.js`);
const { EmptyState } = await import(`${root}/components/EmptyState.js`);

const chatScroll = new Element('div');
const promptInput = new Element('textarea');
const active = { toolCards: new Map(), emptyState: null };
const T = () => active;
const scrollToBottomIfNeeded = () => { chatScroll.scrollTop = chatScroll.scrollHeight; };
const openImageLightbox = () => {};
function textOf(content) { if (!Array.isArray(content)) return ''; return content.filter((c) => c && c.type === 'text').map((c) => c.text || '').join(''); }
function appendErrorMessage(errorText) { const block = new Element('div'); block.className = 'msg-block msg-error'; block.innerHTML = `<strong>Error:</strong> ${escapeHtml(errorText)}`; chatScroll.appendChild(block); }

const app = fs.readFileSync(`${root}/app.js`, 'utf8');
// The page's own user-message renderer, skill block included — a stub here would be a second
// answer to what a user message looks like, which is the fork this test exists to prevent.
const userStart = app.indexOf('    const SKILL_BLOCK');
const userEnd = app.indexOf('    function ensureAssistantBlock', userStart);
assert.notEqual(userStart, -1);
assert.notEqual(userEnd, -1);
const start = app.indexOf('function appendSummaryMessage');
const end = app.indexOf('    // ---- boot', start);
assert.notEqual(start, -1);
assert.notEqual(end, -1);
const factory = new Function('chatScroll', 'promptInput', 'T', 'EmptyState', 'ThinkingBlock', 'ToolCard', 'escapeHtml', 't', 'renderMarkdown', 'openImageLightbox', 'scrollToBottomIfNeeded', 'textOf', 'appendErrorMessage', `${app.slice(userStart, userEnd)}\n${app.slice(start, end)}\nreturn { appendSummaryMessage, appendHookMessage, appendUserMessage, renderMessages };`);
const { appendSummaryMessage, appendHookMessage, appendUserMessage, renderMessages } = factory(chatScroll, promptInput, T, EmptyState, ThinkingBlock, ToolCard, escapeHtml, t, renderMarkdown, openImageLightbox, scrollToBottomIfNeeded, textOf, appendErrorMessage);

renderMessages(fixture.messages);
const html = chatScroll.children.map((c) => c.innerHTML).join('\n');
assert.match(html, /\[compaction\]/);
// The skill block is a collapsed card with the skill's name, its text inside, and the person's
// own words as the user message after it — the raw `<skill>` tag is nowhere on the page.
const skillCard = chatScroll.children.find((c) => c.className.includes('skill-invocation'));
assert.ok(skillCard, 'the skill invocation card was not drawn');
assert.equal(skillCard.tagName, 'DETAILS');
assert.match(skillCard.innerHTML, /\[skill\]<\/span> deploy/);
assert.match(skillCard.innerHTML, /run the pipeline/);
const userBlocks = chatScroll.children.filter((c) => c.className.includes('msg-user'));
// The page builds the bubble from child elements, so the text is one level down.
assert.ok(userBlocks.some((c) => c.children.some((ch) => /ship it/.test(ch.innerHTML))), 'the words typed after the skill were not shown as the user message');
assert.doesNotMatch(html, /&lt;skill name=|<skill name=/);
assert.match(html, /Compacted from 256,653 tokens/);
assert.match(html, /\[branch summary\]/);
assert.match(html, /Branch summary provided by a hook/);
assert.match(html, /\[build\]/);
assert.match(html, /one/);
assert.match(html, /1 more lines/);
assert.doesNotMatch(html, /do not display/);
assert.match(html, /\$ exit 3/);
assert.match(html, /\$ seq 2500/);
assert.match(html, /\$ sleep 5/);

const before = chatScroll.children.length;
appendSummaryMessage(fixture.events.find(e => e.type === 'auto_compaction_end').summary);
appendSummaryMessage(fixture.events.find(e => e.type === 'message_end' && e.message.role === 'compactionSummary').message);
assert.equal(chatScroll.children.length, before, 'duplicate compaction event appended another block');
appendHookMessage(fixture.events.find(e => e.type === 'message_end' && e.message.role === 'custom').message);
assert.equal(chatScroll.children.length, before + 1, 'live hook message was not appended');
appendHookMessage(fixture.events.find(e => e.type === 'message_end' && e.message.role === 'custom' && e.message.display === false)?.message || { display: false });
assert.equal(chatScroll.children.length, before + 1, 'hidden hook message was appended');

const edit = new ToolCard({ toolCallId: 'edit1', toolName: 'edit', arguments: { path: 'a.php', oldText: 'preview-old', newText: 'preview-new' } });
edit.result = { content: [{ type: 'text', text: 'Edited' }], details: { diff: '-1 actual-old\n+1 actual-new' } };
const editBody = edit.formatResultBody();
assert.match(editBody, /actual-old/);
assert.doesNotMatch(editBody, /preview-old/);

const write = new ToolCard({ toolCallId: 'write1', toolName: 'write', arguments: { path: 'b.php', content: 'written contents' } });
write.result = { content: [{ type: 'text', text: 'Wrote 16 bytes' }] };
assert.match(write.formatResultBody(), /written contents/);

const bash = new ToolCard({ toolCallId: 'bash1', toolName: 'bash', arguments: { command: 'exit 3' } });
bash.finish({ result: { content: [{ type: 'text', text: '' }], details: { exitCode: 3, fullOutputPath: '/tmp/full.txt' } }, isError: true });
assert.match(bash.queryFooter?.textContent || bash.element.querySelector('.tool-details-footer').textContent, /exit 3|\(exit 3\)/);
assert.match(bash.element.querySelector('.tool-details-footer').textContent, /Full output: \/tmp\/full.txt/);

console.log('Web message blocks: passed');
