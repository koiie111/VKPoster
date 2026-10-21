-- Isolated test fixture only (Compose test profile), never mounted in production.
CREATE DATABASE IF NOT EXISTS app_test;
GRANT ALL ON app_test.* TO 'app'@'%';
CREATE DATABASE IF NOT EXISTS app_restore_stage19;
CREATE USER 'stage19_restore'@'%' IDENTIFIED BY 'isolated-fixture-only';
GRANT ALL ON `app\_restore\_stage19`.* TO 'stage19_restore'@'%';
CREATE USER 'stage19_wildcard_restore'@'%' IDENTIFIED BY 'isolated-fixture-only';
GRANT ALL ON app_restore_stage19.* TO 'stage19_wildcard_restore'@'%';
CREATE DATABASE IF NOT EXISTS app_check_stage19;
GRANT ALL ON app_check_stage19.* TO 'app'@'%';
