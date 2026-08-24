ALTER TABLE admins ADD COLUMN email VARCHAR(254) NULL AFTER username;
ALTER TABLE admins ADD COLUMN initial_setup_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;
ALTER TABLE admins ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER initial_setup_completed;
UPDATE admins
SET initial_setup_completed = 1
WHERE initial_setup_completed = 0
  AND username <> 'admin'
  AND email IS NOT NULL
  AND email <> '';
