#!/bin/sh
#
# pig installer — https://github.com/owner888/pig
#
#   curl -fsSL https://pigagent.dev/install.sh | sh
#
# What this does and, as importantly, what it does not:
#
#   - It checks PHP and its extensions BEFORE touching anything, and names every missing one at
#     once. If PHP is missing on macOS with Homebrew, it offers to run `brew install php`.
#   - It automatically bootstraps and installs Composer via pure PHP if not found on your PATH.
#   - It runs `composer global require`, which is the one and only way pig is distributed. There is
#     no phar and no second artifact here on purpose: pig's version comes from the git tag through
#     Composer, and a second install route would be a second answer to "which version am I running"
#     — plus an update command that does not work for half the people who see it.
#   - It fixes the one thing Composer says nothing about: its global bin directory is not on
#     anybody's PATH. This is why the installer exists at all; `composer global require` on its own
#     ends in `command not found`.
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

INITIAL_PATH="${PATH:-}"
INSTALLED_COMPOSER_DIR=""

pig_installer_main() {
  set -eu

  INITIAL_PATH="${PATH:-}"

  printf '\n  \033[1mpig\033[0m \033[2m— pi, ported to PHP\033[0m\n\n'

  if ! run_preflight_checks; then
    exit 1
  fi

  install_pig_package

  bin_dir=$(composer_bin_dir)

  # Sync bundled extensions (pig-antigravity, pig-web-search, pig-computer, etc.) to user directory
  if [ -n "$bin_dir" ] && [ -x "$bin_dir/$PIG_CMD" ]; then
    "$bin_dir/$PIG_CMD" update --extensions >/dev/null 2>&1 || true
  fi

  missing_paths=$(collect_missing_path_dirs "$bin_dir")

  if installed_pig_is_first_on_path "$bin_dir" && [ -z "$missing_paths" ]; then
    printf '\npig %s is installed. Run it with: %s\n' "$(installed_version "$bin_dir")" "$PIG_CMD"
  else
    print_not_on_path_message "$bin_dir" "$missing_paths"
  fi

  printf '\nSet a key with ANTHROPIC_API_KEY, or run `%s` and use /login.\n' "$PIG_CMD"
  printf 'Remove it later with: composer global remove %s\n' "$PIG_PACKAGE"

  prompt_start_pig "$bin_dir"
}

# ---- before anything is touched ---------------------------------------------------------

# Every problem at once, not the first one. Somebody without PHP probably also lacks Composer, and
# finding that out one `curl | sh` at a time is the thing this is meant to spare them.
run_preflight_checks() {
  status=0

  if ! check_php_runtime; then
    status=1
  fi

  if [ "$status" -eq 0 ]; then
    if ! ensure_composer_installed; then
      status=1
    fi
  fi

  if [ "$status" -ne 0 ]; then
    printf '\nNothing was installed.\n'
  fi

  return "$status"
}

check_php_runtime() {
  if command -v php >/dev/null 2>&1; then
    if php -r 'exit(PHP_VERSION_ID >= '"$PIG_MIN_PHP_ID"' ? 0 : 1);' 2>/dev/null; then
      missing=$(missing_php_extensions)
      if [ -z "$missing" ]; then
        return 0
      fi
      printf '  error: PHP is missing:%s\n' "$missing"
      printf '         ext-pcntl is the one people are missing; without it the terminal UI\n'
      printf '         cannot notice a window resize and refuses to start.\n'
      return 1
    else
      printf '  error: pig needs PHP %s or newer. Found %s.\n' \
        "$PIG_MIN_PHP" "$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo 'an unknown version')"
    fi
  else
    printf '  error: PHP %s or newer is required, and no php was found on your PATH.\n' "$PIG_MIN_PHP"
  fi

  if offer_install_php_macos; then
    if command -v php >/dev/null 2>&1 && php -r 'exit(PHP_VERSION_ID >= '"$PIG_MIN_PHP_ID"' ? 0 : 1);' 2>/dev/null; then
      missing=$(missing_php_extensions)
      if [ -z "$missing" ]; then
        return 0
      fi
      printf '  error: PHP is missing:%s\n' "$missing"
      return 1
    fi
  else
    print_php_install_guidance
  fi

  return 1
}

