---
timeout: 600
expect:
  check:
    - php report.php
    - php tests/run.php
    - php -r 'exit((int) (sha1_file("tests/run.php") !== "a0c4746a77175d04aa9921e4369dfead62e45680"));'
    - php -r 'exit((int) (sha1_file("orders.csv") !== "d0071db764497b9dce00fd8805d60f60d538aba9"));'
---
`php report.php` crashes with a TypeError. Fix it so the report prints, and so that `php tests/run.php` passes. Do not change tests/ or orders.csv.
