<?php
// Run with: DB_USER=your_username php tests/run.php
// This script starts its own HTTP server and uses only task_manager_test.
putenv('DB_NAME=task_manager_test');
putenv('SESSION_SECURE=0');
require dirname(__DIR__) . '/config/database.php';

$pdo = null;
$server = null;
$clients = [];
$createdUserIds = [];
$checks = 0;
$logFile = tempnam(sys_get_temp_dir(), 'task-manager-test-log-');

// Fail immediately when an observable result differs from what the API promises.
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

// Give each simulated browser its own cookie jar and CSRF token.
function newClient(): array
{
    return ['cookie' => tempnam(sys_get_temp_dir(), 'task-manager-cookie-'), 'token' => ''];
}

// Send a real HTTP request, carrying cookies just as a browser would.
function request(array &$client, string $method, string $path, ?array $body = null,
    int $expected = 200, bool $csrf = true, ?string $rawBody = null): array
{
    global $baseUrl;
    $curl = curl_init($baseUrl . $path);
    $headers = ['Content-Type: application/json'];
    if ($csrf) {
        $headers[] = 'X-CSRF-Token: ' . $client['token'];
    }
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => $client['cookie'], CURLOPT_COOKIEJAR => $client['cookie'],
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);
    if ($body !== null || $rawBody !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $rawBody ?? json_encode($body, JSON_THROW_ON_ERROR));
    }
    $response = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($response === false) {
        throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
    }
    // Flush cookies before the next request creates a fresh cURL handle.
    curl_setopt($curl, CURLOPT_COOKIELIST, 'FLUSH');
    unset($curl);
    check($status === $expected, "$method $path expected $expected, got $status: $response");
    $json = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
    if (isset($json['csrf_token'])) {
        $client['token'] = $json['csrf_token'];
    }
    return $json;
}

