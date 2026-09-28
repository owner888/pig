#!/bin/sh
#
# pig installer — https://github.com/owner888/pig
#
#   curl -fsSL https://pigagent.dev/install.sh | sh
#
# What this does and, as importantly, what it does not:
#
#   - It checks PHP and its extensions BEFORE touching anything, and names every missing one at
#     once. Without that a missing `ext-pcntl` surfaces half way through a Composer install as a
#     platform error that does not say what to install.
#   - It runs `composer global require`, which is the one and only way pig is distributed. There is
#     no phar and no second artifact here on purpose: pig's version comes from the git tag through
#     Composer, and a second install route would be a second answer to "which version am I running"
#     — plus an update command that does not work for half the people who see it.
#   - It fixes the one thing Composer says nothing about: its global bin directory is not on
#     anybody's PATH. This is why the installer exists at all; `composer global require` on its own
#     ends in `command not found`.
#   - It installs nothing else. If PHP is missing it says so and stops, rather than reaching for a
#     package manager on your behalf.
#
# Every function is defined before the last line calls one. A truncated download therefore defines
# some functions and does nothing at all, instead of performing half an install — which is the whole
# reason `curl | sh` scripts are written this way.

PIG_PACKAGE="${PIG_PACKAGE:-pigagent/pig}"
PIG_CMD="pig"
# Mirrors `require` in composer.json. `InstallScriptTest` asserts the two agree, because a
# preflight that checks for the wrong extensions is worse than no preflight.
PIG_MIN_PHP_ID=80300
PIG_MIN_PHP="8.3"
PIG_EXTENSIONS="json mbstring openssl pcntl pcre"
readonly PIG_PACKAGE PIG_CMD PIG_MIN_PHP_ID PIG_MIN_PHP PIG_EXTENSIONS

pig_installer_main() {
  set -eu

  printf '\n  \033[1mpig\033[0m \033[2m— pi, ported to PHP\033[0m\n\n'

  if ! run_preflight_checks; then
    exit 1
  fi

  install_pig_package

  bin_dir=$(composer_bin_dir)

  if installed_pig_is_first_on_path "$bin_dir"; then
    printf '\npig %s is installed. Run it with: %s\n' "$(installed_version "$bin_dir")" "$PIG_CMD"
  else
    print_not_on_path_message "$bin_dir"
  fi

  printf '\nSet a key with ANTHROPIC_API_KEY, or run `%s` and use /login.\n' "$PIG_CMD"
  printf 'Remove it later with: composer global remove %s\n' "$PIG_PACKAGE"
}

# ---- before anything is touched ---------------------------------------------------------

# Every problem at once, not the first one. Somebody without PHP probably also lacks Composer, and
# finding that out one `curl | sh` at a time is the thing this is meant to spare them.
run_preflight_checks() {
  status=0

  if command -v php >/dev/null 2>&1; then
    if ! php -r 'exit(PHP_VERSION_ID >= '"$PIG_MIN_PHP_ID"' ? 0 : 1);' 2>/dev/null; then
      printf '  error: pig needs PHP %s or newer. Found %s.\n' \
        "$PIG_MIN_PHP" "$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo 'an unknown version')"
      status=1
    fi

    missing=$(missing_php_extensions)
    if [ -n "$missing" ]; then
      printf '  error: PHP is missing:%s\n' "$missing"
      printf '         ext-pcntl is the one people are missing; without it the terminal UI\n'
      printf '         cannot notice a window resize and refuses to start.\n'
      status=1
    fi
  else
    printf '  error: PHP %s or newer is required, and no php was found on your PATH.\n' "$PIG_MIN_PHP"
    status=1
  fi

  if ! command -v composer >/dev/null 2>&1; then
    printf '  error: composer is required. See https://getcomposer.org/download/\n'
    status=1
  fi

  if [ "$status" -ne 0 ]; then
    printf '\nNothing was installed.\n'
  fi

  return "$status"
}

# The extensions pig declares, minus the ones this PHP has. Asked of PHP itself rather than parsed
# out of `php -m`, so a build that compiled one in statically answers the same as one that did not.
missing_php_extensions() {
  php -r '
    $missing = "";
    foreach (explode(" ", $argv[1]) as $extension) {
      if ($extension !== "" && !extension_loaded($extension)) {
        $missing .= " ext-" . $extension;
      }
    }
    echo $missing;
  ' "$PIG_EXTENSIONS" 2>/dev/null
}

