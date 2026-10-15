# Web UI: sizes and how a new piece is built

The developer's rule: a new page, dialog or control in `pig web` looks like the ones already
there — no size invented for the occasion. The scheduled-task pages were first drawn at 15–16px
text, 40–44px buttons and 10–14px corners, against a page built on 13px, ~32px and 6px, and read as
clumsy next to it. So the sizes are tokens, taken from what the page already used most, and every
new rule uses them.

## The tokens

Defined once, at the top of `assets/css/style.css` (`:root`). Colours stay the theme's `--color-*`.

| Token | Value | For |
|---|---|---|
| `--text-xs` | 11px | badges, hints, timestamps |
| `--text-sm` | 12px | secondary lines, meta, a field's label |
| `--text-base` | 13px | body, buttons, menu items, table cells — **the default** |
| `--text-md` | 14px | text the person types; a card's or a section's title |
| `--text-lg` | 16px | a dialog's title |
| `--text-xl` | 22px | a page's title (`--font-serif`) |
| `--text-prose` | 15px | the conversation's own text, read at length |
| `--text-touch` | 16px | a field on a phone — Safari zooms into anything smaller |
| `--control-sm` | 26px | chips, small icon buttons inside a row |
| `--control-md` | 32px | buttons, selects, toolbar icon buttons — **the default** |
| `--control-lg` | 36px | text fields |
| `--radius-sm` / `-md` / `-lg` / `-xl` | 4 / 6 / 8 / 12px | chips · buttons and fields · cards, panels, menus · dialogs |
| `--radius-full` | 999px | pills, switches, a bar's ends |
| `--space-1` … `--space-6` | 4 / 8 / 12 / 16 / 24 / 32px | gaps and padding |
| `--icon-sm` / `--icon-md` | 14 / 16px | an icon in a control · a toolbar icon, or a glyph drawn as text (×, +, ▸) |
| `--icon-hero` | 48px | the one large glyph of an empty page |

## Rules

- **A font size, a control's height or a corner is a token, never a raw px value.** One filled
  (primary) button per view; the rest are quiet (`--color-hover` ground or none).
- **A new size is a new token, added here and in `:root`, with the developer's yes** — not a
  one-off value in a rule.
- **The frame is the same on every page.** The header (`.top-header`) and the tab bar are never
  hidden, moved or restyled by a page; a page (`#page-view`, `#main-view.page-mode`) takes the
  conversation's place — the chat, the banner, the prompt — and nothing else.
- **Inline SVG icons are sized by CSS** (`width: var(--icon-sm)`), whatever their `width`
  attribute says.

`WebStyleTokensTest` holds the line: a `font-size` or `border-radius` in px anywhere in the
stylesheet, the scripts' inline styles or the HTML — outside `:root` — fails it.
