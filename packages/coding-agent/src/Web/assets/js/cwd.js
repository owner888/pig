/**
 * The working-directory picker's path handling (`components/CwdPicker.js`), apart from the page so
 * it can be run under Node.
 */

/** A typed path as the directory to list and the start of the name being typed in it. */
export function splitTyped(text) {
  const value = String(text ?? "").trim();
  if (value === "~") return { dir: "~", prefix: "" };
  const at = value.lastIndexOf("/");
  if (at === -1) return { dir: "", prefix: value };
  return { dir: value.slice(0, at + 1), prefix: value.slice(at + 1) };
}

/** The subdirectories whose names start with `prefix`, ignoring case. */
export function matching(dirs, prefix) {
  const p = String(prefix ?? "").toLowerCase();
  return (dirs ?? []).filter((d) => d.toLowerCase().startsWith(p));
}

/** `name` inside `dir`. */
export function joinPath(dir, name) {
  return dir.endsWith("/") ? dir + name : `${dir}/${name}`;
}

/** The last part of a path, to name a folder by; `/` is itself. */
export function folderName(path) {
  return String(path ?? "").split("/").filter(Boolean).pop() || path || "~";
}
