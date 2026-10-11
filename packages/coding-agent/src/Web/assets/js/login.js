/**
 * The login page (`WebLogin`): the first password, with the one-time setup code the terminal
 * printed, or the password. Self-contained — the page's own modules are behind the login.
 */

const zh = (() => {
  try { return (localStorage.getItem("pig_web_locale") || navigator.language || "").startsWith("zh"); } catch { return true; }
})();
const T = zh ? {
  setupHint: "第一次使用：输入启动 pig web 时终端里显示的设置码，再设一个密码（至少 8 位）。",
  loginHint: "输入密码登录。",
  code: "设置码，如 ABCD-2345",
  password: "密码",
  newPassword: "新密码（至少 8 位）",
  confirm: "再输一次",
  setup: "设置密码并进入",
  login: "登录",
  mismatch: "两次输入的密码不一样。",
  unreachable: "无法连接 pig。",
  already_set: "密码已经设置过了。请直接登录，或用 pig web password 修改。",
  throttled: "尝试次数太多，请一分钟后再试。",
  wrong_code: "设置码不对。它显示在启动 pig web 的终端里。",
  weak: "密码至少要 8 位。",
  no_password: "还没有设置密码，请先用设置码设置。",
  wrong_password: "密码错误。",
} : {
  setupHint: "First time: enter the setup code the terminal showed when pig web started, then choose a password (8 characters or more).",
  loginHint: "Enter your password.",
  code: "Setup code, e.g. ABCD-2345",
  password: "Password",
  newPassword: "New password (8 characters or more)",
  confirm: "Once more",
  setup: "Set password and continue",
  login: "Log in",
  mismatch: "The two passwords differ.",
  unreachable: "Could not reach pig.",
};

try {
  const theme = localStorage.getItem("pig_theme");
  if (theme) document.documentElement.setAttribute("data-theme", theme);
} catch {}

const $ = (id) => document.getElementById(id);
const form = $("login-form"), hint = $("login-hint"), code = $("login-code"), password = $("login-password");
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
code.hidden = !setup;
code.required = setup;
code.placeholder = T.code;
confirmField.hidden = !setup;
confirmField.required = setup;
confirmField.placeholder = T.confirm;
password.placeholder = setup ? T.newPassword : T.password;
password.autocomplete = setup ? "new-password" : "current-password";
submit.textContent = setup ? T.setup : T.login;
(setup ? code : password).focus();

form.addEventListener("submit", async (e) => {
  e.preventDefault();
  if (setup && password.value !== confirmField.value) { say(T.mismatch); return; }
  submit.disabled = true;
  say("");
  try {
    const res = await fetch(setup ? "/api/login/setup" : "/api/login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(setup ? { code: code.value, password: password.value } : { password: password.value }),
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
