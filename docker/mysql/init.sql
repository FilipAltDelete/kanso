-- The test databases live alongside the installation database in the dev stack:
-- `kanso_test` for the core's suite, `kanso_project_test` for project/'s.
CREATE DATABASE IF NOT EXISTS kanso_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON kanso_test.* TO 'kanso'@'%';
CREATE DATABASE IF NOT EXISTS kanso_project_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON kanso_project_test.* TO 'kanso'@'%';
FLUSH PRIVILEGES;
