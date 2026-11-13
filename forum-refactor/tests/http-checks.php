<?php
declare(strict_types=1);

/**
 * End-to-end checks for section 8.1 step 8, run against a RUNNING local server
 * whose database was freshly imported from database/schema.sql.
 *
 *   php -S localhost:8000 -t public dev-router.php      (terminal 1)
 *   php tests/http-checks.php http://localhost:8000      (terminal 2)
 *
 * Requires the curl extension. It registers a random fictional user and posts
 * a few comments, so re-import the schema afterwards if you want clean data.
 */

$base = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$failures = 0;

final class Client
{
    private CurlHandle $ch;
    public function __construct(private string $base)
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEFILE => '', // in-memory cookie jar per client
            CURLOPT_TIMEOUT => 15,
        ]);
    }

    /** @return array{status:int, headers:string, body:string, location:string} */
    public function request(string $method, string $path, array $form = []): array
    {
        curl_setopt($this->ch, CURLOPT_URL, $this->base . $path);
        curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $method === 'POST' ? http_build_query($form) : null);
        if ($method !== 'POST') {
            curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        }
        $raw = curl_exec($this->ch);
        if ($raw === false) {
            throw new RuntimeException('Request failed: ' . curl_error($this->ch));
        }
        $size = curl_getinfo($this->ch, CURLINFO_HEADER_SIZE);
        $headers = substr($raw, 0, $size);
        preg_match('/^Location:\s*(\S+)/mi', $headers, $m);
        return ['status' => curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE), 'headers' => $headers,
            'body' => substr($raw, $size), 'location' => $m[1] ?? ''];
    }

    public function get(string $path): array { return $this->request('GET', $path); }
    public function post(string $path, array $form): array { return $this->request('POST', $path, $form); }

    public function sessionCookie(): string
    {
        foreach (curl_getinfo($this->ch, CURLINFO_COOKIELIST) as $line) {
            $parts = explode("\t", $line);
            if (($parts[5] ?? '') === 'nested_forum') {
                return $parts[6] ?? '';
            }
        }
        return '';
    }

    public function csrf(string $path = '/register'): string
    {
        preg_match('/name="csrf" value="([0-9a-f]{64})"/', $this->get($path)['body'], $m);
        return $m[1] ?? '';
    }
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $label . ($ok || $detail === '' ? '' : "  ($detail)") . "\n";
    if (!$ok) {
        $failures++;
    }
}

function commentCount(Client $c, int $discussion = 1): int
{
    preg_match('/<h2>(\d+) comments<\/h2>/', $c->get('/discussions/' . $discussion)['body'], $m);
    return isset($m[1]) ? (int) $m[1] : -1;
}

/** Extract the <li> of one comment so assertions look only at that comment. */
function commentHtml(string $page, int $id): string
{
    return preg_match('/<li id="comment-' . $id . '".*?<\/li>/s', $page, $m) ? $m[0] : '';
}

function newestCommentId(array $response): int
{
    return preg_match('/#comment-(\d+)$/', $response['location'], $m) ? (int) $m[1] : 0;
}

echo "Nested forum HTTP checks against $base\n\n";
$guest = new Client($base);

echo "Reading (public)\n";
check('GET / returns 200', $guest->get('/')['status'] === 200);
$page = $guest->get('/discussions/1');
check('GET /discussions/1 returns 200', $page['status'] === 200);
check('Seed comment 7 (Eli) is at displayed depth 4', str_contains(commentHtml($page['body'], 7), 'data-depth="4"'));
check('Comment 4 (Dee) is a depth-1 sibling of comment 2', str_contains(commentHtml($page['body'], 4), 'data-depth="1"'));
check('Tree order is 1,2,3,6,7,4,5', (function () use ($page): bool {
    preg_match_all('/<li id="comment-(\d+)"/', $page['body'], $m);
    return array_slice(array_map('intval', $m[1]), 0, 7) === [1, 2, 3, 6, 7, 4, 5];
})());
check('Timestamps are shown in UTC', str_contains($page['body'], '2026-11-01 08:10 UTC'));
check('Default avatar is used', str_contains($page['body'], 'src="/avatar.svg"'));
check('Guests see no reply form', !str_contains($page['body'], 'name="parent_id"'));
check('Missing discussion /discussions/999 returns 404', $guest->get('/discussions/999')['status'] === 404);
check('Malformed ID /discussions/abc returns 404', $guest->get('/discussions/abc')['status'] === 404);
check('Out-of-range ID /discussions/0 returns 404', $guest->get('/discussions/0')['status'] === 404);
check('Out-of-range ID /discussions/99999999999 returns 404', $guest->get('/discussions/99999999999')['status'] === 404);

