<?php
class Goal
{
    private PDO $pdo;

    // Receive the shared PDO connection.
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // INSERT a recurring goal with the authenticated user's ID.
    public function createGoal(int $userId, array $goal): array
    {
        $statement = $this->pdo->prepare('INSERT INTO goals
            (user_id, goal_name, description, target_value, frequency, start_date, end_date, status)
            VALUES (:user_id, :goal_name, :description, :target_value, :frequency, :start_date, :end_date, :status) RETURNING *');
        $statement->execute(['user_id' => $userId] + $goal);
        return $statement->fetch();
    }

    // SELECT this user's goals, with a small page size.
    public function getGoals(int $userId, int $page, int $limit): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM goals WHERE user_id = :id ORDER BY goal_id DESC LIMIT :limit OFFSET :offset');
        $statement->bindValue('id', $userId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    // Optionally lock the row during a transaction so progress and edits cannot race.
    public function getGoalById(int $goalId, int $userId, bool $lock = false): array|false
    {
        $sql = 'SELECT * FROM goals WHERE goal_id = :id AND user_id = :user_id';
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['id' => $goalId, 'user_id' => $userId]);
        return $statement->fetch();
    }

    // UPDATE preserves ownership and returns the saved goal.
    public function updateGoal(int $goalId, int $userId, array $goal): array|false
    {
        $statement = $this->pdo->prepare('UPDATE goals SET goal_name = :goal_name, description = :description,
            target_value = :target_value, frequency = :frequency, start_date = :start_date,
            end_date = :end_date, status = :status WHERE goal_id = :id AND user_id = :user_id RETURNING *');
        $statement->execute(['id' => $goalId, 'user_id' => $userId] + $goal);
        return $statement->fetch();
    }

    // The foreign key automatically removes progress when this goal is deleted.
    public function deleteGoal(int $goalId, int $userId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM goals WHERE goal_id = :id AND user_id = :user_id');
        $statement->execute(['id' => $goalId, 'user_id' => $userId]);
        return $statement->rowCount() > 0;
    }

    // Count the user's goals for the JSON dashboard.
    public function statistics(int $userId): array
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) AS total_goals,
            COUNT(*) FILTER (WHERE status = 'Active') AS active_goals FROM goals WHERE user_id = :id");
        $statement->execute(['id' => $userId]);
        return array_map('intval', $statement->fetch());
    }
}
