#!/usr/bin/env python3
"""Rewrite a pig extension package for pig's Extensions\\ namespace (hooks and tool files merged).

Usage: python3 pig-rename-extensions.py <package-dir> [<package-dir> ...]

Edits .php and .md files in place (read-modify-write, so file modes are kept). Prints each file it changed.
"""
import os
import re
import sys

ALIAS = re.compile(r'\bHook(AgentStart|AgentEnd|AgentSettled|TurnStart|TurnEnd|MessageStart|MessageUpdate|MessageEnd|ToolExecutionStart|ToolExecutionUpdate|ToolExecutionEnd)\b')
RULES = [
    (re.compile(r'Pig\\CodingAgent\\Hooks\\HookApi\b'), lambda m: 'Pig\\CodingAgent\\Extensions\\ExtensionApi'),
    (re.compile(r'Pig\\CodingAgent\\CustomTools\\CustomToolApi\b'), lambda m: 'Pig\\CodingAgent\\Extensions\\ExtensionApi'),
    (re.compile(r'Pig\\CodingAgent\\Hooks\\'), lambda m: 'Pig\\CodingAgent\\Extensions\\'),
    (re.compile(r'Pig\\\\CodingAgent\\\\Hooks\\\\'), lambda m: 'Pig\\\\CodingAgent\\\\Extensions\\\\'),
    (re.compile(r'\bHookApi\b'), lambda m: 'ExtensionApi'),
    (re.compile(r'\bCustomToolApi\b'), lambda m: 'ExtensionApi'),
    (re.compile(r'\bHookRunner'), lambda m: 'ExtensionRunner'),
    (re.compile(r'\bHookContext'), lambda m: 'ExtensionContext'),
    (re.compile(r'\bHookUi\b'), lambda m: 'ExtensionUi'),
    (re.compile(r'\bHookEvent\b'), lambda m: 'ExtensionEvent'),
    (re.compile(r'\bHookState\b'), lambda m: 'ExtensionState'),
    (re.compile(r'\bHookedTool\b'), lambda m: 'ExtensionGuardedTool'),
    (re.compile(r'\bHookMessage\b'), lambda m: 'CustomMessage'),
    (re.compile(r'\bHookError\b'), lambda m: 'ExtensionError'),
    (re.compile(r'\bhookPath\b'), lambda m: 'extensionPath'),
    (re.compile(r'\$pi\b'), lambda m: '$pig'),
]


def dedupe_use(text: str) -> str:
    out, seen, header = [], set(), True
    for line in text.split('\n'):
        if header and re.match(r'^(final |abstract |readonly )*(class|interface|trait|enum) ', line):
            header = False
        if header and line.startswith('use ') and line.endswith(';'):
            if line in seen:
                continue
            seen.add(line)
        out.append(line)
    return '\n'.join(out)


def rewrite(path: str) -> bool:
    with open(path, encoding='utf-8', errors='surrogateescape') as f:
        before = f.read()
    after = ALIAS.sub(lambda m: 'Extension' + m.group(1), before)
    for rx, rep in RULES:
        after = rx.sub(rep, after)
    if path.endswith('.php'):
        after = dedupe_use(after)
    if after == before:
        return False
    with open(path, 'r+', encoding='utf-8', errors='surrogateescape') as f:
        f.seek(0)
        f.write(after)
        f.truncate()
    return True


def main(roots: list[str]) -> None:
    for root in roots:
        for dirpath, dirnames, filenames in os.walk(root):
            dirnames[:] = [d for d in dirnames if d not in ('vendor', 'node_modules', '.git')]
            for name in filenames:
                if name.endswith(('.php', '.md')):
                    full = os.path.join(dirpath, name)
                    if rewrite(full):
                        print(full)


if __name__ == '__main__':
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    main(sys.argv[1:])
