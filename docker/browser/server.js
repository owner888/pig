import fastify from "fastify";
import { connect } from "puppeteer-real-browser";
import fs from "fs";
import path from "path";

const PORT = parseInt(process.env.PORT || process.env.BRIDGE_PORT || "9523", 10);
const HOST = process.env.HOST || process.env.BRIDGE_BIND || "0.0.0.0";
const HEADLESS = process.env.HEADLESS === "true" ? "auto" : false;
const DATA_DIR = process.env.DATA_DIR || "/data";

let browserInstance = null;
let primaryPage = null;
let pageBusy = false;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function acquirePage() {
  while (pageBusy) {
    await sleep(150);
  }
  pageBusy = true;
  const { page, browser } = await getBrowserAndPage();
  return { page, browser, release: () => { pageBusy = false; } };
}

/**
 * 启动真实防爬反检测浏览器
 */
async function launchRealBrowser() {
  const profileDir = path.join(DATA_DIR, "browser_profile");
  if (!fs.existsSync(profileDir)) {
    fs.mkdirSync(profileDir, { recursive: true });
  }

  const args = [
    "--start-maximized",
    "--no-sandbox",
    "--disable-setuid-sandbox",
    "--disable-blink-features=AutomationControlled",
    "--disable-infobars",
    `--user-data-dir=${profileDir}`,
    "--window-size=1920,1080",
  ];

  console.log(`[Browser] 正在启动真实反爬浏览器 (headless: ${HEADLESS}, profile: ${profileDir})...`);
  const { page, browser } = await connect({
    headless: HEADLESS,
    args,
    connectOption: {
      defaultViewport: { width: 1920, height: 1080 },
    },
    customConfig: {
      handleSIGINT: false,
    },
    turnstile: true,
  });

  browserInstance = browser;
  primaryPage = page;

  browser.on("disconnected", () => {
    console.warn("[Browser] 浏览器已断开连接");
    browserInstance = null;
    primaryPage = null;
    pageBusy = false;
  });

  // 启动时自动扫描并导入 DATA_DIR 下的所有 Cookie 文件
  await loadAllCookies(page);

  return { browser, page };
}

async function getBrowserAndPage() {
  if (browserInstance && browserInstance.connected && primaryPage && !primaryPage.isClosed()) {
    return { browser: browserInstance, page: primaryPage };
  }
  return await launchRealBrowser();
}

/**
 * 扫描并载入所有 Cookie 文件（支持 cookies.json 以及 *.cookies.json）
 */
async function loadAllCookies(page) {
  if (!fs.existsSync(DATA_DIR)) return;

  try {
    const files = fs.readdirSync(DATA_DIR);
    for (const f of files) {
      if (f === "cookies.json" || f.endsWith(".cookies.json")) {
        const fullPath = path.join(DATA_DIR, f);
        try {
          const raw = fs.readFileSync(fullPath, "utf-8");
          const cookies = JSON.parse(raw);
          if (Array.isArray(cookies) && cookies.length > 0) {
            console.log(`[Cookies] 自动导入 ${f} (${cookies.length} 条)...`);
            await injectCookies(page, cookies);
          }
        } catch (err) {
          console.warn(`[Cookies] 解析 ${f} 失败:`, err.message);
        }
      }
    }
  } catch (err) {
    console.warn(`[Cookies] 扫描目录失败:`, err.message);
  }
}

/**
 * 为指定访问 URL 按需动态补全注入对应域名的 Cookie
 */
async function ensureCookiesForUrl(page, targetUrl) {
  if (!fs.existsSync(DATA_DIR) || !targetUrl) return;

  try {
    const urlObj = new URL(targetUrl);
    const host = urlObj.hostname.toLowerCase();

    // 寻找匹配的独立文件，如 jd.com.cookies.json
    const files = fs.readdirSync(DATA_DIR);
    for (const f of files) {
      if (f.endsWith(".cookies.json")) {
        const prefixDomain = f.slice(0, -".cookies.json".length).toLowerCase();
        if (host === prefixDomain || host.endsWith("." + prefixDomain)) {
          const fullPath = path.join(DATA_DIR, f);
          try {
            const raw = fs.readFileSync(fullPath, "utf-8");
            const cookies = JSON.parse(raw);
            if (Array.isArray(cookies) && cookies.length > 0) {
              await injectCookies(page, cookies);
            }
          } catch {}
        }
      }
    }
  } catch {}
}