echo "\nGuest writes are rejected\n";
$before = commentCount($guest);
$token = $guest->csrf();
$r = $guest->post('/discussions/1/comments', ['csrf' => $token, 'body' => 'guest top-level', 'parent_id' => '']);
check('Guest POST (top-level) is redirected to /login', $r['status'] === 303 && $r['location'] === '/login', "status {$r['status']}");
$r = $guest->post('/discussions/1/comments', ['csrf' => $token, 'body' => 'guest reply', 'parent_id' => '1', 'user_id' => '1']);
check('Guest POST (reply, forged user_id) is redirected to /login', $r['status'] === 303 && $r['location'] === '/login');
check('No row was created by guest POSTs', commentCount($guest) === $before);

echo "\nRegistration and login\n";
$user = new Client($base);
$name = 'tester_' . bin2hex(random_bytes(3));
$email = $name . '@example.test';
$password = 'correct horse ' . bin2hex(random_bytes(4));
$r = $user->post('/register', ['csrf' => 'wrong', 'username' => $name, 'email' => $email, 'password' => $password, 'password_confirm' => $password]);
check('Register with invalid CSRF token returns 403', $r['status'] === 403);
$r = $user->post('/register', ['csrf' => $user->csrf(), 'username' => $name, 'email' => $email, 'password' => $password, 'password_confirm' => $password]);
check('Register succeeds and redirects to /login', $r['status'] === 303 && $r['location'] === '/login', "status {$r['status']}");
$r = $user->post('/register', ['csrf' => $user->csrf(), 'username' => $name, 'email' => 'other@example.test', 'password' => $password, 'password_confirm' => $password]);
check('Duplicate username is rejected (422)', $r['status'] === 422 && str_contains($r['body'], 'already registered'));
$r = $user->post('/register', ['csrf' => $user->csrf(), 'username' => 'x', 'email' => 'bad', 'password' => 'short', 'password_confirm' => 'short']);
check('Invalid registration input is rejected (422)', $r['status'] === 422);

$r = $user->post('/login', ['csrf' => $user->csrf('/login'), 'email' => $email, 'password' => 'wrong password!!']);
check('Incorrect password is rejected (422)', $r['status'] === 422 && str_contains($r['body'], 'Invalid email or password'));
$r = $user->post('/login', ['csrf' => $user->csrf('/login'), 'email' => 'alice@example.test', 'password' => '']);
check('Seed user with NULL password hash cannot log in', $r['status'] === 422);
$r = $user->post('/login', ['csrf' => 'bad', 'email' => $email, 'password' => $password]);
check('Login with invalid CSRF token returns 403', $r['status'] === 403);

$sessionBefore = $user->sessionCookie();
$token = $user->csrf('/login');
$r = $user->post('/login', ['csrf' => $token, 'email' => $email, 'password' => $password]);
check('Correct login redirects (303)', $r['status'] === 303, "status {$r['status']}");
check('Session ID is regenerated on login', $user->sessionCookie() !== '' && $user->sessionCookie() !== $sessionBefore);
check('Session cookie is HttpOnly and SameSite=Lax', (bool) preg_match('/Set-Cookie: nested_forum=[^\r\n]*HttpOnly[^\r\n]*SameSite=Lax/i', $r['headers']));
check('Pre-login CSRF token no longer works after login', $user->post('/discussions/1/comments', ['csrf' => $token, 'body' => 'x', 'parent_id' => ''])['status'] === 403);

