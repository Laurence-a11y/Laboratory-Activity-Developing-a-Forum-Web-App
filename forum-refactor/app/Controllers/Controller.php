<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\Session;
use Mustache_Engine;
use Psr\Log\LoggerInterface;

/** Shared HTTP helpers. Controllers never contain SQL or HTML. */
abstract class Controller
{
    public function __construct(
        protected readonly Mustache_Engine $views,
        protected readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string,mixed> $data */
    protected function render(string $view, array $data = [], int $status = 200): void
    {
        http_response_code($status);
        echo $this->views->render($view, array_merge([
            'csrf' => Session::csrfToken(),
            'logged_in' => Session::userId() !== null,
            'username' => Session::username(),
            'flash_success' => Session::takeFlash('success'),
            'flash_error' => Session::takeFlash('error'),
        ], $data));
    }

    protected function redirect(string $path): never
    {
        // Only ever called with internal paths built by the application.
        header('Location: ' . $path, true, 303);
        exit;
    }

    protected function notFound(): never
    {
        $this->render('error', [
            'title' => 'Not found',
            'message' => 'That page or discussion does not exist.',
        ], 404);
        exit;
    }

    protected function invalidToken(): never
    {
        $this->logger->warning('csrf.rejected', ['path' => $_SERVER['REQUEST_URI'] ?? '']);
        $this->render('error', [
            'title' => 'Form expired',
            'message' => 'Your form token was missing or invalid. Go back, reload the page and try again.',
        ], 403);
        exit;
    }

    /**
     * Route patterns already guarantee digits. This also rejects 0, leading
     * zeros and values above INT UNSIGNED, all answered with 404.
     */
    protected function parseId(string $raw): ?int
    {
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $raw) || (int) $raw > 4294967295) {
            return null;
        }
        return (int) $raw;
    }

    protected function input(string $name): string
    {
        $value = $_POST[$name] ?? '';
        return is_string($value) ? trim($value) : '';
    }
}
