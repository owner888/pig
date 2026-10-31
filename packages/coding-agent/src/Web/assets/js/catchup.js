/**
 * A page that was away does not replay what it missed: it jumps to the latest and loads the rest
 * as the reader scrolls up — what Telegram does for a device a month offline. Pig's own, not
 * upstream's (upstream has no browser page).
 *
 * Two ways a page fell behind, both measured with a run of 3,000 tool calls:
 * - **Hidden** (the laptop locked but awake, the tab behind another): every event was still
 *   drawn — 6,000 messages, 74,000 nodes, the page busy for 90 of the run's 94 seconds — for a
 *   screen nobody could see.
 * - **Kept open**: the same run in a visible tab grew the page without end, the newest blocks
 *   appended and nothing ever taken off.
 *
 * So a page hidden for longer than `AWAY_AFTER` keeps only what the next draw needs (`catchUp()`)
 * and marks the tab to load its last page when it is seen again, as a reconnect does; and a tab
 * that stays in view loads its last page again once `LIVE_CAP` messages were added to it live.
 * A short look elsewhere (switching apps for a few seconds) changes nothing.
 */

/** How long the page may be hidden before what arrives is no longer drawn. */
export const AWAY_AFTER = 30000;

/**
 * How many messages may be added to a tab live before it draws its last page afresh — on top of
 * the `FIRST_LOAD` it drew to begin with, so the page holds a few hundred at most.
 */
export const LIVE_CAP = 300;

/** The events that draw messages, and only those: the rest (running state, queue, dialogs, the
 *  notifications an extension sends) are handled as ever, hidden or not. */
export const DRAWS_MESSAGES = new Set([
  "message_start", "message_update", "message_end",
  "tool_execution_start", "tool_execution_update", "tool_execution_end",
]);

/** Whether a page hidden since `hiddenAt` (null: shown) has been away long enough to stop drawing. */
export function away(hiddenAt, now) {
  return hiddenAt !== null && hiddenAt !== undefined && now - hiddenAt > AWAY_AFTER;
}

/**
 * An event not drawn: what the next draw needs of it. The answer still streaming is the one thing
 * the last page does not have (`get_messages` holds ended messages only), so its text is kept —
 * a string, nothing drawn — and drawn whole when the tab is seen again.
 */
export function catchUp(tab, evt) {
  if (evt.type === "message_start") {
    tab.currentAssistantText = "";
    tab.currentThinkingText = "";
  } else if (evt.type === "message_update") {
    const d = evt.assistantMessageEvent || {};
    if (d.type === "text_delta") tab.currentAssistantText += d.delta || "";
    else if (d.type === "thinking_delta") tab.currentThinkingText += d.delta || "";
  } else if (evt.type === "tool_execution_start" || evt.type === "tool_execution_end") {
    tab.currentAssistantText = "";
  }
}

/** Whether a tab in view has had enough added live to draw its last page afresh. Not while the
 *  reader is scrolled up: that is the part being read, and it waits until they are back down. */
export function overCap(tab) {
  return (tab.liveMessages || 0) >= LIVE_CAP && !tab.userScrolledUp;
}
