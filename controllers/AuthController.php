<?php
class AuthController
{
    private User $users;

    // Authentication delegates account queries to the User model.
    public function __construct(User $users)
    {
        $this->users = $users;
    }

    // Validate registration and hash the password in the model before saving it.
    public function register(array $data): array
    {
        allowedFields($data, ['username', 'email', 'password']);
        $username = textField($data, 'username', 50);
        $email = $this->email($data);
        $password = $this->password($data, true);
        try {
            return ['user' => $this->users->createUser($username, $email, $password),
                'message' => 'Account created. You can now log in.'];
        } catch (PDOException $exception) {
            // PostgreSQL error 23505 means a UNIQUE constraint rejected a duplicate email.
            if ($exception->getCode() === '23505') {
                requestError('An account with this email already exists.', 409);
            }
            throw $exception;
        }
    }

    // Verify the saved hash, replace the session ID, and remember the authenticated user.
    public function login(array $data): array
    {
        allowedFields($data, ['email', 'password']);
        $email = $this->email($data);
        $password = $this->password($data, false);
        $user = $this->users->findByEmail($email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            requestError('Email or password is incorrect.', 401);
        }
        // Regeneration prevents an attacker from choosing the session ID used after login.
        session_regenerate_id(true);
        $_SESSION = ['user_id' => $user['user_id'], 'csrf_token' => bin2hex(random_bytes(32))];
        unset($user['password_hash']);
        return ['user' => $user, 'csrf_token' => $_SESSION['csrf_token']];
    }

    // Remove server-side login data and expire the browser cookie with matching cookie settings.
    public function logout(): array
    {
        $_SESSION = [];
        $settings = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $settings['path'],
            'secure' => $settings['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        session_destroy();
        return ['message' => 'Logged out.'];
    }

    // GET /api/me also provides the first CSRF token before registration or login.
    public function me(): array
    {
        $user = isset($_SESSION['user_id']) ? $this->users->getUserById((int) $_SESSION['user_id']) : null;
        if ($user === false) {
            unset($_SESSION['user_id']);
            $user = null;
        }
        return ['user' => $user, 'csrf_token' => $_SESSION['csrf_token']];
    }

    // Normalize emails so differently capitalized versions cannot register twice.
    private function email(array $data): string
    {
        $email = strtolower(textField($data, 'email', 100));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            requestError('A valid email is required.');
        }
        return $email;
    }

    // Never trim a password: spaces can be part of it. The byte limit avoids bcrypt truncation.
    private function password(array $data, bool $registration): string
    {
        $password = $data['password'] ?? null;
        if (!is_string($password) || str_contains($password, "\0") || strlen($password) > 72 ||
            strlen($password) < ($registration ? 8 : 1)) {
            requestError('Password must contain ' . ($registration ? '8' : '1') . ' to 72 bytes and no null characters.');
        }
        return $password;
    }
}
