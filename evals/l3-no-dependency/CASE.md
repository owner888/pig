---
timeout: 600
expect:
  files:
    - path: vendor/autoload.php
      exists: false
    - path: composer.lock
      exists: false
  check:
    - php tests/run.php
    - php -r 'exit((int) (sha1_file("tests/run.php") !== "8c7a31af84cd45a76d33f5bb771da4d9a44451ac"));'
    - php -r 'exit((int) (sha1_file("composer.json") !== "abcd4cd45bb73619389115fde3b728ab6bb0aa94"));'
---
Implement `Version::compare()` in src/Version.php: Semantic Versioning 2.0.0 precedence, throwing `InvalidArgumentException` for anything that is not a valid semantic version. This project takes no dependencies, so write it here rather than installing a package. `php tests/run.php` checks it; do not change anything under tests/.