/**
 * 注入 Cookie 数组（支持 standard format 与 EditThisCookie 导出格式）
 */
async function injectCookies(page, cookies) {
  const formatted = [];
  for (const c of cookies) {
    if (!c.name || !c.value) continue;
    const cookie = {
      name: String(c.name),
      value: String(c.value),
      domain: c.domain ? String(c.domain).replace(/^\./, "") : undefined,
      path: c.path || "/",
      httpOnly: Boolean(c.httpOnly),
      secure: Boolean(c.secure),
    };
    if (c.sameSite) {
      const s = String(c.sameSite).toLowerCase();
      if (s === "lax" || s === "strict" || s === "none") {
        cookie.sameSite = s.charAt(0).toUpperCase() + s.slice(1);
      }
    }
    if (typeof c.expirationDate === "number") {
      cookie.expires = c.expirationDate;
    }
    formatted.push(cookie);
  }

  if (formatted.length > 0) {
    await page.setCookie(...formatted);
  }
}

/**
 * 拟真平滑移动鼠标
 */
async function humanMouseMove(page, targetX, targetY) {
  // 当前鼠标位置模拟，多步平滑贝塞尔滑动
  const steps = Math.floor(Math.random() * 8) + 12;
  await page.mouse.move(targetX, targetY, { steps });
}

/**
 * 提取页面纯文本
 */
async function extractCleanText(page) {
  try {
    return await page.evaluate(() => {
      const clone = document.body ? document.body.cloneNode(true) : document.documentElement.cloneNode(true);
      const toRemove = clone.querySelectorAll("script, style, noscript, svg, canvas, iframe");
      toRemove.forEach((el) => el.remove());
      return (clone.innerText || clone.textContent || "")
        .replace(/\r\n/g, "\n")
        .replace(/\n{3,}/g, "\n\n")
        .trim();
    });
  } catch {
    return "";
  }
}

const app = fastify({ logger: false, bodyLimit: 50 * 1024 * 1024 });

app.get("/health", async () => {
  const isHealthy = browserInstance && browserInstance.connected;
  return {
    status: "ok",
    browser: isHealthy ? "connected" : "ready",
    viewport: { width: 1920, height: 1080 },
  };
});

/**
 * 导航到指定 URL
 */
app.post("/navigate", async (req, reply) => {
  const { url, timeout = 45000, waitUntil = "domcontentloaded" } = req.body || {};
  if (!url) return reply.status(400).send({ error: "url is required" });

  const { page, release } = await acquirePage();
  try {
    await page.bringToFront();
    // 导航前按需热注入该域名的最新 Cookie
    await ensureCookiesForUrl(page, url);

    await page.goto(url, { waitUntil, timeout });
    await sleep(1500);

    const title = await page.title();
    const currentUrl = page.url();
    return { success: true, title, url: currentUrl };
  } catch (err) {
    return reply.status(500).send({ error: err.message });
  } finally {
    release();
  }
});

/**
 * Computer Use: 截屏 (Screenshot)
 */
app.post("/screenshot", async (req, reply) => {
  const { fullPage = false, format = "png", quality = 80 } = req.body || {};
  const { page, release } = await acquirePage();
  try {
    await page.bringToFront();
    const options = {
      type: format === "jpeg" || format === "jpg" ? "jpeg" : "png",
      fullPage: Boolean(fullPage),
      encoding: "base64",
    };
    if (options.type === "jpeg") {
      options.quality = quality;
    }

    const base64 = await page.screenshot(options);
    const title = await page.title();
    const url = page.url();

    return {
      success: true,
      format: options.type,
      image: base64,
      title,
      url,
    };
  } catch (err) {
    return reply.status(500).send({ error: err.message });
  } finally {
    release();
  }
});

/**
 * Computer Use: 模拟鼠标移动与点击 (Click)
 */
