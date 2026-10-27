<?php
// Run from the project root: php database/seed.php
// For the separate test database: DB_NAME=task_manager_test php database/seed.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/config/database.php';

$pdo = null;
try {
    $pdo = connectDatabase();
    $databaseName = $pdo->query('SELECT current_database()')->fetchColumn();
    if (!in_array($databaseName, ['task_manager', 'task_manager_test'], true)) {
        throw new RuntimeException('Choose task_manager or task_manager_test for demo data.');
    }

    // A transaction prevents a failed seed from leaving a partly created demo account.
    $pdo->beginTransaction();
    $pdo->exec(file_get_contents(__DIR__ . '/seed.sql'));
    $categories = $pdo->query('SELECT category_name, category_id FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR);

    $findUser = $pdo->prepare('SELECT user_id FROM users WHERE email = :email');
    $insertUser = $pdo->prepare('INSERT INTO users (username, email, password_hash)
        VALUES (:username, :email, :password_hash) RETURNING user_id');
    $insertTask = $pdo->prepare('INSERT INTO tasks (user_id, category_id, title, description, status)
        VALUES (:user_id, :category_id, :title, :description, :status)');
    $insertGoal = $pdo->prepare('INSERT INTO goals
        (user_id, goal_name, description, target_value, frequency, start_date, end_date, status)
        VALUES (:user_id, :goal_name, :description, :target_value, :frequency, :start_date, :end_date, :status)
        RETURNING goal_id');
    $insertProgress = $pdo->prepare('INSERT INTO goal_progress (goal_id, progress_value, completion_date)
        VALUES (:goal_id, :progress_value, :completion_date)');

    // These task examples come from M1. Different owners let you try the privacy rules.
    $demoUsers = [
        ['username' => 'zin', 'email' => 'zin@email.com', 'tasks' => [
            ['title' => 'Finish CSC 350 Project', 'category' => 'School', 'status' => 'Incomplete'],
            ['title' => 'Study for Midterm', 'category' => 'School', 'status' => 'Completed'],
            ['title' => 'Finish Work Schedule', 'category' => 'Work', 'status' => 'Incomplete']
        ]],
        ['username' => 'john', 'email' => 'john@email.com', 'tasks' => [
            ['title' => 'Buy Groceries', 'category' => 'Personal', 'status' => 'Completed'],
            ['title' => 'Finish Homework', 'category' => 'School', 'status' => 'Incomplete']
        ]]
    ];

    $messages = [];
    foreach ($demoUsers as $demoUser) {
        $findUser->execute(['email' => $demoUser['email']]);
        if ($findUser->fetchColumn() !== false) {
            // Leave existing passwords and records alone, including accounts from earlier seed runs.
            $messages[] = 'Skipped existing account: ' . $demoUser['email'];
            continue;
        }
        $insertUser->execute(['username' => $demoUser['username'], 'email' => $demoUser['email'],
            'password_hash' => password_hash('Student123!', PASSWORD_DEFAULT)]);
        $userId = (int) $insertUser->fetchColumn();
        foreach ($demoUser['tasks'] as $task) {
            $insertTask->execute(['user_id' => $userId, 'category_id' => $categories[$task['category']],
                'title' => $task['title'], 'description' => 'Sample task from the M1 dataset.', 'status' => $task['status']]);
        }

        if ($demoUser['username'] === 'zin') {
            // Use recent dates so the default progress endpoints show useful data immediately.
            $today = new DateTimeImmutable('today');
            $demoGoals = [
                ['name' => 'Drink Water', 'description' => 'Drink 3 liters of water daily.', 'target' => '3.00',
                    'frequency' => 'Daily', 'entries' => [
                        ['value' => '3.00', 'date' => $today->modify('-1 day')->format('Y-m-d')],
                        ['value' => '2.50', 'date' => $today->format('Y-m-d')]
                    ]],
                ['name' => 'Workout', 'description' => 'Work out 5 times weekly.', 'target' => '5.00',
                    'frequency' => 'Weekly', 'entries' => [
                        ['value' => '4.00', 'date' => $today->format('Y-m-d')]
                    ]]
            ];
            foreach ($demoGoals as $goal) {
                $insertGoal->execute(['user_id' => $userId, 'goal_name' => $goal['name'],
                    'description' => $goal['description'], 'target_value' => $goal['target'],
                    'frequency' => $goal['frequency'], 'start_date' => $today->modify('-30 days')->format('Y-m-d'),
                    'end_date' => $today->modify('+1 year')->format('Y-m-d'), 'status' => 'Active']);
                $goalId = (int) $insertGoal->fetchColumn();
                foreach ($goal['entries'] as $entry) {
                    $insertProgress->execute(['goal_id' => $goalId, 'progress_value' => $entry['value'],
                        'completion_date' => $entry['date']]);
                }
            }
        }
        $messages[] = 'Created demo account and records: ' . $demoUser['email'];
    }
    $pdo->commit();
    echo "Seeded $databaseName.\n" . implode("\n", $messages) . "\n";
    echo "New demo accounts use password: Student123! (local demo only).\n";
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // This CLI script prints errors to your terminal, not to API clients.
    fwrite(STDERR, 'Seeding failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
