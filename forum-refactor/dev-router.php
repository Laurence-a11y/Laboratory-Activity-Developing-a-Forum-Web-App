<?php
// Router script for PHP's built-in development server ONLY (never production):
//   php -S localhost:8000 -t public dev-router.php
// Real files in public/ (style.css, avatar.svg) are served directly;
// everything else goes to the front controller.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($path, '.php')) {
    return false;
}
require __DIR__ . '/public/index.php';
