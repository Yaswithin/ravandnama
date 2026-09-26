# Ravandnama (روندنما)

Ravandnama is an open-source personal life management application.

## Requirements

- PHP 8.4 or newer
- Composer

## Initial setup

```bash
composer install
php -S localhost:8000 -t public
```

Then open <http://localhost:8000> in a browser.

The current starting page is served by `public/index.php`. Composer maps the
`App\\` namespace to the `app/` directory using PSR-4.
