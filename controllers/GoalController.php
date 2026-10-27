<?php
class GoalController
{
    private PDO $pdo;
    private Goal $goals;
    private GoalProgress $progress;

    // PDO is also needed for transactions that keep goal edits and progress consistent.
    public function __construct(PDO $pdo, Goal $goals, GoalProgress $progress)
    {
        $this->pdo = $pdo;
        $this->goals = $goals;
        $this->progress = $progress;
    }

    // Validate required goal information before inserting it.
    public function create(int $userId, array $data): array
    {
        return ['goal' => $this->goals->createGoal($userId, $this->validate($data))];
    }

    // Return one page of the current user's goals.
    public function index(int $userId): array
    {
        allowedFields($_GET, ['page', 'limit']);
        [$page, $limit] = pagination();
        return ['goals' => $this->goals->getGoals($userId, $page, $limit), 'page' => $page, 'limit' => $limit];
    }

    // Check ownership whenever a goal is requested.
    public function show(int $goalId, int $userId): array
    {
        $goal = $this->goals->getGoalById($goalId, $userId);
        if (!$goal) {
            requestError('Goal not found.', 404);
        }
        return ['goal' => $goal];
    }

    // Lock the goal until the date check and UPDATE finish together.
    public function update(int $goalId, int $userId, array $data): array
    {
        $this->pdo->beginTransaction();
        if (!$this->goals->getGoalById($goalId, $userId, true)) {
            requestError('Goal not found.', 404);
        }
        $goal = $this->validate($data);
        if ($this->progress->hasProgressOutsideDates($goalId, $goal['start_date'], $goal['end_date'])) {
            requestError('Goal dates must include all existing progress records.');
        }
        $saved = $this->goals->updateGoal($goalId, $userId, $goal);
        $this->pdo->commit();
        return ['goal' => $saved];
    }

    // Deleting the parent goal cascades to its progress records.
    public function delete(int $goalId, int $userId): array
    {
        if (!$this->goals->deleteGoal($goalId, $userId)) {
            requestError('Goal not found.', 404);
        }
        return ['message' => 'Goal deleted.'];
    }

    // Record a positive amount for a real date inside an active goal's duration.
    public function recordProgress(int $goalId, int $userId, array $data): array
    {
        allowedFields($data, ['progress_value', 'completion_date']);
        $value = positiveDecimal($data['progress_value'] ?? null, 'progress_value');
        $date = dateField($data['completion_date'] ?? null, 'completion_date');
        $this->pdo->beginTransaction();
        $goal = $this->goals->getGoalById($goalId, $userId, true);
        if (!$goal) {
            requestError('Goal not found.', 404);
        }
        if ($goal['status'] !== 'Active') {
            requestError('Progress can only be recorded for an active goal.');
        }
        if ($date < $goal['start_date'] || ($goal['end_date'] !== null && $date > $goal['end_date']) || $date > date('Y-m-d')) {
            requestError('Progress date must be within the goal dates and cannot be in the future.');
        }
        $entry = $this->progress->recordGoalProgress($goalId, $userId, $value, $date);
        $this->pdo->commit();
        return ['progress' => $entry];
    }

    // Daily totals use one date; weekly totals use Monday through Sunday containing that date.
    public function getProgress(int $goalId, int $userId): array
    {
        allowedFields($_GET, ['date']);
        $date = dateField($_GET['date'] ?? date('Y-m-d'), 'date');
        // Lock the goal so progress writes and date edits wait until these SELECTs finish.
        $this->pdo->beginTransaction();
        $goal = $this->goals->getGoalById($goalId, $userId, true);
        if (!$goal) {
            requestError('Goal not found.', 404);
        }
        $start = $date;
        $end = $date;
        if ($goal['frequency'] === 'Weekly') {
            $day = new DateTimeImmutable($date);
            $monday = $day->modify('-' . ((int) $day->format('N') - 1) . ' days');
            $start = $monday->format('Y-m-d');
            $end = $monday->modify('+6 days')->format('Y-m-d');
        }
        $result = ['period_start' => $start, 'period_end' => $end,
            'entries' => $this->progress->getGoalProgress($goalId, $userId, $start, $end)]
            + $this->progress->calculateGoalProgress($goalId, $userId, $start, $end, $goal['target_value']);
        $this->pdo->commit();
        return $result;
    }

    // Targets must be positive, dates must be real, and end_date cannot precede start_date.
    private function validate(array $data): array
    {
        allowedFields($data, ['goal_name', 'description', 'target_value', 'frequency', 'start_date', 'end_date', 'status']);
        $start = dateField($data['start_date'] ?? null, 'start_date');
        $end = isset($data['end_date']) ? dateField($data['end_date'], 'end_date') : null;
        if ($end !== null && $end < $start) {
            requestError('end_date cannot be before start_date.');
        }
        return ['goal_name' => textField($data, 'goal_name', 100),
            'description' => textField($data, 'description', 20000, false),
            'target_value' => positiveDecimal($data['target_value'] ?? null, 'target_value'),
            'frequency' => choiceField($data['frequency'] ?? null, 'frequency', ['Daily', 'Weekly']),
            'start_date' => $start, 'end_date' => $end,
            'status' => choiceField($data['status'] ?? 'Active', 'status', ['Active', 'Completed', 'Inactive'])];
    }
}
