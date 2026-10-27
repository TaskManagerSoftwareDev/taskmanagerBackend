<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require dirname(__DIR__) . '/config/helpers.php';
$pdo = null;
try {
    require dirname(__DIR__) . '/config/database.php';
    foreach (['User', 'Task', 'Category', 'Goal', 'GoalProgress'] as $model) {
        require dirname(__DIR__) . "/models/$model.php";
    }
    foreach (['AuthController', 'TaskController', 'GoalController'] as $controller) {
        require dirname(__DIR__) . "/controllers/$controller.php";
    }

    // HttpOnly keeps JavaScript from reading the cookie. Strict mode rejects invented session IDs.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('task_manager_session');
    session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => getenv('SESSION_SECURE') === '1']);
    if (!session_start()) {
        throw new RuntimeException('Session storage failed.');
    }
    if (!isset($_SESSION['csrf_token'])) {
        // random_bytes creates an unpredictable value; bin2hex makes it safe to send as text.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'];
    // The table lists supported methods. Routes with numeric IDs are matched just below it.
    $routes = ['/api/register' => ['POST'], '/api/login' => ['POST'], '/api/logout' => ['POST'],
        '/api/me' => ['GET'], '/api/categories' => ['GET'], '/api/tasks' => ['GET', 'POST'],
        '/api/goals' => ['GET', 'POST'], '/api/dashboard' => ['GET']];
    $id = null;
    $route = $path;
    if (preg_match('#^/api/(tasks|goals)/([1-9][0-9]*)(/(status|progress))?$#D', $path, $matches)) {
        $id = positiveInteger($matches[2], 'id');
        $route = '/api/' . $matches[1] . '/{id}' . ($matches[3] ?? '');
    }
    $routes['/api/tasks/{id}'] = ['GET', 'PUT', 'DELETE'];
    $routes['/api/tasks/{id}/status'] = ['PATCH'];
    $routes['/api/goals/{id}'] = ['GET', 'PUT', 'DELETE'];
    $routes['/api/goals/{id}/progress'] = ['GET', 'POST'];
    if (!isset($routes[$route])) {
        requestError('Route not found.', 404);
    }
    if (!in_array($method, $routes[$route], true)) {
        header('Allow: ' . implode(', ', $routes[$route]));
        requestError('Method not allowed.', 405);
    }

    // /me is public so a new client can obtain a token before login or registration.
    $publicRoute = in_array($route, ['/api/register', '/api/login', '/api/me'], true);
    $userId = $publicRoute ? null : authenticatedUserId();
    if ($method !== 'GET') {
        $submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        // The client must copy the token from /api/me into this header, including on login.
        if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
            requestError('Invalid or missing CSRF token. Get a token from GET /api/me.', 403);
        }
    }
    $data = in_array($method, ['POST', 'PUT', 'PATCH'], true) && $route !== '/api/logout' ? readJson() : [];
    $pdo = connectDatabase();
    $categories = new Category($pdo);
    $tasks = new Task($pdo);
    $goals = new Goal($pdo);
    $authController = new AuthController(new User($pdo));
    $taskController = new TaskController($tasks, $categories);
    $goalController = new GoalController($pdo, $goals, new GoalProgress($pdo));

    // Plain conditionals keep the router easy to follow without a routing library.
    if ($route === '/api/register') {
        respond($authController->register($data), 201);
    }
    if ($route === '/api/login') {
        respond($authController->login($data));
    }
    if ($route === '/api/logout') {
        respond($authController->logout());
    }
    if ($route === '/api/me') {
        respond($authController->me());
    }
    if ($route === '/api/categories') {
        respond(['categories' => $categories->getCategories()]);
    }
    if ($route === '/api/dashboard') {
        respond($tasks->statistics($userId) + $goals->statistics($userId));
    }
    if ($route === '/api/tasks') {
        respond($method === 'GET' ? $taskController->index($userId) : $taskController->create($userId, $data), $method === 'POST' ? 201 : 200);
    }
    if ($route === '/api/tasks/{id}') {
        if ($method === 'GET') {
            respond($taskController->show($id, $userId));
        }
        if ($method === 'PUT') {
            respond($taskController->update($id, $userId, $data));
        }
        respond($taskController->delete($id, $userId));
    }
    if ($route === '/api/tasks/{id}/status') {
        respond($taskController->status($id, $userId, $data));
    }
    if ($route === '/api/goals') {
        respond($method === 'GET' ? $goalController->index($userId) : $goalController->create($userId, $data), $method === 'POST' ? 201 : 200);
    }
    if ($route === '/api/goals/{id}') {
        if ($method === 'GET') {
            respond($goalController->show($id, $userId));
        }
        if ($method === 'PUT') {
            respond($goalController->update($id, $userId, $data));
        }
        respond($goalController->delete($id, $userId));
    }
    if ($method === 'GET') {
        respond($goalController->getProgress($id, $userId));
    }
    respond($goalController->recordProgress($id, $userId, $data), 201);
} catch (Throwable $exception) {
    // Roll back unfinished work before returning an error. Technical details stay in server logs.
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($exception instanceof RuntimeException && !($exception instanceof PDOException) &&
        $exception->getCode() >= 400 && $exception->getCode() <= 499) {
        respond(['error' => $exception->getMessage()], $exception->getCode());
    }
    error_log((string) $exception);
    respond(['error' => 'The server could not complete this request. Check the server log.'], 500);
}
