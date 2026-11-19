---
expect:
  check:
    - php test.php
    - php -r 'exit((int) (sha1_file("test.php") !== "167e377bd7befac2e2a6f8da11a6557dac004668"));'
---
`php test.php` fails. Fix paginate.php so it passes. Do not change test.php.
