# Helpers

These classes carry no product semantics and can be composed directly in framework services.

## Arrays and strings

`plato\arr` provides dotted-path `get/set/del/key_exists`, recursive merge, plus row-list `group_by()`, `pluck()`, stable multi-key `sort()`, and `tree()`.

`plato\str` provides format detection, secure random strings, 19-digit numeric ids, placeholder replacement, Unicode masking, byte formatting, and stable bucketing. Real uniqueness must be enforced by a database unique index.

## Dates

```php
use plato\date;

$utc = date::convert('2026-07-31 09:00:00', 'UTC', null, 'Asia/Taipei');
$range = date::month_range('2026-07', 'Asia/Taipei');
$valid = date::valid('2026-02-28', 'Y-m-d');
```

A timestamp represents an instant; a string represents wall-clock time in a named timezone. The display timezone used by `date` is configured separately from PHP's process timezone.

## Other helpers

- `plato\cast`: basic request-value type conversion
- `plato\file`: extensions, path existence, file writes, and image URLs
- `plato\paginator::meta()`: pagination metadata independent of requests and HTML
- `plato\cli`: terminal I/O, colors, options, and standard streams
- `plato\config`: configuration access with dotted paths

Helpers solve reusable mechanics only. Phone numbers, currency, business ids, translated fields, and domain trees belong to the host application.
