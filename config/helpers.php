<?php
// Send one JSON response and stop processing this request.
function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR);
    exit;
}

// Throw a controlled error; index.php turns it into JSON and rolls back open transactions.
function requestError(string $message, int $status = 422): never
{
    throw new RuntimeException($message, $status);
}

// Accept only JSON objects, preventing arrays or malformed JSON from becoming request fields.
function readJson(): array
{
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        requestError('Send an application/json request body.', 415);
    }
    // Read at most 64 KiB plus one byte so oversized requests can be rejected.
    $body = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($body) > 65536) {
        requestError('Request body is too large.', 413);
    }
    try {
        $object = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        requestError('Request body must contain valid JSON.', 400);
    }
    if (!is_object($object)) {
        requestError('Request body must be a JSON object.', 400);
    }
    return (array) $object;
}

// Validate text before calling string functions, so arrays and numbers give useful errors.
function textField(array $data, string $name, int $maximum, bool $required = true): string
{
    $value = $data[$name] ?? '';
    if (!is_string($value)) {
        requestError("$name must be text.");
    }
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value) > $maximum) {
        requestError("$name is required and must be at most $maximum characters.");
    }
    return $value;
}

// Reject unknown fields so misspellings and submitted ownership fields are not silently ignored.
function allowedFields(array $data, array $allowed): void
{
    foreach (array_keys($data) as $name) {
        if (!in_array($name, $allowed, true)) {
            requestError("Unknown field: $name.");
        }
    }
}

// Check positive integer IDs and page numbers without accepting decimals or arrays.
function positiveInteger(mixed $value, string $name, int $maximum = 2147483647): int
{
    if ((!is_string($value) && !is_int($value)) ||
        !preg_match('/^[1-9][0-9]*$/D', (string) $value) || (float) $value > $maximum) {
        requestError("$name must be a positive integer up to $maximum.");
    }
    return (int) $value;
}

// Only the documented status/frequency choices may be stored.
function choiceField(mixed $value, string $name, array $choices): string
{
    if (!is_string($value) || !in_array($value, $choices, true)) {
        requestError("$name must be one of: " . implode(', ', $choices) . '.');
    }
    return $value;
}

// PostgreSQL NUMERIC(10,2) allows eight integer digits and two decimal places.
function positiveDecimal(mixed $value, string $name): string
{
    if ((!is_int($value) && !is_float($value) && !is_string($value)) ||
        !preg_match('/^[0-9]{1,8}(\.[0-9]{1,2})?$/D', (string) $value) || (float) $value <= 0) {
        requestError("$name must be positive, at most 99999999.99, with at most two decimal places.");
    }
    return (string) $value;
}

// Round-trip the date to reject impossible dates such as February 30.
function dateField(mixed $value, string $name): string
{
    if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
        requestError("$name must be a valid YYYY-MM-DD date.");
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value || substr($value, 0, 4) === '0000') {
        requestError("$name must be a valid YYYY-MM-DD date.");
    }
    return $value;
}

// Lists return a page of up to 100 rows. Cap page numbers to keep offsets reasonable.
function pagination(): array
{
    return [positiveInteger($_GET['page'] ?? 1, 'page', 1000000),
        positiveInteger($_GET['limit'] ?? 20, 'limit', 100)];
}

// A server-side session remembers who logged in; never trust a user_id sent by a client.
function authenticatedUserId(): int
{
    if (!isset($_SESSION['user_id'])) {
        requestError('Login is required.', 401);
    }
    return (int) $_SESSION['user_id'];
}
