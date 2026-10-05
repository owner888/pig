import { escapeHtml } from "./utils.js";
import { t } from "./i18n.js";

export function renderMarkdown(md) {
  if (!md) return "";
  let html = String(md)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/```([a-zA-Z0-9_\-\+\.]*)\n([\s\S]*?)```/g, (m, lang, code) => {
      const l = lang ? lang.trim() : "code";
      return `<div class="code-block-wrapper"><div class="code-block-header"><span class="code-lang-tag">${escapeHtml(l)}</span><button class="code-copy-btn" onclick="copyCode(this)" title="${escapeHtml(t("copy"))}">📋 ${escapeHtml(t("copy"))}</button></div><pre><code class="language-${l}">${code.trim()}</code></pre></div>`;
    })
    .replace(/`([^`]+)`/g, "<code>$1</code>")
    .replace(/^### (.*$)/gim, "<h3>$1</h3>")
    .replace(/^## (.*$)/gim, "<h2>$1</h2>")
    .replace(/^# (.*$)/gim, "<h1>$1</h1>")
    .replace(/\n\n/g, "</p><p>");
  return `<p>${html}</p>`;
}

export function copyCode(btn) {
  const wrapper = btn.closest(".code-block-wrapper");
  const codeEl = wrapper?.querySelector("code");
  if (!codeEl) return;
  const text = codeEl.innerText;
  navigator.clipboard.writeText(text).then(() => {
    btn.classList.add("copied");
    btn.innerText = "✓ " + t("copied");
    setTimeout(() => {
      btn.classList.remove("copied");
      btn.innerText = "📋 " + t("copy");
    }, 1500);
  });
}

// Make copyCode available to inline onclick in generated HTML
if (typeof window !== "undefined") {
  window.copyCode = copyCode;
}

export function renderDiff(oldText, newText) {
  const oldLines = oldText ? oldText.split("\n") : [];
  const newLines = newText ? newText.split("\n") : [];
  let html = '<div class="diff-container">';
  for (const line of oldLines) {
    html += `<div class="diff-line diff-del">- ${escapeHtml(line)}</div>`;
  }
  for (const line of newLines) {
    html += `<div class="diff-line diff-add">+ ${escapeHtml(line)}</div>`;
  }
  html += '</div>';
  return html;
}

export function renderDiffFromUnified(unified) {
  const lines = String(unified).split("\n");
  let html = '<div class="diff-container">';
  for (const line of lines) {
    if (line.startsWith("+")) {
      html += `<div class="diff-line diff-add">${escapeHtml(line)}</div>`;
    } else if (line.startsWith("-")) {
      html += `<div class="diff-line diff-del">${escapeHtml(line)}</div>`;
    } else {
      html += `<div class="diff-line diff-ctx">${escapeHtml(line)}</div>`;
    }
  }
  html += '</div>';
  return html;
}
