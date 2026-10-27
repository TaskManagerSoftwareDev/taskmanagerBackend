# CSC 350 Task Manager backend

A PHP + PostgreSQL JSON backend for Zin Min Wai's Task Manager with goal tracking. There is no frontend and no framework or Composer dependency.

The implementation follows `M1-finalpolished.pdf`, especially FR-01 through FR-14, the ownership rules, and the five-table design. For M2, PostgreSQL identity columns replace MySQL `AUTO_INCREMENT`, `TIMESTAMP` replaces `DATETIME`, and `ILIKE` handles case-insensitive searching. Task categories are optional, as allowed by M1 FR-04. An end date is optional, matching the relational schema and your M2 request. Goal frequencies are `Daily` and `Weekly`; goal statuses are `Active`, `Completed`, and `Inactive`.

## Run on your Mac

Use PHP 8.2 or newer with `pdo_pgsql`, `mbstring`, and `curl` (curl is used by tests), plus a running PostgreSQL server. Check with:

```sh
php -v
php -m
psql -d postgres -c 'SELECT current_user;'
```

If PHP is missing, install it with `brew install php`. PostgreSQL can be provided by Postgres.app or Homebrew; you only need one running server.

From the project root:

```sh
cd /Users/jezzjezz/TaskManager
cp .env.example .env
```

Edit `.env`: set `DB_USER` to your PostgreSQL role and `DB_PASSWORD` to its password if required. Settings are plain `KEY=value` lines, without quotes. The application reads this file itself; you do not need to `source` it. Real environment variables override the file.

Create and initialize the development database **once**:

```sh
createdb task_manager
psql -d task_manager -v ON_ERROR_STOP=1 -f database/schema.sql
psql -d task_manager -v ON_ERROR_STOP=1 -f database/seed.sql
```

The databases and development schema were already created in this workspace during implementation. Your local `.env` is configured for the existing `jezzjezz` role. Skip creation and initialization here unless you are setting up another machine. `schema.sql` deliberately fails if tables exist; it never drops your data. `seed.sql` can be rerun safely and only adds the three default categories.

Start the API:

```sh
php -S 127.0.0.1:8000 -t public public/router.php
```

Keep that terminal open. Stop the server with **Ctrl+C**. This is PHP's local development server. Only `public/` is the web root; the router sends every path through the API and does not serve configuration or SQL files.

## Add demo data

Run these commands from the project root to seed both local databases:

```sh
php database/seed.php
DB_NAME=task_manager_test php database/seed.php
```

The script adds the M1 demo accounts `zin@email.com` and `john@email.com`. Both new accounts use the password `Student123!` for local demonstration. Zin owns three tasks and two active goals; John owns two separate tasks. The goals include three recent progress entries: daily water progress is 83.33% today, and weekly workout progress is 80% this week. Dates are based on when the seed first runs.

Passwords are hashed with PHP before insertion. Existing accounts with either demo email are skipped entirely, and their passwords and records are preserved. Rerunning the script does not duplicate their sample records. These credentials are for local demo data only. `seed.sql` remains the categories-only seed used by automated tests.

After logging in as Zin, use `/api/tasks`, `/api/goals`, and `/api/dashboard` to see the demo records. Read the goal IDs from `/api/goals`, then open `/api/goals/{id}/progress` to see today's or this week's progress.

## Try registration, login, and a task

The API uses a session cookie. `curl -c` saves cookies and `curl -b` sends them back. A CSRF token proves that a write request came from a client that read the session's token.

First get an anonymous session and token:

```sh
curl -sS -c /tmp/task-manager-cookies.txt http://127.0.0.1:8000/api/me
```

Response:

```json
{"user":null,"csrf_token":"COPY_THIS_VALUE"}
```

Replace `TOKEN_FROM_ME` below with that value. Register an account:

```sh
curl -sS -b /tmp/task-manager-cookies.txt -c /tmp/task-manager-cookies.txt \
  -H 'Content-Type: application/json' -H 'X-CSRF-Token: TOKEN_FROM_ME' \
  -d '{"username":"zin","email":"zin@example.com","password":"Student-password-123"}' \
  http://127.0.0.1:8000/api/register
```

