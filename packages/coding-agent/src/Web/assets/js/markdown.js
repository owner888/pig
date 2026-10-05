import { escapeHtml } from "./utils.js";
import { t } from "./i18n.js";

/** Check if a line is a Markdown table separator (e.g. |:---|:---:|---:| or --- | ---) */
function isTableSeparator(line) {
  return /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)+\|?\s*$/.test(line);
}

/** Render inline Markdown elements: code, bold, italic, strikethrough, safe links */
function renderInline(escapedStr) {
  if (!escapedStr) return "";
  return String(escapedStr)
    .replace(/`([^`]+)`/g, "<code>$1</code>")
    .replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>")
    .replace(/__([^_]+)__/g, "<strong>$1</strong>")
    .replace(/\*([^*]+)\*/g, "<em>$1</em>")
    .replace(/_([^_]+)_/g, "<em>$1</em>")
    .replace(/~~([^~]+)~~/g, "<del>$1</del>")
    .replace(/\[([^\]]+)\]\(([^)]+)\)/g, (m, txt, url) => {
      const u = url.trim();
      // Allow only safe protocols, forbid javascript:, data:, vbscript:
      if (/^(https?:|\/|#|mailto:)/i.test(u) && !/^(javascript:|data:|vbscript:)/i.test(u)) {
        return `<a href="${u}" target="_blank" rel="noopener noreferrer">${txt}</a>`;
      }
      return txt;
    });
}

/** Parse lines forming a Markdown table into an HTML table with column alignment */
function parseTable(lines) {
  const parseRow = (line) => {
    let trimmed = line.trim();
    if (trimmed.startsWith("|")) trimmed = trimmed.slice(1);
    if (trimmed.endsWith("|")) trimmed = trimmed.slice(0, -1);
    return trimmed.split("|").map(c => c.trim());
  };

  const headers = parseRow(lines[0]);
  const sepLine = parseRow(lines[1]);
  const alignments = sepLine.map(col => {
    const left = col.startsWith(":");
    const right = col.endsWith(":");
    if (left && right) return "center";
    if (right) return "right";
    if (left) return "left";
    return "";
  });

  let html = '<div class="table-wrapper"><table class="markdown-table"><thead><tr>';
  headers.forEach((h, i) => {
    const align = alignments[i] ? ` style="text-align:${alignments[i]}"` : "";
    html += `<th${align}>${renderInline(h)}</th>`;
  });
  html += '</tr></thead><tbody>';

  for (let r = 2; r < lines.length; r++) {
    const row = parseRow(lines[r]);
    if (row.length === 1 && row[0] === "") continue;
    html += '<tr>';
    headers.forEach((_, i) => {
      const cell = row[i] !== undefined ? row[i] : "";
      const align = alignments[i] ? ` style="text-align:${alignments[i]}"` : "";
      html += `<td${align}>${renderInline(cell)}</td>`;
    });
    html += '</tr>';
  }
  html += '</tbody></table></div>';
  return html;
}

export function renderMarkdown(rawMd) {
  if (!rawMd) return "";

  // 1. Protect fenced code blocks with placeholders
  const codeBlocks = [];
  let text = String(rawMd).replace(/```([a-zA-Z0-9_\-\+\.]*)\n([\s\S]*?)```/g, (m, lang, code) => {
    const l = lang ? lang.trim() : "code";
    const idx = codeBlocks.length;
    codeBlocks.push(
      `<div class="code-block-wrapper"><div class="code-block-header"><span class="code-lang-tag">${escapeHtml(l)}</span><button class="code-copy-btn" onclick="copyCode(this)" title="${escapeHtml(t("copy"))}">📋 ${escapeHtml(t("copy"))}</button></div><pre><code class="language-${l}">${escapeHtml(code.trim())}</code></pre></div>`
    );
    return `\n\n__CODE_BLOCK_${idx}__\n\n`;
  });

  // 2. Escape HTML special characters for the rest of the text
  text = escapeHtml(text);

  // 3. Process lines for block-level elements: Tables, Quotes, Lists, Headings, Rules
  const lines = text.split("\n");
  const out = [];
  let i = 0;

  while (i < lines.length) {
    const line = lines[i];

    // 3.1 GFM Table: current line has '|', next line is separator
    if (i + 1 < lines.length && isTableSeparator(lines[i + 1]) && line.includes("|")) {
      const tableLines = [line, lines[i + 1]];
      i += 2;
      while (i < lines.length && lines[i].trim() !== "" && lines[i].includes("|")) {
        tableLines.push(lines[i]);
        i++;
      }
      out.push(parseTable(tableLines));
      continue;
    }

    // 3.2 Blockquotes: lines starting with &gt; (escaped >)
    if (/^\s*&gt;\s?/.test(line)) {
      const quoteLines = [];
      while (i < lines.length && /^\s*&gt;\s?/.test(lines[i])) {
        quoteLines.push(lines[i].replace(/^\s*&gt;\s?/, ""));
        i++;
      }
      out.push(`<blockquote>${quoteLines.map(renderInline).join("<br>")}</blockquote>`);
      continue;
    }

    // 3.3 Unordered lists: - / * / +
    if (/^\s*[-*+]\s+(.+)/.test(line)) {
      const listItems = [];
      while (i < lines.length && /^\s*[-*+]\s+(.+)/.test(lines[i])) {
        const itemContent = lines[i].replace(/^\s*[-*+]\s+/, "");
        listItems.push(`<li>${renderInline(itemContent)}</li>`);
        i++;
      }
      out.push(`<ul>${listItems.join("")}</ul>`);
      continue;
    }

    // 3.4 Ordered lists: 1. / 2.
    if (/^\s*\d+\.\s+(.+)/.test(line)) {
      const listItems = [];
      while (i < lines.length && /^\s*\d+\.\s+(.+)/.test(lines[i])) {
        const itemContent = lines[i].replace(/^\s*\d+\.\s+/, "");
        listItems.push(`<li>${renderInline(itemContent)}</li>`);
        i++;
      }
      out.push(`<ol>${listItems.join("")}</ol>`);
      continue;
    }

    // 3.5 Headings: # to ######
    const headingMatch = line.match(/^(#{1,6})\s+(.+)$/);
    if (headingMatch) {
      const level = headingMatch[1].length;
      out.push(`<h${level}>${renderInline(headingMatch[2])}</h${level}>`);
      i++;
      continue;
    }

    // 3.6 Horizontal Rules: --- / *** / ___
    if (/^\s*([-*_]\s*){3,}\s*$/.test(line)) {
      out.push("<hr>");
      i++;
      continue;
    }

    out.push(line);
    i++;
  }

  // 4. Assemble consecutive non-block lines into paragraphs
  const blocks = [];
  let currentParagraph = [];

  const flushParagraph = () => {
    if (currentParagraph.length > 0) {
      const text = currentParagraph.join("<br>").trim();
      if (text) blocks.push(`<p>${text}</p>`);
      currentParagraph = [];
    }
  };

  for (const item of out) {
    if (typeof item !== "string") continue;
    if (
      item.startsWith("<div") ||
      item.startsWith("<table") ||
      item.startsWith("<ul") ||
      item.startsWith("<ol") ||
      item.startsWith("<blockquote") ||
      item.startsWith("<h") ||
      item.startsWith("<hr>") ||
      item.startsWith("__CODE_BLOCK_")
    ) {
      flushParagraph();
      blocks.push(item);
    } else if (item.trim() === "") {
      flushParagraph();
    } else {
      currentParagraph.push(renderInline(item.trim()));
    }
  }
  flushParagraph();

  // 5. Restore protected code blocks
  let finalHtml = blocks.join("\n");
  finalHtml = finalHtml.replace(/__CODE_BLOCK_(\d+)__/g, (m, idx) => codeBlocks[Number(idx)] || "");
  return finalHtml;
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
