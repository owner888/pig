// What Enter does in the prompt (`assets/js/keyboard.js`), against stand-ins for a desktop, a
// phone with its keyboard down and up, Android's shrinking window, and an iPad with a hardware
// keyboard. Run by WebKeyboardTest with the assets folder as its argument.
import assert from "node:assert/strict";

const { onScreenKeyboard, watchKeyboard } = await import(`${process.argv[2]}/js/keyboard.js`);

function screen({ touch, width, innerHeight, viewport }) {
  return {
    innerWidth: width,
    innerHeight,
    matchMedia: () => ({ matches: touch }),
    visualViewport: viewport === null ? undefined : { height: viewport, scale: 1, addEventListener() {} },
  };
}

// A desktop: never, whatever the window.
assert.equal(onScreenKeyboard(screen({ touch: false, width: 1440, innerHeight: 900, viewport: 300 })), false);

// An iPhone: the layout viewport keeps its height, the visual one loses the keyboard's.
const phone = screen({ touch: true, width: 390, innerHeight: 760, viewport: 760 });
watchKeyboard(phone);
assert.equal(onScreenKeyboard(phone), false, "keyboard down");
phone.visualViewport.height = 420;
assert.equal(onScreenKeyboard(phone), true, "keyboard up: Enter is a new line");

// Android: the window shrinks with the keyboard, so the tallest seen is what counts.
const android = screen({ touch: true, width: 412, innerHeight: 860, viewport: 860 });
watchKeyboard(android);
android.innerHeight = android.visualViewport.height = 520;
assert.equal(onScreenKeyboard(android), true, "Android, keyboard up");

// An iPad with a Magic Keyboard: a shortcut bar, not a keyboard; Enter sends.
const ipad = screen({ touch: true, width: 1024, innerHeight: 1300, viewport: 1300 });
watchKeyboard(ipad);
ipad.visualViewport.height = 1245;
assert.equal(onScreenKeyboard(ipad), false, "iPad, hardware keyboard");

// A touch screen that cannot say: the glass keyboard is the one it has.
assert.equal(onScreenKeyboard(screen({ touch: true, width: 360, innerHeight: 640, viewport: null })), true);

console.log("Web keyboard: passed");
