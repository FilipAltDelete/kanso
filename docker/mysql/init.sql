-- The test database lives alongside the installation database in the dev stack.
CREATE DATABASE IF NOT EXISTS kanso_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON kanso_test.* TO 'kanso'@'%';
FLUSH PRIVILEGES;