Registration returns `201` and public user fields. It does not log you in. Login using the same token and cookie jar:

```sh
curl -sS -b /tmp/task-manager-cookies.txt -c /tmp/task-manager-cookies.txt \
  -H 'Content-Type: application/json' -H 'X-CSRF-Token: TOKEN_FROM_ME' \
  -d '{"email":"zin@example.com","password":"Student-password-123"}' \
  http://127.0.0.1:8000/api/login
```

Login returns a **new** CSRF token and replaces the session ID. Use `TOKEN_FROM_LOGIN` from this response for subsequent writes. You can also call `/api/me` again to retrieve the current token.

List categories to find their IDs:

```sh
curl -sS -b /tmp/task-manager-cookies.txt http://127.0.0.1:8000/api/categories
```

Create a task (replace `category_id` if your category IDs differ):

```sh
curl -sS -b /tmp/task-manager-cookies.txt -c /tmp/task-manager-cookies.txt \
  -H 'Content-Type: application/json' -H 'X-CSRF-Token: TOKEN_FROM_LOGIN' \
  -d '{"title":"Finish CSC 350 Project","description":"Complete M2 backend","category_id":1}' \
  http://127.0.0.1:8000/api/tasks
```

Search with combined filters:

```sh
curl -sS -b /tmp/task-manager-cookies.txt \
  'http://127.0.0.1:8000/api/tasks?search=project&category_id=1&status=Incomplete&page=1&limit=20'
```

Mark task 1 completed (use the ID from your creation response):

```sh
curl -sS -X PATCH -b /tmp/task-manager-cookies.txt \
  -H 'Content-Type: application/json' -H 'X-CSRF-Token: TOKEN_FROM_LOGIN' \
  -d '{"status":"Completed"}' http://127.0.0.1:8000/api/tasks/1/status
```

## Endpoints

All requests and responses use JSON. `POST`, `PUT`, `PATCH`, and `DELETE` require the session's `X-CSRF-Token`, including registration, login, and logout. Registration, login, and `/api/me` can be accessed before login. All other routes require login.

| Method | Endpoint | Body or query |
| --- | --- | --- |
| POST | `/api/register` | `username`, `email`, `password` |
| POST | `/api/login` | `email`, `password` |
| POST | `/api/logout` | No body |
| GET | `/api/me` | Public user fields or `null`, plus CSRF token |
| GET | `/api/categories` | Shared categories |
| GET | `/api/tasks` | Optional `search`, `category_id`, `status`, `page`, `limit` |
| POST | `/api/tasks` | Required `title`; optional `description`, `category_id`, `status` |
| GET | `/api/tasks/{id}` | One owned task |
| PUT | `/api/tasks/{id}` | Replace editable fields using the same fields as creation |
| DELETE | `/api/tasks/{id}` | No body |
| PATCH | `/api/tasks/{id}/status` | Required `status` |
| GET | `/api/goals` | Optional `page`, `limit` |
| POST | `/api/goals` | Goal fields described below |
| GET | `/api/goals/{id}` | One owned goal |
| PUT | `/api/goals/{id}` | Replace editable fields using the same fields as creation |
| DELETE | `/api/goals/{id}` | Also deletes its progress entries |
| POST | `/api/goals/{id}/progress` | `progress_value`, `completion_date` |
| GET | `/api/goals/{id}/progress` | Optional `date=YYYY-MM-DD`; defaults to today |
| GET | `/api/dashboard` | Task and goal counts for the logged-in user |

Single-item responses use `{"task":{...}}`, `{"goal":{...}}`, or `{"progress":{...}}`. Lists use `{"tasks":[...],"page":1,"limit":20}` or the equivalent `goals` key. Category lists use `{"categories":[...]}`. Deletes and logout return a `message`. Dashboard counts are returned directly, as in your request.

`PUT` replaces all editable fields: always send the required fields and any optional values you want to keep. Omitted task status defaults to `Incomplete`; omitted goal status defaults to `Active`. Omitted descriptions become empty text, and omitted category/end date becomes `null`.

