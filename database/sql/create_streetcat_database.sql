-- Run as a MySQL administrator. This file does not migrate/delete/copy existing data.
CREATE DATABASE IF NOT EXISTS streetcat
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create a dedicated user through your secret-management process, then grant ONLY
-- streetcat.* access. Example (replace host and username appropriately):
-- GRANT SELECT, INSERT, UPDATE, DELETE ON streetcat.* TO 'streetcat'@'app-host';
-- Use a separate migration credential with DDL rights for project:migrate.
-- Never grant this runtime account access to another project's database.
