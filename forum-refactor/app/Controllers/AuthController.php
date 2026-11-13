<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Services\Session;
use Illuminate\Database\QueryException;

final class AuthController extends Controller
{
    private const PASSWORD_MIN = 12;
    private const PASSWORD_MAX = 72; // bcrypt ignores bytes beyond 72
    /** Hash of a random throwaway string; only used to equalise login timing. */
    private const DUMMY_HASH = '$2y$10$m72dHHGAHm354PMpZ87W0OECvVuwSUAia4KVOp7YsPEIjyvD8l.A.';

    /** GET /register */
    public function registerForm(): void
    {
        $this->render('register', ['old_username' => '', 'old_email' => '']);
    }

    /** POST /register */
    public function register(): void
    {
        if (!Session::checkCsrf($_POST['csrf'] ?? null)) {
            $this->invalidToken();
        }
        $username = $this->input('username');
        $email = strtolower($this->input('email'));
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        $error = match (true) {
            !preg_match('/^[A-Za-z0-9_]{3,30}$/D', $username)
                => 'Usernames are 3–30 characters: letters, numbers and underscores.',
            strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
                => 'Enter a valid email address.',
            !is_string($password) || strlen($password) < self::PASSWORD_MIN || strlen($password) > self::PASSWORD_MAX
                => 'Passwords must be ' . self::PASSWORD_MIN . '–' . self::PASSWORD_MAX . ' bytes long.',
            !is_string($confirm) || !hash_equals($password, $confirm)
                => 'The two passwords do not match.',
            User::usernameOrEmailTaken($username, $email)
                => 'That username or email is already registered.',
            default => null,
        };

        if ($error === null) {
            try {
                $user = User::create([
                    'username' => $username,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                $this->logger->info('user.registered', ['user_id' => $user->id]);
                Session::flash('success', 'Account created. You can log in now.');
                $this->redirect('/login');
            } catch (QueryException $e) {
                // A concurrent registration can still hit the UNIQUE indexes.
                if (($e->errorInfo[1] ?? null) !== 1062) {
                    throw $e;
                }
                $error = 'That username or email is already registered.';
            }
        }

        $this->render('register', [
            'error' => $error,
            'old_username' => $username,
            'old_email' => $email,
        ], 422);
    }

    /** GET /login */
    public function loginForm(): void
    {
        $this->render('login', ['old_email' => '']);
    }

    /** POST /login */
    public function login(): void
    {
        if (!Session::checkCsrf($_POST['csrf'] ?? null)) {
            $this->invalidToken();
        }
        $email = strtolower($this->input('email'));
        $password = $_POST['password'] ?? '';

        $user = $email !== '' ? User::findByEmail($email) : null;
        $ok = $user !== null && is_string($password) && $user->checkPassword($password);
        if ($user === null) {
            // Spend similar time when the email is unknown, to avoid account probing.
            password_verify('dummy-password', self::DUMMY_HASH);
        }

        if (!$ok) {
            $this->logger->notice('auth.login_failed'); // never log the email/password typed
            $this->render('login', ['error' => 'Invalid email or password.', 'old_email' => $email], 422);
            return;
        }

        Session::logIn((int) $user->id, $user->username); // regenerates the session ID
        $this->logger->info('auth.login', ['user_id' => $user->id]);
        Session::flash('success', 'Welcome back, ' . $user->username . '.');
        $this->redirect('/');
    }

    /** POST /logout */
    public function logout(): void
    {
        if (!Session::checkCsrf($_POST['csrf'] ?? null)) {
            $this->invalidToken();
        }
        $userId = Session::userId();
        Session::logOut();
        $this->logger->info('auth.logout', ['user_id' => $userId]);
        Session::flash('success', 'You have logged out.');
        $this->redirect('/');
    }
}
