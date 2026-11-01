/**
 * Unified diffs inside command output, coloured when the card is drawn.
 *
 * `git diff` colours only on a terminal, and pig runs commands without one, so a bash card showing
 * a diff was plain text while the edit card beside it was red and green — the developer asked why.
 * Colour is added here, at drawing time, and never asked of the command: `--color` would put
 * escape codes into the tool result, which the model reads and pays for.
 *
 * Only real diff structure is coloured. A region starts at a `diff --git` line, or at a `--- x` /
 * `+++ y` pair followed by an `@@ -a,b +c,d @@` hunk header, and a hunk is read by its counts — so
 * `ls -l` (`-rw-r--r--`), a YAML or Markdown list, an `npm ls` tree or a line that happens to begin
 * with `+` stays plain. What the counts do not cover ends the region; the text around it is drawn as
 * it always was.
 */

const HUNK = /^@@ -\d+(?:,(\d+))? \+\d+(?:,(\d+))? @@/;
const FILE_HEADER = /^(?:diff --git |index [0-9a-f]+\.\.[0-9a-f]+|new file mode |deleted file mode |old mode |new mode |similarity index |dissimilarity index |rename from |rename to |copy from |copy to |Binary files .* differ$|--- \S|\+\+\+ \S)/;

/** The cheap test first: no hunk header, no diff — most command output never gets further. */
export function mayHoldDiff(text) {
  return typeof text === "string" && (text.startsWith("@@ -") || text.includes("\n@@ -") || text.includes("diff --git "));
}

/**
 * Each line's kind: null for text outside a diff, else `meta`, `hunk`, `add`, `del` or `ctx`.
 *
 * @param {string[]} lines
 * @returns {(null|"meta"|"hunk"|"add"|"del"|"ctx")[]}
 */
export function classifyDiffLines(lines) {
  const kinds = new Array(lines.length).fill(null);
  let i = 0;

  while (i < lines.length) {
    const start = regionStart(lines, i);
    if (start === null) { i++; continue; }

    // Headers up to the first hunk (or, for `diff --git` with no hunk — a binary file, a mode
    // change — up to whatever is not a header).
    let j = i;
    while (j < lines.length && !HUNK.test(lines[j]) && (j === i || FILE_HEADER.test(lines[j]))) {
      if (j > i && lines[j].startsWith("diff --git ")) break;
      kinds[j++] = "meta";
    }

    // Hunks, each read by its counts, until a line that is not one.
    let hunks = 0;
    while (j < lines.length) {
      const m = HUNK.exec(lines[j]);
      const end = m ? readHunk(lines, j, m, kinds) : null;
      if (end === null) break;
      hunks++;
      j = end;
    }

    // `---`/`+++` were only a header because a hunk followed; with none that added up, they were
    // text after all. (`diff --git` is a header whatever follows it.)
    if (hunks === 0 && !lines[start].startsWith("diff --git ")) kinds.fill(null, start, j);
    i = Math.max(j, i + 1);
  }

  return kinds;
}

/** Where a diff begins: a `diff --git` line, or `---`/`+++` with a hunk header next. */
function regionStart(lines, i) {
  const line = lines[i];
  if (line.startsWith("diff --git ")) return i;
  if (/^--- \S/.test(line) && /^\+\+\+ \S/.test(lines[i + 1] ?? "") && HUNK.test(lines[i + 2] ?? "")) return i;
  return null;
}

/**
 * One hunk from its header at `at`: as many old and new lines as the header says. Null when the
 * lines do not add up — then it was not a hunk, and nothing of it is coloured.
 */
function readHunk(lines, at, m, kinds) {
  let oldLeft = m[1] === undefined ? 1 : Number(m[1]);
  let newLeft = m[2] === undefined ? 1 : Number(m[2]);
  const marks = [[at, "hunk"]];
  let j = at + 1;

  while ((oldLeft > 0 || newLeft > 0) && j < lines.length) {
    const line = lines[j];
    const c = line[0];
    if (c === "\\") { marks.push([j++, "meta"]); continue; }          // `\ No newline at end of file`
    if ((c === " " || line === "") && oldLeft > 0 && newLeft > 0) { oldLeft--; newLeft--; marks.push([j++, "ctx"]); continue; }
    if (c === "-" && oldLeft > 0) { oldLeft--; marks.push([j++, "del"]); continue; }
    if (c === "+" && newLeft > 0) { newLeft--; marks.push([j++, "add"]); continue; }
    break;
  }

  // Output cut short (a tail, a stream still arriving) colours what there is of the last hunk;
  // a line that does not fit the counts means it was never a hunk.
  if ((oldLeft > 0 || newLeft > 0) && j < lines.length) return null;
  while (j < lines.length && lines[j].startsWith("\\")) marks.push([j++, "meta"]);
  for (const [k, kind] of marks) kinds[k] = kind;
  return j;
}

/**
 * The output as the inside of a `.tool-terminal`: escaped, with diff lines wrapped in spans. Every
 * character of the text is kept, newlines included, so copying the card still copies the output.
 *
 * @param {string} text
 * @param {(s: string) => string} escape
 */
export function highlightDiffs(text, escape) {
  if (!mayHoldDiff(text)) return escape(text);
  const lines = text.split("\n");
  const kinds = classifyDiffLines(lines);
  if (!kinds.some((k) => k !== null)) return escape(text);
  return lines
    .map((line, i) => (kinds[i] === null ? escape(line) : `<span class="tdiff tdiff-${kinds[i]}">${escape(line)}</span>`))
    .join("\n");
}