Lists default to 20 records and accept `limit` from 1 to 100 and `page` from 1 to 1000000. They sort newest ID first. Empty lists return `[]`. Search treats `%` and `_` literally. Query values containing spaces should be URL encoded, for example with curl's `--get --data-urlencode`.

Creation returns `201`; successful reads, edits, and deletions return `200`. Error responses use `{"error":"Explanation"}`:

| Status | Meaning |
| --- | --- |
| 400 | Malformed JSON or body is not an object |
| 401 | Login required or incorrect login credentials |
| 403 | Missing or incorrect CSRF token |
| 404 | Unknown route, missing record, or record belongs to someone else |
| 405 | Unsupported method; the `Allow` response header lists supported methods |
| 409 | Email already registered |
| 413 | Body exceeds 64 KiB |
| 415 | Body needs `Content-Type: application/json` |
| 422 | Invalid fields, dates, values, or choices |
| 500 | Unexpected error; technical details go to the server terminal/log |

Emails are normalized to lowercase. Passwords must contain 8 to 72 bytes and no null characters. Task titles allow 255 characters, usernames 50, emails 100, goal names 100, and descriptions 20000. Unknown body fields are rejected, including client-supplied `user_id`.

## Goals and progress

Example goal body for `POST /api/goals`:

```json
{
  "goal_name": "Drink Water",
  "description": "Liters per day",
  "target_value": "3.00",
  "frequency": "Daily",
  "start_date": "2026-09-01",
  "end_date": "2026-12-31",
  "status": "Active"
}
```

Required: `goal_name`, `target_value`, `frequency`, `start_date`. Optional: `description`, `end_date`, `status`. Targets and progress amounts must be positive, at most `99999999.99`, with at most two decimal places. PostgreSQL returns `NUMERIC` values as strings to preserve their decimal precision.

For `POST /api/goals/1/progress`, a sample body is:

```json
{"progress_value":"2.50","completion_date":"2026-09-29"}
```

Read it with `GET /api/goals/1/progress?date=2026-09-29`. The response includes `period_start`, `period_end`, `entries`, `total_progress`, `target_value`, and `percentage`.

- **Daily:** add all entries on the selected date. `2.5 / 3 * 100 = 83.33%`.
- **Weekly:** add entries from Monday through Sunday containing the selected date. Four workouts against a target of five gives `80%`.
- Multiple entries on a date are allowed and added together. Totals reset for each reporting period. There is no automatic change to the goal's status when a period reaches its target; M1 describes recurring goals rather than one-time completion.
- Progress may exceed 100%. Dates must fall within the goal's duration and cannot be in the future. Only `Active` goals accept new progress. Old entries remain readable after a status change.
- The default reporting date uses `APP_TIMEZONE`, currently `America/New_York`. Reports can select any valid date, including an empty period.
- A date edit cannot exclude existing progress entries. Changing frequency recalculates the same dated history into the new daily/weekly periods; it does not erase entries.

## Learn how the files work

| File | Responsibility | How to test it |
| --- | --- | --- |
| `database/schema.sql` | Defines the five tables, relationships, checks, and indexes | Initialize an empty database; inspect it with `psql -d task_manager -c '\dt'` |
| `database/seed.sql` | Adds School, Work, Personal | `GET /api/categories` after login |
| `config/database.php` | Loads settings and creates the PDO connection | Call `/api/me`; connection failures produce a safe JSON error |
| `config/helpers.php` | Small shared functions for JSON, validation, pagination, and login checks | Send malformed JSON, empty fields, or unauthenticated requests |
| `models/User.php` | Creates users and retrieves account information | Register and log in |
| `models/Category.php` | Lists and checks shared categories | Read categories; try an invalid category ID |
| `models/Task.php` | Task CRUD, filtered queries, task counts | Create, read, edit, search, complete, delete tasks |
| `models/Goal.php` | Goal CRUD and goal counts | Create, edit, list, delete goals |
| `models/GoalProgress.php` | Inserts dated progress, reads history, calculates totals | Record amounts and request daily/weekly reports |
| `controllers/AuthController.php` | Validates account input and manages authenticated sessions | Test login/logout and inspect `/api/me` |
| `controllers/TaskController.php` | Validates task fields and delegates SQL to `Task` | Try valid and invalid task requests |
| `controllers/GoalController.php` | Validates goals and progress, including ownership and dates | Try invalid dates, inactive goals, another user's goal |
| `public/index.php` | Starts sessions, checks CSRF, routes requests, catches errors | Call the endpoint table above |
| `public/router.php` | Sends local development requests to `index.php` | Start the server with the documented command |
| `tests/run.php` | Runs real HTTP requests against a separate PostgreSQL test database | Run the command below |

