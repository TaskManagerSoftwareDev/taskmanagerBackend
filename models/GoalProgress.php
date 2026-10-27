<?php
class GoalProgress
{
    private PDO $pdo;

    // Receive the shared PDO connection.
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // Check ownership inside the INSERT as well as in the controller.
    public function recordGoalProgress(int $goalId, int $userId, string $value, string $date): array
    {
        $statement = $this->pdo->prepare('INSERT INTO goal_progress (goal_id, progress_value, completion_date)
            SELECT goal_id, :value, :date FROM goals WHERE goal_id = :goal_id AND user_id = :user_id RETURNING *');
        $statement->execute(['value' => $value, 'date' => $date, 'goal_id' => $goalId, 'user_id' => $userId]);
        return $statement->fetch();
    }

    // Return dated history for the requested period, checking the parent goal's owner.
    public function getGoalProgress(int $goalId, int $userId, string $start, string $end): array
    {
        $statement = $this->pdo->prepare('SELECT p.* FROM goal_progress p JOIN goals g ON g.goal_id = p.goal_id
            WHERE p.goal_id = :goal_id AND g.user_id = :user_id AND completion_date BETWEEN :start AND :end
            ORDER BY completion_date, progress_id');
        $statement->execute(['goal_id' => $goalId, 'user_id' => $userId, 'start' => $start, 'end' => $end]);
        return $statement->fetchAll();
    }

    // SUM adds this period's entries. COALESCE replaces an empty sum with zero.
    public function calculateGoalProgress(int $goalId, int $userId, string $start, string $end, string $target): array
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(SUM(p.progress_value), 0) FROM goal_progress p
            JOIN goals g ON g.goal_id = p.goal_id WHERE p.goal_id = :goal_id AND g.user_id = :user_id
            AND completion_date BETWEEN :start AND :end');
        $statement->execute(['goal_id' => $goalId, 'user_id' => $userId, 'start' => $start, 'end' => $end]);
        $total = (float) $statement->fetchColumn();
        // For example, 2.5 / 3 * 100 = 83.33%. Extra progress can exceed 100%.
        return ['total_progress' => $total, 'target_value' => (float) $target,
            'percentage' => round($total / (float) $target * 100, 2)];
    }

    // Prevent editing a goal's dates in a way that strands already recorded progress.
    public function hasProgressOutsideDates(int $goalId, string $start, ?string $end): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM goal_progress WHERE goal_id = :id
            AND (completion_date < :start OR completion_date > :end)');
        $statement->execute(['id' => $goalId, 'start' => $start, 'end' => $end ?? '9999-12-31']);
        return (int) $statement->fetchColumn() > 0;
    }
}
