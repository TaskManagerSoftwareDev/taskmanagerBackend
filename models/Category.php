<?php
class Category
{
    private PDO $pdo;

    // Receive the shared PDO connection.
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // Categories are shared choices, rather than private user records.
    public function getCategories(): array
    {
        return $this->pdo->query('SELECT * FROM categories ORDER BY category_id')->fetchAll();
    }

    // Check a submitted category before using its foreign key.
    public function exists(int $categoryId): bool
    {
        $statement = $this->pdo->prepare('SELECT category_id FROM categories WHERE category_id = :id');
        $statement->execute(['id' => $categoryId]);
        return $statement->fetch() !== false;
    }
}
