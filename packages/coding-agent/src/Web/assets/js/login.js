/**
 * The login page (`WebLogin`): the first account while there is none, or logging in — a name and a
 * password either way. Self-contained — the page's own modules are behind the login.
 */

const zh = (() => {
  try { return (localStorage.getItem("pig_web_locale") || navigator.language || "").startsWith("zh"); } catch { return true; }
})();
const T = zh ? {
  setupHint: "第一次使用：建一个管理员账号（密码至少 8 位）。",
  loginHint: "输入账号和密码登录。",
  name: "账号",
  password: "密码",
  newPassword: "密码（至少 8 位）",
  confirm: "再输一次",
  setup: "创建账号并进入",
  login: "登录",
  mismatch: "两次输入的密码不一样。",
  unreachable: "无法连接 pig。",
  already_set: "已经有账号了，请直接登录。",
  throttled: "尝试次数太多，请一分钟后再试。",
  weak: "密码至少要 8 位。",
  no_password: "还没有账号，请先创建一个。",
  wrong_login: "账号或密码错误。",
  bad_name: "账号是 1 到 64 个字母、数字、点、短横线或下划线。",
} : {
  setupHint: "First time: make the admin account (a password of 8 characters or more).",
  loginHint: "Enter your name and password.",
  name: "Name",
  password: "Password",
  newPassword: "Password (8 characters or more)",
  confirm: "Once more",
  setup: "Make the account and continue",
  login: "Log in",
  mismatch: "The two passwords differ.",
  unreachable: "Could not reach pig.",
};

try {
  const theme = localStorage.getItem("pig_theme");
  if (theme) document.documentElement.setAttribute("data-theme", theme);
} catch {}

const $ = (id) => document.getElementById(id);
const form = $("login-form"), hint = $("login-hint"), name = $("login-name"), password = $("login-password");
const confirmField = $("login-confirm"), error = $("login-error"), submit = $("login-submit");

const say = (text) => { error.hidden = !text; error.textContent = text || ""; };

let setup = false;
try {
  const state = await (await fetch("/api/login/state", { cache: "no-store" })).json();
  if (state.loggedIn) location.replace("/");
  setup = state.setup === true;
} catch {
  say(T.unreachable);
}

hint.textContent = setup ? T.setupHint : T.loginHint;
name.placeholder = T.name;
name.autocomplete = setup ? "off" : "username";
confirmField.hidden = !setup;
confirmField.required = setup;
confirmField.placeholder = T.confirm;
password.placeholder = setup ? T.newPassword : T.password;
password.autocomplete = setup ? "new-password" : "current-password";
submit.textContent = setup ? T.setup : T.login;
name.focus();

form.addEventListener("submit", async (e) => {
  e.preventDefault();
  if (setup && password.value !== confirmField.value) { say(T.mismatch); return; }
  submit.disabled = true;
  say("");
  try {
    const res = await fetch(setup ? "/api/login/setup" : "/api/login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ name: name.value.trim(), password: password.value }),
    });
    const d = await res.json();
    if (d.ok) { location.replace("/"); return; }
    say((zh && T[d.reason]) || d.error);
    password.select();
  } catch {
    say(T.unreachable);
  } finally {
    submit.disabled = false;
  }
});
