<?php
class User
{
    private PDO $pdo;

    // Keep the database connection available to this model's methods.
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // INSERT stores a secure password hash; RETURNING gives us the new public account fields.
    public function createUser(string $username, string $email, string $password): array
    {
        $statement = $this->pdo->prepare('INSERT INTO users (username, email, password_hash)
            VALUES (:username, :email, :password_hash) RETURNING user_id, username, email, created_at');
        $statement->execute(['username' => $username, 'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        return $statement->fetch();
    }

    // Login needs the hash internally; controllers never send it to the client.
    public function findByEmail(string $email): array|false
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE email = :email');
        $statement->execute(['email' => $email]);
        return $statement->fetch();
    }

    // SELECT only public account information for the current session.
    public function getUserById(int $userId): array|false
    {
        $statement = $this->pdo->prepare('SELECT user_id, username, email, created_at FROM users WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
        return $statement->fetch();
    }
}
