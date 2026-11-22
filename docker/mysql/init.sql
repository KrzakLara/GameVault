-- Separate databases so automated tests never touch demo data:
--   gamevault_test  Pest suite (phpunit.xml, guarded in tests/TestCase.php)
--   gamevault_e2e   Playwright stack (docker-compose.yml profile "e2e", guarded by e2e:reset)
-- Runs only when the MySQL volume is first created; scripts/e2e.sh applies the same statements
-- idempotently to an existing volume.
CREATE DATABASE IF NOT EXISTS gamevault_test;
GRANT ALL PRIVILEGES ON gamevault_test.* TO 'gamevault'@'%';
CREATE DATABASE IF NOT EXISTS gamevault_e2e;
GRANT ALL PRIVILEGES ON gamevault_e2e.* TO 'gamevault'@'%';
