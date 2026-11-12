-- BC / PrestaShop 1.7.8 — safe module and newsletter source preflight.
-- Execute only in the authorized BC database, read-only.
-- Returns module metadata and *only* security mode/configuration switches.
-- No emails, customer identifiers, IPs, secrets, hashes, tokens or PII.
-- Validate 'bc_' is the real table prefix before execution.

SELECT 'module' AS origin,
       m.name AS item,
       CONCAT('version=', m.version, '; active=', m.active) AS observed_value,
       'global' AS config_scope
FROM bc_module AS m
WHERE m.name IN (
  'pssalesforcebridge', 'ps_emailsubscription', 'blocknewsletter',
  'psgdpr', 'ps_dataprivacy', 'ps_customerprivacy'
)
UNION ALL
SELECT 'setting' AS origin,
       c.name AS item,
       CASE
         WHEN c.name = 'PS_SFMC_MODE' AND c.value = 'test' THEN 'test'
         WHEN c.name = 'PS_SFMC_MODE' THEN 'NOT_TEST_REVIEW'
         WHEN c.value = '1' THEN 'enabled'
         WHEN c.value = '0' THEN 'disabled'
         ELSE 'unset_or_unrecognized'
       END AS observed_value,
       CONCAT('shop_group=', COALESCE(CAST(c.id_shop_group AS CHAR), 'GLOBAL'),
              '; shop=', COALESCE(CAST(c.id_shop AS CHAR), 'GLOBAL')) AS config_scope
FROM bc_configuration AS c
WHERE c.name IN (
  'PS_SFMC_MODE',
  'NW_VERIFICATION_EMAIL',
  'NW_CONFIRMATION_EMAIL',
  'PS_CUSTOMER_OPTIN'
)
ORDER BY origin, item, config_scope;