offer_install_php_macos() {
  [ "$(uname -s 2>/dev/null || true)" = "Darwin" ] || return 1
  command -v brew >/dev/null 2>&1 || return 1
  have_tty || return 1

  exec 3<>/dev/tty
  printf '\n  Detected Homebrew on macOS. Would you like to run `brew install php` now? [y/N] ' >&3
  if ! IFS= read -r answer <&3; then
    answer=
  fi
  exec 3>&-

  case "$answer" in
    y|Y|yes|YES)
      printf '\n  Installing PHP with Homebrew...\n\n'
      if brew install php; then
        for brew_bin in /opt/homebrew/bin /usr/local/bin; do
          if [ -x "$brew_bin/php" ] && [ -d "$brew_bin" ]; then
            case ":$PATH:" in
              *":$brew_bin:"*) ;;
              *) export PATH="$brew_bin:$PATH" ;;
            esac
            break
          fi
        done
        return 0
      fi
      ;;
  esac

  return 1
}

print_php_install_guidance() {
  os=$(uname -s 2>/dev/null || echo "Unknown")
  case "$os" in
    Darwin)
      if command -v brew >/dev/null 2>&1; then
        printf '         Install PHP %s with: brew install php\n' "$PIG_MIN_PHP"
      else
        printf '         Install Homebrew (https://brew.sh), then run: brew install php\n'
      fi
      ;;
    Linux)
      distro=""
      if [ -f /etc/os-release ]; then
        # shellcheck disable=SC1091
        . /etc/os-release 2>/dev/null || true
        distro="${ID:-}"
      fi
      case "$distro" in
        ubuntu|debian)
          printf '         Install PHP %s on Ubuntu/Debian with:\n' "$PIG_MIN_PHP"
          printf '           sudo apt update && sudo apt install -y php-cli php-mbstring php-curl\n'
          ;;
        fedora|rhel|centos)
          printf '         Install PHP %s on Fedora/RHEL with:\n' "$PIG_MIN_PHP"
          printf '           sudo dnf install -y php-cli php-mbstring\n'
          ;;
        arch|manjaro)
          printf '         Install PHP %s on Arch Linux with:\n' "$PIG_MIN_PHP"
          printf '           sudo pacman -S php\n'
          ;;
        alpine)
          printf '         Install PHP %s on Alpine with:\n' "$PIG_MIN_PHP"
          printf '           apk add php83 php83-mbstring php83-pcntl php83-openssl php83-curl\n'
          ;;
        *)
          printf '         Install PHP %s or newer from your package manager or https://www.php.net/manual/en/install.php\n' "$PIG_MIN_PHP"
          ;;
      esac
      ;;
    *)
      printf '         Install PHP %s or newer from https://www.php.net/manual/en/install.php\n' "$PIG_MIN_PHP"
      ;;
  esac
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

# Ensure Composer is present; if not, automatically bootstrap and install it via pure PHP.
ensure_composer_installed() {
  if command -v composer >/dev/null 2>&1; then
    return 0
  fi

  printf '  composer is required but not found on your PATH.\n'

  composer_dir="/usr/local/bin"
  if [ ! -d "$composer_dir" ] || [ ! -w "$composer_dir" ]; then
    composer_dir="$HOME/.local/bin"
  fi

  mkdir -p "$composer_dir" 2>/dev/null || true
  if [ ! -d "$composer_dir" ] || [ ! -w "$composer_dir" ]; then
    composer_dir="$HOME/bin"
    mkdir -p "$composer_dir" 2>/dev/null || true
  fi

  if [ ! -d "$composer_dir" ] || [ ! -w "$composer_dir" ]; then
    printf '  error: cannot find a writable directory to install Composer.\n'
    printf '         Please install Composer manually: https://getcomposer.org/download/\n'
    return 1
  fi

  printf '  Installing Composer automatically to %s...\n\n' "$composer_dir"

  downloaded=0
  temp_installer=$(mktemp 2>/dev/null || echo "/tmp/composer-setup-$$.php")
  if php -r "copy('https://getcomposer.org/installer', '$temp_installer');" 2>/dev/null; then
    if php "$temp_installer" --quiet --install-dir="$composer_dir" --filename=composer 2>/dev/null; then
      downloaded=1
    fi
    rm -f "$temp_installer"
  fi

  # Fallback: direct download of composer.phar if installer failed
  if [ "$downloaded" -eq 0 ]; then
    if php -r "copy('https://getcomposer.org/composer.phar', '$composer_dir/composer');" 2>/dev/null; then
      downloaded=1
    fi
  fi

  if [ "$downloaded" -eq 1 ]; then
    chmod +x "$composer_dir/composer" 2>/dev/null || true
    export PATH="$composer_dir:$PATH"
    if command -v composer >/dev/null 2>&1; then
      INSTALLED_COMPOSER_DIR="$composer_dir"
      printf '  Composer installed successfully.\n\n'
      return 0
    fi
  fi

  printf '  error: automatic Composer installation failed.\n'
  printf '         Please install Composer manually: https://getcomposer.org/download/\n'
  return 1
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

collect_missing_path_dirs() {
  bin_dir="$1"
  result=""
  for d in "$bin_dir" "${INSTALLED_COMPOSER_DIR:-}"; do
    [ -n "$d" ] || continue
    case ":${INITIAL_PATH:-$PATH}:" in
      *":$d:"*) ;;
      *)
        case " $result " in
          *" $d "*) ;;
          *) result="${result:+$result:}$d" ;;
        esac
        ;;
    esac
  done
  printf '%s' "$result"
}