echo "\nAuthenticated replies\n";
$token = $user->csrf('/discussions/1');
$before = commentCount($user);
$r = $user->post('/discussions/1/comments', ['csrf' => $token, 'body' => 'Then add login and CSRF.', 'parent_id' => '7', 'user_id' => '1']);
$replyId = newestCommentId($r);
check('Reply under comment 7 is saved and redirects to its anchor', $r['status'] === 303 && $replyId > 0, "status {$r['status']} {$r['location']}");
$page = $user->get('/discussions/1')['body'];
$li = commentHtml($page, $replyId);
check('New reply is at displayed depth 5', str_contains($li, 'data-depth="5"') && str_contains($li, 'Depth 5'));
check('New reply\'s parent is comment 7', str_contains($li, 'data-parent="7"'));
check('Author is the session user, not the forged user_id', str_contains($li, "<strong>$name</strong>"));
check('New reply appears directly after comment 7', (bool) preg_match('/id="comment-7".*?<\/li>\s*<li id="comment-' . $replyId . '"/s', $page));

$r = $user->post('/discussions/1/comments', ['csrf' => $token, 'body' => 'A second top-level comment.', 'parent_id' => '']);
$topId = newestCommentId($r);
$li = commentHtml($user->get('/discussions/1')['body'], $topId);
check('Second top-level comment is saved at depth 0 by the session user', $topId > 0 && str_contains($li, 'data-depth="0"') && str_contains($li, "<strong>$name</strong>"));
check('Comment count went up by exactly 2', commentCount($user) === $before + 2);

echo "\nRejected writes\n";
$before = commentCount($user);
check('Invalid CSRF token returns 403', $user->post('/discussions/1/comments', ['csrf' => str_repeat('0', 64), 'body' => 'x', 'parent_id' => ''])['status'] === 403);
check('Missing CSRF token returns 403', $user->post('/discussions/1/comments', ['body' => 'x', 'parent_id' => ''])['status'] === 403);
$r = $user->post('/discussions/1/comments', ['csrf' => $token, 'body' => 'cross', 'parent_id' => '8']);
check('Cross-discussion parent (comment 8 of discussion 2) is rejected', $r['status'] === 303 && str_contains($r['location'], '#post-comment'));
check('Rejection message is shown', str_contains($user->get('/discussions/1')['body'], 'does not belong to this discussion'));
$user->post('/discussions/1/comments', ['csrf' => $token, 'body' => 'ghost', 'parent_id' => '424242']);
$user->post('/discussions/1/comments', ['csrf' => $token, 'body' => "  \n ", 'parent_id' => '']);
$user->post('/discussions/1/comments', ['csrf' => $token, 'body' => str_repeat('a', 5001), 'parent_id' => '']);
check('Nonexistent parent, blank body and 5001-char body create no rows', commentCount($user) === $before);
check('POST to missing discussion returns 404', $user->post('/discussions/999/comments', ['csrf' => $token, 'body' => 'x', 'parent_id' => ''])['status'] === 404);

echo "\nHTML escaping\n";
$evil = '<script>alert("xss")</script><b>bold?</b>';
$r = $user->post('/discussions/1/comments', ['csrf' => $token, 'body' => $evil, 'parent_id' => '5']);
$li = commentHtml($user->get('/discussions/1')['body'], newestCommentId($r));
check('Script-like comment is stored and shown as text', str_contains($li, '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;&lt;b&gt;'));
check('No raw <script> tag in the page', !str_contains($user->get('/discussions/1')['body'], '<script>'));

echo "\nLogout\n";
check('Logout without CSRF token returns 403', $user->post('/logout', [])['status'] === 403);
$r = $user->post('/logout', ['csrf' => $user->csrf('/')]);
check('Logout with token redirects (303)', $r['status'] === 303);
$before = commentCount($user);
$r = $user->post('/discussions/1/comments', ['csrf' => $user->csrf(), 'body' => 'after logout', 'parent_id' => '1']);
check('POST after logout is redirected to /login', $r['status'] === 303 && $r['location'] === '/login');
check('No row was created after logout', commentCount($user) === $before);

echo "\n" . ($failures === 0 ? "All checks passed.\n" : "$failures check(s) FAILED.\n");
exit($failures === 0 ? 0 : 1);
