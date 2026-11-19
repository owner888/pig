---
timeout: 600
expect:
  check:
    - php tests/run.php
    - php -r 'exit((int) (sha1_file("tests/run.php") !== "39f22d17884578df6bd7bf6a3aca0767f924aeb1"));'
    - php -r 'exit((int) (substr_count(implode("", array_map("file_get_contents", glob("src/*.php"))), "preg_match") !== 1));'
---
Signup, Invite and Profile under src/ each check an email address their own way, and they do not agree. Make all three use one check, Signup's rule, written once. `php tests/run.php` must pass; do not change anything under tests/.
