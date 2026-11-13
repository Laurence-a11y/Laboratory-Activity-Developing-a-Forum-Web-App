<?php
declare(strict_types=1);

/**
 * Front controller: the only PHP file inside the web root.
 * Request flow: Router -> Controller -> Model (Eloquent/PDO) -> View (Mustache).
 */

use App\Controllers\AuthController;
use App\Controllers\CommentController;
use App\Controllers\DiscussionController;
use App\Services\CommentService;
use App\Services\CommentTree;
use App\Services\Session;
use Bramus\Router\Router;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

date_default_timezone_set('UTC');
ini_set('display_errors', getenv('APP_DEBUG') === '1' ? '1' : '0');

Session::start();
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'");

$logger = new Logger('forum');
$logger->pushHandler(new StreamHandler($root . '/storage/logs/forum.log', Level::Info));

try {
    require $root . '/config/database.php';

    $viewLoader = new Mustache_Loader_FilesystemLoader($root . '/app/Views');
    $views = new Mustache_Engine([
        'loader' => $viewLoader,
        'partials_loader' => $viewLoader,
        'escape' => static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        'strict_callables' => true,
    ]);

    $tree = new CommentTree();
    $discussions = new DiscussionController($views, $logger, $tree);
    $auth = new AuthController($views, $logger);
    $comments = new CommentController($views, $logger, new CommentService($tree));

    $router = new Router();
    $router->set404(static function () use ($views): void {
        http_response_code(404);
        echo $views->render('error', [
            'title' => 'Not found',
            'message' => 'That page or discussion does not exist.',
            'csrf' => Session::csrfToken(),
            'logged_in' => Session::userId() !== null,
            'username' => Session::username(),
        ]);
    });
    require $root . '/config/routes.php';
    $router->run();
} catch (Throwable $error) {
    // Log the class and location only: never request bodies, passwords or tokens.
    $logger->error('request.failed', ['type' => get_class($error), 'at' => $error->getFile() . ':' . $error->getLine()]);
    http_response_code(500);
    echo getenv('APP_DEBUG') === '1'
        ? '<pre>' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') . '</pre>'
        : 'An unexpected error occurred.';
}
