---
expect:
  files:
    - path: greet.php
      contains: ['Hello, {$name}!']
    - path: greet.php.bak
      exists: false
  check: [php -l greet.php]
  tools:
    called: [edit]
    not_called: [write]
---
greet.php prints "Helo" where it should print "Hello". Fix the typo in place and change nothing else.