`config/helpers.php` is the only extra PHP file beyond your proposed structure. It avoids copying the same validation and response functions into every controller. There is no service layer.

A task creation travels through these steps:

1. PHP receives `POST /api/tasks` in `public/index.php`.
2. The session identifies the user. The CSRF header is checked. `json_decode()` turns JSON into PHP values.
3. `TaskController` validates the fields and category.
4. `Task::createTask()` calls PDO's `prepare()` with SQL placeholders such as `:title`.
5. `execute()` sends values separately from the SQL. PostgreSQL inserts the row, checks constraints, and returns it through `RETURNING *`.
6. `fetch()` turns that row into a PHP array. `json_encode()` turns the array into the JSON response.

Other useful concepts:

- `SELECT` reads, `INSERT` creates, `UPDATE` edits, and `DELETE` removes rows.
- A foreign key links a child row to an existing parent. `ON DELETE CASCADE` removes dependent records when their parent is deleted.
- `password_hash()` stores a one-way password hash. `password_verify()` compares a supplied password with that hash; the application never decrypts passwords.
- `$_SESSION` stores login data on the server. The cookie contains its identifier. Logout removes that data and expires the cookie.
- `hash_equals()` safely compares CSRF tokens. Tokens are generated with `random_bytes()` and must stay with their session cookie.
- `beginTransaction()` and `commit()` make related SQL operations succeed together. Goal operations lock the parent row so an edit cannot race with a progress entry. Failed transactions are rolled back.
- A constructor saves the connection in `$this->pdo`, so other methods in that object can use it. `private` means only that class can directly access the property. `array|false` means a function returns either a row or `false` if none was found. `never` means a function always throws or exits.
- JSON strings are data. A future frontend should display user text with `textContent` or HTML escaping; do not insert it as raw HTML.

## Run the tests

Create the separate test database once (already done in this workspace):

```sh
createdb task_manager_test
```

Then run:

```sh
php tests/run.php
```

The script uses `.env` credentials, forces the database name to `task_manager_test`, starts its own temporary local PHP server on a free port, and initializes the test schema if absent. It does not use your development server. Each simulated browser has separate cookies. Each run registers unique accounts and deletes only those accounts and their dependent records in cleanup. It does not truncate tables or drop a database.

The suite covers registration, password hashes, duplicate emails, login/logout, task CRUD and completion, search and combined filters, pagination, goal CRUD, daily/weekly sums, date and numeric validation, inactive goals, CSRF rejection, JSON errors, invalid routes/methods, private file URLs, dashboard counts, cascading deletion, and cross-user reads and writes.

Run syntax checks with:

```sh
find config models controllers public tests -name '*.php' -exec php -l {} \;
```

Implementation verification: **120 checks passed** against real PostgreSQL, and every PHP file passed syntax checking. The tested runtime was PHP 8.5.12 with PostgreSQL 18.6; the code uses PHP 8.2-compatible syntax, but a separate PHP 8.2 runtime was not tested.

If a test fails, the script prints the error and the temporary server-log path. Connection errors usually mean PostgreSQL is stopped or the `.env` role/password does not match your server. A `could not find driver` error means PHP needs `pdo_pgsql`. If `/api/me` works but writes return `403`, check that your cookie jar and current CSRF token belong to the same session.

Keep `.env` private. Use HTTPS and `SESSION_SECURE=1` when deploying; local HTTP uses `0`. This project implements the requested course backend. Production deployment would need its own server configuration and operational review.
