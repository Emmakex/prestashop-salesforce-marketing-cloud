-- BC / PrestaShop: read-only cart recovery policy preflight.
-- Never export emails, customer IDs, personal info, secrets or tokens.
-- Use only in the authorized BC database. Check the prefix bc_.

SELECT
  c.name AS parametro,
  CASE
    WHEN c.name = 'PS_SFMC_MODE' THEN
      CASE WHEN LOWER(TRIM(c.value)) = 'test' THEN 'test'
           ELSE 'NOT_TEST_REVIEW' END
    WHEN c.name = 'PS_SFMC_CART_CONSENT_MODE' THEN
      CASE WHEN LOWER(TRIM(c.value)) = 'marketing_optin' THEN 'marketing_optin'
           WHEN LOWER(TRIM(c.value)) = 'known_contact'
                THEN 'known_contact_REQUIRES_LEGAL_REVIEW'
           ELSE 'UNKNOWN_POLICY_REVIEW' END
    WHEN c.name = 'PS_SFMC_CART_ABANDON_MINUTES' THEN
      CASE WHEN TRIM(c.value) REGEXP '^[0-9]{1,5}$'
           THEN CONCAT(TRIM(c.value), '_minutes')
           ELSE 'INVALID_THRESHOLD_REVIEW' END
    ELSE 'UNRECOGNIZED'
  END AS valor_seguro,
  COALESCE(CAST(c.id_shop_group AS CHAR), 'GLOBAL') AS grupo,
  COALESCE(CAST(c.id_shop AS CHAR), 'GLOBAL') AS tienda
FROM bc_configuration c
WHERE c.name IN (
  'PS_SFMC_MODE',
  'PS_SFMC_CART_CONSENT_MODE',
  'PS_SFMC_CART_ABANDON_MINUTES'
)
ORDER BY c.name, c.id_shop_group, c.id_shop;
