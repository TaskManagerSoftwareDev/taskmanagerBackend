-- Shared categories only. Run seed.php for demo accounts with PHP-hashed passwords and sample records.
INSERT INTO categories (category_name) VALUES ('School'), ('Work'), ('Personal')
ON CONFLICT (category_name) DO NOTHING;
