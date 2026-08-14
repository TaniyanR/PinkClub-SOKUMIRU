SET NAMES utf8mb4;

DELETE FROM settings
WHERE setting_key IN ('sokumiru_affiliate_id', 'fanza_affiliate_id');

SET @affiliate_column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'api_credentials'
    AND COLUMN_NAME = 'affiliate_id'
);
SET @drop_affiliate_column_sql := IF(
  @affiliate_column_exists > 0,
  'ALTER TABLE api_credentials DROP COLUMN affiliate_id',
  'SELECT 1'
);
PREPARE drop_affiliate_column_statement FROM @drop_affiliate_column_sql;
EXECUTE drop_affiliate_column_statement;
DEALLOCATE PREPARE drop_affiliate_column_statement;

SET @backup_affiliate_column_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'settings_legacy_backup'
    AND COLUMN_NAME = 'affiliate_id'
);
SET @drop_backup_affiliate_column_sql := IF(
  @backup_affiliate_column_exists > 0,
  'ALTER TABLE settings_legacy_backup DROP COLUMN affiliate_id',
  'SELECT 1'
);
PREPARE drop_backup_affiliate_column_statement FROM @drop_backup_affiliate_column_sql;
EXECUTE drop_backup_affiliate_column_statement;
DEALLOCATE PREPARE drop_backup_affiliate_column_statement;

SET @backup_setting_key_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'settings_legacy_backup'
    AND COLUMN_NAME = 'setting_key'
);
SET @delete_backup_affiliate_rows_sql := IF(
  @backup_setting_key_exists > 0,
  'DELETE FROM settings_legacy_backup WHERE setting_key IN (''sokumiru_affiliate_id'', ''fanza_affiliate_id'')',
  'SELECT 1'
);
PREPARE delete_backup_affiliate_rows_statement FROM @delete_backup_affiliate_rows_sql;
EXECUTE delete_backup_affiliate_rows_statement;
DEALLOCATE PREPARE delete_backup_affiliate_rows_statement;
