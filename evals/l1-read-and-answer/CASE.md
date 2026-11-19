---
expect:
  answer:
    regex: ['/(?<![\w-])hello-world-php-8-3(?![\w-])/']
  files:
    - path: slug.php
      contains: ['function slugify']
  tools:
    not_called: [edit, write]
---
What does `slugify('  Hello,   World -- PHP 8.3! ')` in slug.php return? Reply with the value only.
