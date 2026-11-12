-- BC (PrestaShop 1.7.x): discover potential consent-source schemas.
-- READ ONLY. Outputs only table/column names and SQL data types.
-- NO customer data, no email addresses, no tokens, no changes.
-- Uses DATABASE() so no hard-coded production DB name.
-- A table or column named consent does NOT itself prove legal permission.
SELECT TABLE_NAME AS table_name,
       COLUMN_NAME AS column_name,
       DATA_TYPE AS data_type
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
    (TABLE_NAME = 'bc_customer'
      AND COLUMN_NAME IN (
        'newsletter', 'optin', 'newsletter_date_add', 'date_add',
        'date_upd', 'is_guest', 'active', 'deleted'
      ))
    OR LOWER(TABLE_NAME) REGEXP 'gdpr|consent|newsletter|subscribe|optin|privacy'
    OR LOWER(COLUMN_NAME) REGEXP 'gdpr|consent|newsletter|subscribe|unsubscribe|optin|privacy'
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;
