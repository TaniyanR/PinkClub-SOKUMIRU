SET NAMES utf8mb4;

ALTER TABLE api_credentials
  ADD COLUMN IF NOT EXISTS affiliate_id VARCHAR(255) NOT NULL DEFAULT '' AFTER api_id;

UPDATE api_credentials
SET affiliate_id = COALESCE(
  (SELECT setting_value FROM settings WHERE setting_key = 'sokumiru_affiliate_id' LIMIT 1),
  ''
)
WHERE api_type = 'items'
  AND affiliate_id = '';
