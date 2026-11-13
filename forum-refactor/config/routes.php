<?php
declare(strict_types=1);

/**
 * Route table. Path patterns use (\d+), so a non-numeric ID such as
 * /discussions/abc never reaches a controller: the router answers 404.
 * Controllers also reject numeric IDs that are out of range (404).
 *
 * @var Bramus\Router\Router $router
 * @var App\Controllers\DiscussionController $discussions
 * @var App\Controllers\AuthController $auth
 * @var App\Controllers\CommentController $comments
 */

// Public reading
$router->get('/', [$discussions, 'index']);
$router->get('/discussions/(\d+)', [$discussions, 'show']);

// Accounts
$router->get('/register', [$auth, 'registerForm']);
$router->post('/register', [$auth, 'register']);
$router->get('/login', [$auth, 'loginForm']);
$router->post('/login', [$auth, 'login']);
$router->post('/logout', [$auth, 'logout']);

// Authenticated writes: a top-level comment (no parent_id) or a reply (parent_id set)
$router->post('/discussions/(\d+)/comments', [$comments, 'store']);