try {
    if (!extension_loaded('curl') || !extension_loaded('pdo_pgsql')) {
        throw new RuntimeException('Tests require the curl and pdo_pgsql PHP extensions.');
    }
    $pdo = connectDatabase();
    check($pdo->query('SELECT current_database()')->fetchColumn() === 'task_manager_test', 'Use only the test database.');
    // Install the schema once. Later runs keep unrelated test data and clean up only their own users.
    if ($pdo->query("SELECT to_regclass('public.users')")->fetchColumn() === null) {
        $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    }
    $pdo->exec(file_get_contents(dirname(__DIR__) . '/database/seed.sql'));
    $categoryIds = $pdo->query('SELECT category_id FROM categories ORDER BY category_id')->fetchAll(PDO::FETCH_COLUMN);
    $school = (int) $categoryIds[0];
    $work = (int) $categoryIds[1];

    // Ask the OS for an available local port, then start PHP using that port.
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    if (!$socket) {
        throw new RuntimeException($errorMessage);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $baseUrl = 'http://' . $address;
    $server = proc_open([PHP_BINARY, '-S', $address, '-t', 'public', 'public/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
        $pipes, dirname(__DIR__));
    if (!is_resource($server)) {
        throw new RuntimeException('Could not start the PHP test server.');
    }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @stream_socket_client('tcp://' . $address, $errorNumber, $errorMessage, 0.1);
        if ($probe) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check($ready, 'Test server must start.');
    $clients[] = newClient();
    $clients[] = newClient();
    $alice = &$clients[0];
    $bob = &$clients[1];
    $suffix = bin2hex(random_bytes(6));
    $email = "alice-$suffix@example.com";
    $password = 'Student-password-123';

    $me = request($alice, 'GET', '/api/me');
    check($me['user'] === null && strlen($alice['token']) === 64, 'Anonymous clients receive a token, not private user data.');
    request($alice, 'GET', '/api/tasks', null, 401);
    request($alice, 'POST', '/api/register', [], 403, false);
    request($alice, 'POST', '/api/register', ['username' => '', 'email' => 'bad', 'password' => 'short'], 422);
    $registered = request($alice, 'POST', '/api/register', ['username' => 'Alice', 'email' => $email, 'password' => $password], 201);
    $createdUserIds[] = $registered['user']['user_id'];
    check(!isset($registered['user']['password_hash']), 'Registration must not expose a hash.');
    $statement = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = :id');
    $statement->execute(['id' => $createdUserIds[0]]);
    $hash = $statement->fetchColumn();
    check($hash !== $password && password_verify($password, $hash), 'Passwords are stored as verifiable hashes.');
    request($alice, 'POST', '/api/register', ['username' => 'Duplicate', 'email' => strtoupper($email), 'password' => $password], 409);
    request($alice, 'POST', '/api/login', ['email' => $email, 'password' => 'wrong'], 401);
    $oldToken = $alice['token'];
    request($alice, 'POST', '/api/login', ['email' => strtoupper($email), 'password' => $password]);
    check($alice['token'] !== $oldToken, 'Login rotates the CSRF token.');
    check(request($alice, 'GET', '/api/me')['user']['username'] === 'Alice', 'The session remembers the user.');
    $stale = $alice;
    $stale['token'] = $oldToken;
    request($stale, 'POST', '/api/tasks', ['title' => 'Old token'], 403);
    check(count(request($alice, 'GET', '/api/categories')['categories']) === 3, 'Default categories exist.');

    request($alice, 'POST', '/api/tasks', ['title' => ''], 422);
    request($alice, 'POST', '/api/tasks', ['title' => ['bad']], 422);
    request($alice, 'POST', '/api/tasks', ['title' => 'Invalid category', 'category_id' => 2147483647], 422);
    request($alice, 'POST', '/api/tasks', ['title' => 'Invalid status', 'status' => 'Done'], 422);
    request($alice, 'POST', '/api/tasks', ['title' => 'Wrong owner', 'user_id' => 2], 422);
    request($alice, 'POST', '/api/tasks', null, 400, true, '{broken');
    request($alice, 'POST', '/api/tasks', null, 400, true, '[]');
    $task = request($alice, 'POST', '/api/tasks', ['title' => 'Finish CSC 350 Project', 'category_id' => $school], 201)['task'];
    $taskId = $task['task_id'];
    check($task['status'] === 'Incomplete', 'A task starts incomplete.');
    $otherTask = request($alice, 'POST', '/api/tasks', ['title' => 'Work shift', 'category_id' => $work, 'status' => 'Completed'], 201)['task'];
    $uncategorized = request($alice, 'POST', '/api/tasks', ['title' => 'Uncategorized'], 201)['task'];
    check($uncategorized['category_id'] === null, 'M1 permits an optional category.');
    check(request($alice, 'GET', "/api/tasks/$taskId")['task']['title'] === $task['title'], 'Read back the saved task.');
    check(count(request($alice, 'GET', '/api/tasks?search=project')['tasks']) === 1, 'Search is case insensitive.');
    check(count(request($alice, 'GET', '/api/tasks?category_id=' . $school . '&status=Incomplete&search=CSC')['tasks']) === 1, 'Filters can be combined.');
    check(count(request($alice, 'GET', '/api/tasks?status=Completed')['tasks']) === 1, 'Status filtering works.');
    check(count(request($alice, 'GET', '/api/tasks?limit=1&page=2')['tasks']) === 1, 'Pagination returns the requested page.');
    check(count(request($alice, 'GET', '/api/tasks?search=%25')['tasks']) === 0, 'A percent sign is searched literally.');
    check(count(request($alice, 'GET', '/api/tasks?search=' . urlencode("' OR 1=1 --"))['tasks']) === 0, 'SQL-looking text stays data.');
    request($alice, 'GET', '/api/tasks?page=0', null, 422);
    request($alice, 'GET', '/api/tasks?limit=101', null, 422);
    request($alice, 'GET', '/api/tasks?search[]=bad', null, 422);
    $editedTask = request($alice, 'PUT', "/api/tasks/$taskId", ['title' => 'Revised project', 'description' => 'Prepare M2', 'category_id' => $work, 'status' => 'Completed'])['task'];
    check($editedTask['description'] === 'Prepare M2' && $editedTask['category_id'] === $work, 'Task editing persists changes.');
    check(request($alice, 'PATCH', "/api/tasks/$taskId/status", ['status' => 'Incomplete'])['task']['status'] === 'Incomplete', 'Status can be toggled.');

    // Use fixed historical dates so these tests do not depend on which day they run.
    $goalData = ['goal_name' => 'Drink Water', 'target_value' => '3.00', 'frequency' => 'Daily',
        'start_date' => '2026-09-01', 'end_date' => '2026-12-31'];
    request($alice, 'POST', '/api/goals', array_replace($goalData, ['target_value' => 0]), 422);
    request($alice, 'POST', '/api/goals', array_replace($goalData, ['target_value' => '1.234']), 422);
    request($alice, 'POST', '/api/goals', array_replace($goalData, ['start_date' => '2026-02-30']), 422);
    request($alice, 'POST', '/api/goals', array_replace($goalData, ['end_date' => '2026-01-01']), 422);
    request($alice, 'POST', '/api/goals', array_replace($goalData, ['frequency' => 'Monthly']), 422);
    $goal = request($alice, 'POST', '/api/goals', $goalData, 201)['goal'];
    $goalId = $goal['goal_id'];
    check(request($alice, 'GET', "/api/goals/$goalId")['goal']['status'] === 'Active', 'Goals start active.');
    $goalData['description'] = 'Liters per day';
    check(request($alice, 'PUT', "/api/goals/$goalId", $goalData)['goal']['description'] === 'Liters per day', 'Goal editing works.');
    request($alice, 'POST', "/api/goals/$goalId/progress", ['progress_value' => '2.5', 'completion_date' => '2026-09-29'], 201);
    request($alice, 'POST', "/api/goals/$goalId/progress", ['progress_value' => 3, 'completion_date' => '2026-09-28'], 201);
    $daily = request($alice, 'GET', "/api/goals/$goalId/progress?date=2026-09-29");
    check($daily['percentage'] === 83.33 && count($daily['entries']) === 1, 'Daily calculation excludes other dates: 2.5/3 = 83.33%.');
    check(request($alice, 'GET', "/api/goals/$goalId/progress?date=2026-09-30")['total_progress'] == 0, 'Empty periods total zero.');
    request($alice, 'POST', "/api/goals/$goalId/progress", ['progress_value' => -1, 'completion_date' => '2026-09-29'], 422);
    request($alice, 'POST', "/api/goals/$goalId/progress", ['progress_value' => 1, 'completion_date' => '2026-08-31'], 422);
    request($alice, 'POST', "/api/goals/$goalId/progress", ['progress_value' => 1, 'completion_date' => '2099-01-01'], 422);
    request($alice, 'PUT', "/api/goals/$goalId", array_replace($goalData, ['start_date' => '2026-09-29']), 422);
    request($alice, 'PUT', "/api/goals/$goalId", array_replace($goalData, ['status' => 'Inactive']));
    request($alice, 'POST', "/api/goals/$goalId/progress", ['progress_value' => 1, 'completion_date' => '2026-09-29'], 422);
    request($alice, 'PUT', "/api/goals/$goalId", $goalData);

    $weeklyData = array_replace($goalData, ['goal_name' => 'Workout', 'target_value' => 5, 'frequency' => 'Weekly']);
    $weeklyId = request($alice, 'POST', '/api/goals', $weeklyData, 201)['goal']['goal_id'];
    foreach (['2026-09-28', '2026-09-29', '2026-10-01', '2026-10-02', '2026-10-05'] as $date) {
        request($alice, 'POST', "/api/goals/$weeklyId/progress", ['progress_value' => 1, 'completion_date' => $date], 201);
    }
    $weekly = request($alice, 'GET', "/api/goals/$weeklyId/progress?date=2026-10-04");
    check($weekly['percentage'] == 80 && $weekly['total_progress'] == 4, 'Weekly calculation excludes the next week: 4/5 = 80%.');
    check($weekly['period_start'] === '2026-09-28' && $weekly['period_end'] === '2026-10-04', 'Weeks run Monday through Sunday.');
    check(count(request($alice, 'GET', '/api/goals')['goals']) === 2, 'Goal listing contains the owned goals.');
    $dashboard = request($alice, 'GET', '/api/dashboard');
    check($dashboard === ['total_tasks' => 3, 'completed_tasks' => 1, 'incomplete_tasks' => 2, 'total_goals' => 2, 'active_goals' => 2], 'Dashboard counts match stored records.');

    request($bob, 'GET', '/api/me');
    $bobEmail = "bob-$suffix@example.com";
    $bobUser = request($bob, 'POST', '/api/register', ['username' => 'Bob', 'email' => $bobEmail, 'password' => $password], 201);
    $createdUserIds[] = $bobUser['user']['user_id'];
    request($bob, 'POST', '/api/login', ['email' => $bobEmail, 'password' => $password]);
    check(request($bob, 'GET', '/api/tasks')['tasks'] === [], 'Bob cannot list Alice tasks.');
    check(request($bob, 'GET', '/api/goals')['goals'] === [], 'Bob cannot list Alice goals.');
    check(request($bob, 'GET', '/api/dashboard')['total_tasks'] === 0, 'Dashboard is scoped to its user.');
    request($bob, 'GET', "/api/tasks/$taskId", null, 404);
    request($bob, 'PUT', "/api/tasks/$taskId", ['title' => 'Stolen'], 404);
    request($bob, 'PATCH', "/api/tasks/$taskId/status", ['status' => 'Completed'], 404);
    request($bob, 'DELETE', "/api/tasks/$taskId", null, 404);
    request($bob, 'GET', "/api/goals/$goalId", null, 404);
    request($bob, 'PUT', "/api/goals/$goalId", $goalData, 404);
    request($bob, 'DELETE', "/api/goals/$goalId", null, 404);
    request($bob, 'GET', "/api/goals/$goalId/progress", null, 404);
    request($bob, 'POST', "/api/goals/$goalId/progress", ['progress_value' => 1, 'completion_date' => '2026-09-29'], 404);

    request($alice, 'GET', '/api/unknown', null, 404);
    request($alice, 'POST', '/api/dashboard', [], 405);
    request($alice, 'GET', '/.env', null, 404);
    request($alice, 'GET', '/database/schema.sql', null, 404);
    request($alice, 'POST', '/api/tasks', ['title' => 'Missing CSRF'], 403, false);
    request($alice, 'DELETE', "/api/tasks/$taskId");
    request($alice, 'GET', "/api/tasks/$taskId", null, 404);
    request($alice, 'DELETE', "/api/goals/$goalId");
    request($alice, 'GET', "/api/goals/$goalId", null, 404);
    $statement = $pdo->prepare('SELECT COUNT(*) FROM goal_progress WHERE goal_id = :id');
    $statement->execute(['id' => $goalId]);
    check((int) $statement->fetchColumn() === 0, 'Deleting a goal cascades to its progress records.');
    request($alice, 'POST', '/api/logout');
    request($alice, 'GET', '/api/dashboard', null, 401);
    check(request($alice, 'GET', '/api/me')['user'] === null, 'Logout clears authentication.');
    echo "PASS: $checks checks using real HTTP requests and task_manager_test.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . "\n");
    fwrite(STDERR, "Server log: $logFile\n");
    $failed = true;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($pdo instanceof PDO) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Delete only users created by this run; foreign keys remove their tasks, goals, and progress.
        foreach ($createdUserIds as $userId) {
            $statement = $pdo->prepare('DELETE FROM users WHERE user_id = :id');
            $statement->execute(['id' => $userId]);
        }
    }
    foreach ($clients as $client) {
        unlink($client['cookie']);
    }
    if (!isset($failed)) {
        unlink($logFile);
    }
}
exit(isset($failed) ? 1 : 0);
