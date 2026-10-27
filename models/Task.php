<?php
class Task
{
    private PDO $pdo;

    // Receive the shared PDO connection.
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // INSERT creates a task owned by the session's user, never a submitted user_id.
    public function createTask(int $userId, array $task): array
    {
        $statement = $this->pdo->prepare('INSERT INTO tasks (user_id, title, description, category_id, status)
            VALUES (:user_id, :title, :description, :category_id, :status) RETURNING *');
        $statement->execute(['user_id' => $userId] + $task);
        return $statement->fetch();
    }

    // Build combined filters using bound values, so search text cannot become SQL code.
    public function getTasks(int $userId, array $filters, int $page, int $limit): array
    {
        $sql = 'SELECT tasks.*, categories.category_name FROM tasks
            LEFT JOIN categories ON tasks.category_id = categories.category_id WHERE user_id = :user_id';
        $parameters = ['user_id' => $userId];
        if (isset($filters['search'])) {
            $sql .= ' AND title ILIKE :search';
            // Treat % and _ as literal search characters rather than wildcard instructions.
            $parameters['search'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']) . '%';
        }
        foreach (['category_id', 'status'] as $field) {
            if (isset($filters[$field])) {
                $sql .= " AND tasks.$field = :$field";
                $parameters[$field] = $filters[$field];
            }
        }
        $sql .= ' ORDER BY task_id DESC LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    // The ownership condition prevents another user from reading this task by guessing its ID.
    public function getTaskById(int $taskId, int $userId): array|false
    {
        $statement = $this->pdo->prepare('SELECT * FROM tasks WHERE task_id = :id AND user_id = :user_id');
        $statement->execute(['id' => $taskId, 'user_id' => $userId]);
        return $statement->fetch();
    }

    // UPDATE changes only a task belonging to this user.
    public function updateTask(int $taskId, int $userId, array $task): array|false
    {
        $statement = $this->pdo->prepare('UPDATE tasks SET title = :title, description = :description,
            category_id = :category_id, status = :status WHERE task_id = :id AND user_id = :user_id RETURNING *');
        $statement->execute(['id' => $taskId, 'user_id' => $userId] + $task);
        return $statement->fetch();
    }

    // DELETE removes a task only when its ID and owner both match.
    public function deleteTask(int $taskId, int $userId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM tasks WHERE task_id = :id AND user_id = :user_id');
        $statement->execute(['id' => $taskId, 'user_id' => $userId]);
        return $statement->rowCount() > 0;
    }

    // Change completion status without replacing the other fields.
    public function updateTaskStatus(int $taskId, int $userId, string $status): array|false
    {
        $statement = $this->pdo->prepare('UPDATE tasks SET status = :status
            WHERE task_id = :id AND user_id = :user_id RETURNING *');
        $statement->execute(['status' => $status, 'id' => $taskId, 'user_id' => $userId]);
        return $statement->fetch();
    }

    // Aggregate counts in PostgreSQL instead of loading every task into PHP.
    public function statistics(int $userId): array
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) AS total_tasks,
            COUNT(*) FILTER (WHERE status = 'Completed') AS completed_tasks,
            COUNT(*) FILTER (WHERE status = 'Incomplete') AS incomplete_tasks FROM tasks WHERE user_id = :id");
        $statement->execute(['id' => $userId]);
        return array_map('intval', $statement->fetch());
    }
}
