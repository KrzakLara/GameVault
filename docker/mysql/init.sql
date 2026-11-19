-- Separate database for the automated test suite so tests never touch demo data.
CREATE DATABASE IF NOT EXISTS gamevault_test;
GRANT ALL PRIVILEGES ON gamevault_test.* TO 'gamevault'@'%';
