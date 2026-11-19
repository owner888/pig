---
timeout: 300
expect:
  check:
    - php tests/run.php
    - php -r 'exit((int) (sha1_file("tests/run.php") !== "6604a51adac7d9c5d5b43d2095771565e7b16e98"));'
---
Add a `--json` option to bin/stats.php. With it, the script prints one JSON object instead of the three lines, for example `{"count": 4, "sum": 43, "mean": 10.75}`; `mean` is null when the file has no numbers. The option may come before or after the file name. Running it without a file, or with an option it does not know, exits 2. Without `--json` the output stays as it is.

`php tests/run.php` checks all of this. Do not change anything under tests/.
