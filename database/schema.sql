-- Run this file inside task_manager (see README). No existing tables are dropped.
BEGIN;
CREATE TABLE users (
    user_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    username VARCHAR(50) NOT NULL CHECK (btrim(username) <> ''),
    email VARCHAR(100) UNIQUE NOT NULL CHECK (email = lower(email)),
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE categories (
    category_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    category_name VARCHAR(50) UNIQUE NOT NULL
);
-- A user owns many tasks. A task may be uncategorized, as allowed by M1 FR-04.
CREATE TABLE tasks (
    task_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    category_id INTEGER REFERENCES categories(category_id),
    title VARCHAR(255) NOT NULL CHECK (btrim(title) <> ''),
    description TEXT,
    status VARCHAR(20) NOT NULL DEFAULT 'Incomplete' CHECK (status IN ('Incomplete', 'Completed')),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE goals (
    goal_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    goal_name VARCHAR(100) NOT NULL CHECK (btrim(goal_name) <> ''),
    description TEXT,
    target_value NUMERIC(10,2) NOT NULL CHECK (target_value > 0),
    frequency VARCHAR(30) NOT NULL CHECK (frequency IN ('Daily', 'Weekly')),
    start_date DATE NOT NULL,
    end_date DATE CHECK (end_date >= start_date),
    status VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active', 'Completed', 'Inactive'))
);
-- Deleting a goal also deletes its progress records.
CREATE TABLE goal_progress (
    progress_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    goal_id INTEGER NOT NULL REFERENCES goals(goal_id) ON DELETE CASCADE,
    progress_value NUMERIC(10,2) NOT NULL CHECK (progress_value > 0),
    completion_date DATE NOT NULL
);
-- These indexes help PostgreSQL find one user's records and one goal's dated entries.
CREATE INDEX tasks_user_id_idx ON tasks(user_id);
CREATE INDEX goals_user_id_idx ON goals(user_id);
CREATE INDEX goal_progress_date_idx ON goal_progress(goal_id, completion_date);
COMMIT;