# ---- the install itself -----------------------------------------------------------------

install_pig_package() {
  printf '  Installing %s with Composer...\n\n' "$PIG_PACKAGE"

  if have_tty; then
    composer global require "$PIG_PACKAGE"
  else
    composer global require --no-interaction "$PIG_PACKAGE"
  fi
}

composer_bin_dir() {
  composer global config bin-dir --absolute 2>/dev/null | tail -n 1
}

installed_version() {
  bin_dir="$1"
  "$bin_dir/$PIG_CMD" --version 2>/dev/null | awk '{ print $2 }'
}

# Not "does `pig` resolve" but "does it resolve to the one just installed". An older copy earlier on
# the PATH answers the first question and is not what anybody wants to be running.
installed_pig_is_first_on_path() {
  bin_dir="$1"
  [ -n "$bin_dir" ] || return 1
  [ -x "$bin_dir/$PIG_CMD" ] || return 1

  active=$(command -v "$PIG_CMD" 2>/dev/null) || return 1
  [ "$active" = "$bin_dir/$PIG_CMD" ]
}

# ---- the PATH, which is the reason this script exists -----------------------------------

print_not_on_path_message() {
  bin_dir="$1"

  printf '\npig is installed, but your shell cannot see it yet.\n'

  if [ -z "$bin_dir" ]; then
    printf "Composer did not say where it put the command. Find it with:\n\n"
    printf '  composer global config bin-dir --absolute\n\n'
    printf 'then add that directory to your PATH.\n'
    return
  fi

  active=$(command -v "$PIG_CMD" 2>/dev/null || true)
  if [ -n "$active" ]; then
    printf 'Your shell currently resolves %s to: %s\n' "$PIG_CMD" "$active"
  fi

  prompt_add_path_to_profile "$bin_dir" || true

  printf '\nRestart your shell, or run:\n\n  %s\n\n' "$(path_update_command "$bin_dir")"
  printf 'Then run: %s\n' "$PIG_CMD"
}

# Asked rather than done. This writes to a file the person did not ask us to touch, and a `curl | sh`
# that silently edits a shell profile is exactly the reason some people refuse `curl | sh`.
prompt_add_path_to_profile() {
  bin_dir="$1"
  have_tty || return 1

  config_file=$(shell_config_file)
  command=$(path_update_command "$bin_dir")

  if config_file_mentions_path "$config_file" "$command"; then
    printf 'A PATH entry for %s is already in %s.\n' "$bin_dir" "$config_file"
    return 0
  fi

  exec 3<>/dev/tty
  printf '\nAdd %s to your PATH in %s? [Y/n] ' "$bin_dir" "$config_file" >&3
  if ! IFS= read -r answer <&3; then
    answer=
  fi
  exec 3>&-

  case "$answer" in
    n|N|no|NO) return 1 ;;
    *) ;;
  esac

  mkdir -p "${config_file%/*}"
  touch "$config_file"
  printf '\n# pig\n%s\n' "$command" >> "$config_file"
  printf 'Added it to %s.\n' "$config_file"
}

shell_config_file() {
  case "$(basename "${SHELL:-sh}")" in
    fish) printf '%s/.config/fish/config.fish' "$HOME" ;;
    zsh) printf '%s/.zshrc' "${ZDOTDIR:-$HOME}" ;;
    bash)
      if [ -f "$HOME/.bashrc" ]; then
        printf '%s/.bashrc' "$HOME"
      else
        printf '%s/.profile' "$HOME"
      fi
      ;;
    *) printf '%s/.profile' "$HOME" ;;
  esac
}

path_update_command() {
  bin_dir="$1"
  case "$(basename "${SHELL:-sh}")" in
    fish) printf 'fish_add_path "%s"' "$bin_dir" ;;
    *) printf 'export PATH="%s:$PATH"' "$bin_dir" ;;
  esac
}

# Whole-line match, so running the installer twice does not append the line twice.
config_file_mentions_path() {
  config_file="$1"
  command="$2"

  [ -f "$config_file" ] || return 1
  grep -Fxq "$command" "$config_file"
}

# Piped to `sh`, standard input is the script itself, so a prompt has to come from the terminal
# directly — and there may not be one.
have_tty() {
  ( : <>/dev/tty ) 2>/dev/null
}

pig_installer_main "$@"
