-- BC (Barcelona Confort, PrestaShop 1.7.8.11): historic newsletter-source audit.
-- READ ONLY: one result set; no customer IDs, email strings, IP values,
-- names, dates, passwords or tokens are returned. Entire shop-1 population.
-- Confirm _DB_PREFIX_ = bc_, that tables bc_customer AND
-- bc_emailsubscription exist, and that shop ID 1 is the intended BC shop.
--
-- "active_indicator" is source subscription status, NOT guaranteed valid
-- consent to abandoned-cart emails. With-date and with-IP only describe
-- evidence fields; they do not certify notice wording, checkbox defaults,
-- consent purpose, Salesforce unsubscribes, complaint or suppression.
-- Distinct emails are counted ONLY within each source, NEVER sum them
-- across sources to claim unique people (an email may occur in both).
--
-- If bc_emailsubscription does not exist, STOP: do not create a table.
-- Run the source-only fallback instead, after checking the correct prefix.

SELECT
    'bc_customer' AS source_object,
    COUNT(*) AS total_rows_shop_1,
    COALESCE(SUM(newsletter = 1), 0) AS active_indicator,
    COALESCE(SUM(newsletter = 1 AND newsletter_date_add IS NOT NULL
                 AND CAST(newsletter_date_add AS CHAR) <> '0000-00-00 00:00:00'), 0) AS active_with_date,
    COALESCE(SUM(newsletter = 1 AND
                 (newsletter_date_add IS NULL
                  OR CAST(newsletter_date_add AS CHAR) = '0000-00-00 00:00:00')), 0) AS active_without_date,
    COALESCE(SUM(newsletter = 1 AND ip_registration_newsletter IS NOT NULL
                 AND TRIM(ip_registration_newsletter) <> ''), 0) AS active_with_ip_recorded,
    COALESCE(SUM(newsletter = 0), 0) AS inactive_indicator,
    COUNT(DISTINCT CASE WHEN newsletter = 1 THEN LOWER(TRIM(email)) ELSE NULL END) AS distinct_active_emails,
    COALESCE(SUM(newsletter = 1 AND is_guest = 1), 0) AS positive_guest_account_rows,
    COALESCE(SUM(newsletter = 1 AND deleted = 1), 0) AS positive_deleted_account_rows,
    COALESCE(SUM(newsletter = 0 AND newsletter_date_add IS NOT NULL), 0) AS unsubscribed_with_old_date
FROM bc_customer
WHERE id_shop = 1

UNION ALL

SELECT
    'bc_emailsubscription' AS source_object,
    COUNT(*) AS total_rows_shop_1,
    COALESCE(SUM(active = 1), 0) AS active_indicator,
    COALESCE(SUM(active = 1 AND newsletter_date_add IS NOT NULL
                 AND CAST(newsletter_date_add AS CHAR) <> '0000-00-00 00:00:00'), 0) AS active_with_date,
    COALESCE(SUM(active = 1 AND
                 (newsletter_date_add IS NULL
                  OR CAST(newsletter_date_add AS CHAR) = '0000-00-00 00:00:00')), 0) AS active_without_date,
    COALESCE(SUM(active = 1 AND ip_registration_newsletter IS NOT NULL
                 AND TRIM(ip_registration_newsletter) <> ''), 0) AS active_with_ip_recorded,
    COALESCE(SUM(active = 0), 0) AS inactive_indicator,
    COUNT(DISTINCT CASE WHEN active = 1 THEN LOWER(TRIM(email)) ELSE NULL END) AS distinct_active_emails,
    NULL AS positive_guest_account_rows,
    NULL AS positive_deleted_account_rows,
    COALESCE(SUM(active = 0 AND newsletter_date_add IS NOT NULL), 0) AS unsubscribed_with_old_date
FROM bc_emailsubscription
WHERE id_shop = 1;