# ---- the PATH, which is the reason this script exists -----------------------------------

print_not_on_path_message() {
  bin_dir="$1"
  missing_paths="${2:-$1}"

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

  prompt_add_path_to_profile "$missing_paths" || true

  printf '\nRestart your shell, or run:\n\n  %s\n\n' "$(path_update_command "$missing_paths")"
  printf 'Then run: %s\n' "$PIG_CMD"
}

# Asked rather than done. This writes to a file the person did not ask us to touch, and a `curl | sh`
# that silently edits a shell profile is exactly the reason some people refuse `curl | sh`.
prompt_add_path_to_profile() {
  dirs="$1"
  have_tty || return 1

  config_file=$(shell_config_file)
  command=$(path_update_command "$dirs")

  if config_file_mentions_path "$config_file" "$command"; then
    printf 'A PATH entry for %s is already in %s.\n' "$dirs" "$config_file"
    return 0
  fi

  exec 3<>/dev/tty
  printf '\nAdd %s to your PATH in %s? [Y/n] ' "$dirs" "$config_file" >&3
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
  dirs="$1"
  case "$(basename "${SHELL:-sh}")" in
    fish)
      cmd=""
      old_ifs=$IFS
      IFS=:
      for d in $dirs; do
        [ -n "$d" ] || continue
        cmd="${cmd:+${cmd} && }fish_add_path \"$d\""
      done
      IFS=$old_ifs
      printf '%s' "$cmd"
      ;;
    *)
      printf 'export PATH="%s:$PATH"' "$dirs"
      ;;
  esac
}

# Whole-line match, so running the installer twice does not append the line twice.
config_file_mentions_path() {
  config_file="$1"
  command="$2"

  [ -f "$config_file" ] || return 1
  grep -Fxq "$command" "$config_file"
}

# The installer runs as a child process and cannot change the calling shell's
# PATH in memory, so offer to start the installed pig by its full path. This makes the
# first run work seamlessly even when the shell still needs a restart to find pig.
prompt_start_pig() {
  bin_dir="$1"
  start_pig_path="$bin_dir/$PIG_CMD"
  [ -n "$start_pig_path" ] && [ -x "$start_pig_path" ] || return 0
  have_tty || return 0

  exec 3<>/dev/tty
  printf '\nStart pig now? [Y/n] ' >&3
  if ! IFS= read -r answer <&3; then
    answer=
  fi
  exec 3>&-

  case "$answer" in
    n|N|no|NO) return 0 ;;
  esac

  printf '\n'
  # Piped to `sh`, standard input is the rest of this script, and pig started on it is in print
  # mode (stdin not a terminal) with nothing to say: it exits at once and the Enter looks ignored.
  exec "$start_pig_path" </dev/tty >/dev/tty 2>/dev/tty
}

# Piped to `sh`, standard input is the script itself, so a prompt has to come from the terminal
# directly — and there may not be one.
have_tty() {
  ( : <>/dev/tty ) 2>/dev/null
}

pig_installer_main "$@"
