-- Single-user auth table for Keepsake. Only one row is ever expected to
-- exist (Kathryn only — no registration flow, per the brief), but this is
-- a real table rather than config-file credentials so the app can outgrow
-- config-file auth cleanly if that's ever needed later, and so future
-- phases (see PLAN.md Phase 1 note) have somewhere to hang sessions/audit
-- fields if wanted.
--
-- Seed with: php scripts/seed_user.php <username> <password>

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY users_username_unique (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