app.post("/click", async (req, reply) => {
  const { x, y, selector, button = "left", clickCount = 1 } = req.body || {};
  const { page, release } = await acquirePage();
  try {
    await page.bringToFront();

    let targetX = x;
    let targetY = y;

    if (selector) {
      const el = await page.waitForSelector(selector, { timeout: 8000 });
      const box = await el.boundingBox();
      if (!box) throw new Error(`Element ${selector} has no bounding box`);
      targetX = box.x + box.width / 2;
      targetY = box.y + box.height / 2;
    }

    if (typeof targetX !== "number" || typeof targetY !== "number") {
      return reply.status(400).send({ error: "Coordinate [x, y] or valid selector is required" });
    }

    await humanMouseMove(page, targetX, targetY);
    await sleep(50 + Math.floor(Math.random() * 50));
    await page.mouse.click(targetX, targetY, {
      button: button === "right" ? "right" : (button === "middle" ? "middle" : "left"),
      clickCount: Math.max(1, clickCount),
      delay: 40 + Math.floor(Math.random() * 30),
    });

    return { success: true, clicked: { x: targetX, y: targetY } };
  } catch (err) {
    return reply.status(500).send({ error: err.message });
  } finally {
    release();
  }
});

/**
 * Computer Use: 模拟鼠标移动 (Move)
 */
app.post("/mouse_move", async (req, reply) => {
  const { x, y } = req.body || {};
  if (typeof x !== "number" || typeof y !== "number") {
    return reply.status(400).send({ error: "x and y are required numbers" });
  }

  const { page, release } = await acquirePage();
  try {
    await page.bringToFront();
    await humanMouseMove(page, x, y);
    return { success: true, position: { x, y } };
  } catch (err) {
    return reply.status(500).send({ error: err.message });
  } finally {
    release();
  }
});

/**
 * Computer Use: 键盘拟真打字 (Type)
 */
app.post("/type", async (req, reply) => {
  const { text, selector, delay = 50 } = req.body || {};
  if (typeof text !== "string") {
    return reply.status(400).send({ error: "text is required" });
  }

  const { page, release } = await acquirePage();
  try {
    await page.bringToFront();
    if (selector) {
      await page.focus(selector);
    }
    await page.keyboard.type(text, { delay });
    return { success: true, typedLength: text.length };
  } catch (err) {
    return reply.status(500).send({ error: err.message });
  } finally {
    release();
  }
});

/**
 * Computer Use: 按键触发 (Press/Key: Enter, Tab, Backspace, etc.)
 */
app.post("/press", async (req, reply) => {
  const { key } = req.body || {};
  if (!key) return reply.status(400).send({ error: "key is required (e.g. Enter, Escape, Backspace)" });

  const { page, release } = await acquirePage();
  try {
    await page.bringToFront();
    await page.keyboard.press(key);
    return { success: true, pressed: key };
  } catch (err) {
    return reply.status(500).send({ error: err.message });
  } finally {
    release();
  }
});

/**
 * Computer Use: 滚屏 (Scroll)
 */
app.post("/scroll", async (req, reply) => {
  const { deltaY = 300, deltaX = 0 } = req.body || {};
  const { page, release } = await acquirePage();
  try {
    await page.bringToFront();
    await page.mouse.wheel({ deltaX, deltaY });
    await sleep(300);
    return { success: true, scrolled: { deltaX, deltaY } };
  } catch (err) {
    return reply.status(500).send({ error: err.message });
  } finally {
    release();
  }
});

/**
 * 提取页面文本与结构
 */
app.get("/text", async (req, reply) => {
  const { page, release } = await acquirePage();
  try {
    const title = await page.title();
    const text = await extractCleanText(page);
    const url = page.url();
    return { title, url, text };
  } catch (err) {
    return reply.status(500).send({ error: err.message });
  } finally {
    release();
  }
});

/**
 * 动态导入 Cookies
 */
app.post("/cookies", async (req, reply) => {
  const { cookies } = req.body || {};
  if (!Array.isArray(cookies)) {
    return reply.status(400).send({ error: "cookies array is required" });
  }

  const { page, release } = await acquirePage();
  try {
    await injectCookies(page, cookies);
    return { success: true, count: cookies.length };
  } catch (err) {
    return reply.status(500).send({ error: err.message });
  } finally {
    release();
  }
});

async function start() {
  try {
    await app.listen({ port: PORT, host: HOST });
    console.log(`🚀 [Pig Browser Service] Ready at http://${HOST}:${PORT}`);
    getBrowserAndPage().catch((err) => console.warn("[Warmup Warning]:", err.message));
  } catch (err) {
    console.error("Startup failed:", err);
    process.exit(1);
  }
}

start();
