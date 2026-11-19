---
timeout: 300
expect:
  check:
    - php tests/run.php
    - php -r 'exit((int) (sha1_file("tests/run.php") !== "06933d6e6e84baa462eee0a65300c7e448db7ba2"));'
---
`php tests/run.php` fails. Find the bug under src/ and fix it. Do not change anything under tests/.
