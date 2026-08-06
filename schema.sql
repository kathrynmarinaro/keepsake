-- Keepsake — schema
-- Load with:  mysql -u root keepsake < schema.sql
--
-- MySQL/MariaDB is the production target: InnoDB, utf8mb4, every DATETIME
-- default is CURRENT_TIMESTAMP so the server clock is the only clock. Note
-- that tools/test-harness.php translates this file into SQLite for the test
-- run, because the build environment has no MySQL. That translation is a
-- TEST convenience and proves nothing about MySQL — it is not a second
-- supported backend, and this file must stay written for MySQL. See
-- tools/test-harness.php's own header before editing this file: never make a
-- construct easier for the translator by weakening what's written here —
-- teach the translator instead.
--
-- Single schema file, no migrations directory: matching the rest of the
-- suite (Grocery, Personal CRM, Inspiration Board), which each ship one
-- schema.sql with no migration runner. Every CREATE TABLE is IF NOT EXISTS
-- so re-applying this file is safe.


-- ========================================================== AUTH ============

-- ----------------------------------------------------------------- users

-- One row per allowed login. Unlike the sibling apps (a single password_hash
-- living in config.php), Keepsake keeps a real table with a username —
-- Phase 0's own decision, kept deliberately through this pass's
-- reconciliation rather than replaced (see lib/auth.php for the reasoning).
-- Only one row is ever really expected to exist (Kathryn only — no
-- registration flow, per the brief), but a table costs nothing extra for a
-- single-user app and leaves room to outgrow it cleanly if that's ever
-- needed.
--
-- Seed with: php tools/seed_user.php <username> <password>
CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username       VARCHAR(190) NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  -- Serves the login query and the seed script's upsert:
  --   SELECT id, username, password_hash FROM users WHERE username = ?
  --   INSERT ... ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)
  UNIQUE KEY users_username_unique (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
