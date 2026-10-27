<?php
class TaskController
{
    private Task $tasks;
    private Category $categories;

    // The controller validates requests; models contain the SQL.
    public function __construct(Task $tasks, Category $categories)
    {
        $this->tasks = $tasks;
        $this->categories = $categories;
    }

    // Create a task using the authenticated owner and validated fields.
    public function create(int $userId, array $data): array
    {
        return ['task' => $this->tasks->createTask($userId, $this->validate($data))];
    }

    // Validate optional filters before passing them to the SQL query.
    public function index(int $userId): array
    {
        allowedFields($_GET, ['search', 'category_id', 'status', 'page', 'limit']);
        $filters = [];
        if (isset($_GET['search'])) {
            $filters['search'] = textField($_GET, 'search', 255, false);
        }
        if (isset($_GET['category_id'])) {
            $filters['category_id'] = $this->categoryId($_GET['category_id']);
        }
        if (isset($_GET['status'])) {
            $filters['status'] = choiceField($_GET['status'], 'status', ['Incomplete', 'Completed']);
        }
        [$page, $limit] = pagination();
        return ['tasks' => $this->tasks->getTasks($userId, $filters, $page, $limit), 'page' => $page, 'limit' => $limit];
    }

    // Return the same 404 for an absent task and someone else's task to protect private IDs.
    public function show(int $taskId, int $userId): array
    {
        $task = $this->tasks->getTaskById($taskId, $userId);
        if (!$task) {
            requestError('Task not found.', 404);
        }
        return ['task' => $task];
    }

    // PUT replaces editable fields. Title is required; optional fields use creation defaults.
    public function update(int $taskId, int $userId, array $data): array
    {
        $this->show($taskId, $userId);
        $task = $this->tasks->updateTask($taskId, $userId, $this->validate($data));
        if (!$task) {
            requestError('Task not found.', 404);
        }
        return ['task' => $task];
    }

    // Return a clear message when the owned task was removed.
    public function delete(int $taskId, int $userId): array
    {
        if (!$this->tasks->deleteTask($taskId, $userId)) {
            requestError('Task not found.', 404);
        }
        return ['message' => 'Task deleted.'];
    }

    // PATCH changes only completion status.
    public function status(int $taskId, int $userId, array $data): array
    {
        allowedFields($data, ['status']);
        $status = choiceField($data['status'] ?? null, 'status', ['Incomplete', 'Completed']);
        $task = $this->tasks->updateTaskStatus($taskId, $userId, $status);
        if (!$task) {
            requestError('Task not found.', 404);
        }
        return ['task' => $task];
    }

    // A missing category means uncategorized; supplied categories must exist.
    private function categoryId(mixed $value): int
    {
        $categoryId = positiveInteger($value, 'category_id');
        if (!$this->categories->exists($categoryId)) {
            requestError('Category does not exist.');
        }
        return $categoryId;
    }

    // Whitelist fields so clients cannot assign tasks to another account.
    private function validate(array $data): array
    {
        allowedFields($data, ['title', 'description', 'category_id', 'status']);
        return ['title' => textField($data, 'title', 255),
            'description' => textField($data, 'description', 20000, false),
            'category_id' => isset($data['category_id']) ? $this->categoryId($data['category_id']) : null,
            'status' => choiceField($data['status'] ?? 'Incomplete', 'status', ['Incomplete', 'Completed'])];
    }
}
