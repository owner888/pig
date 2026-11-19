---
timeout: 300
expect:
  files:
    - path: src/Invoice.php
      contains: ['public function total(): int']
    - path: src/Stats.php
      contains: ['public static function sum(array $invoices): int']
    - path: run.php
      contains: ['Stats::sum(']
  check:
    - php -r 'exit((int) (sha1((string) shell_exec("php run.php")) !== "d99455d7ef2f2920c2e06205a91f29683b19beed"));'
    - php -r 'exit(preg_match("/->sum\(/", implode("", array_map("file_get_contents", [...glob("src/*.php"), "run.php"]))));'
---
Rename the method `Invoice::sum()` to `Invoice::total()`, and update every caller. `php run.php` must print exactly what it prints now.
