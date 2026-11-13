-- Barcelona Confort (BC) · Inventario cruzado de newsletters (solo lectura)
-- Versión: 2026-11-13. MySQL 5.7/8.0, shop 1, prefijo bc_.
--
-- Comprueba primero que existen bc_customer y bc_emailsubscription.
-- No devuelve correos, IDs, IP, nombres, fechas individuales ni tokens.
-- Este diagnóstico usa LOWER(TRIM(email)) solo DENTRO del SQL como clave
-- temporal de comparación; no fusiona ni modifica fichas de PrestaShop.
-- NO confirma consentimiento legal, la entregabilidad ni bajas de SFMC.
-- La columna customer_newsletter_zero NO equivale a baja demostrada:
-- incluye gente que nunca se suscribió.
--
-- UNA SOLA CONSULTA / UNA SOLA FILA de recuentos agregados para phpMyAdmin.

SELECT
  COUNT(*) AS emails_distintos_ambas_fuentes,
  COALESCE(SUM(customer_newsletter_yes > 0), 0) AS emails_positivos_customer,
  COALESCE(SUM(standalone_newsletter_yes > 0), 0) AS emails_positivos_emailsubscription,
  COALESCE(SUM(customer_newsletter_yes > 0 OR standalone_newsletter_yes > 0), 0) AS emails_positivos_union,
  COALESCE(SUM(customer_newsletter_yes > 0 AND standalone_newsletter_yes > 0), 0) AS emails_activos_en_ambas,
  COALESCE(SUM(customer_newsletter_yes > 0 AND standalone_newsletter_yes = 0), 0) AS emails_activos_solo_customer,
  COALESCE(SUM(customer_newsletter_yes = 0 AND standalone_newsletter_yes > 0), 0) AS emails_activos_solo_emailsubscription,
  COALESCE(SUM(customer_newsletter_yes > 1), 0) AS emails_con_varios_customers_newsletter_1,
  COALESCE(SUM(customer_rows > 1), 0) AS emails_con_multiples_fichas_customer,
  COALESCE(SUM(standalone_rows > 1), 0) AS emails_con_multiples_fichas_newsletter,
  COALESCE(SUM(customer_newsletter_yes > 0 AND customer_newsletter_zero > 0), 0) AS emails_con_flags_customer_contradictorios,
  COALESCE(SUM(standalone_newsletter_yes > 0 AND customer_newsletter_zero > 0), 0) AS emails_activos_standalone_con_customer_0,
  COALESCE(SUM(customer_newsletter_yes > 0 AND standalone_newsletter_zero > 0), 0) AS emails_activos_customer_con_standalone_0,
  COALESCE(SUM(customer_deleted_yes > 0), 0) AS emails_newsletter_con_cliente_eliminado,
  COALESCE(SUM(customer_inactive_yes > 0), 0) AS emails_newsletter_con_cuenta_inactiva,
  COALESCE(SUM(customer_guest_yes > 0), 0) AS emails_newsletter_con_invitado,
  COALESCE(SUM(GREATEST(
     customer_newsletter_yes + standalone_newsletter_yes - 1, 0
  )), 0) AS registros_positivos_adicionales_por_email
FROM (
  SELECT
    email_key,
    SUM(customer_rows) AS customer_rows,
    SUM(standalone_rows) AS standalone_rows,
    SUM(customer_newsletter_yes) AS customer_newsletter_yes,
    SUM(customer_newsletter_zero) AS customer_newsletter_zero,
    SUM(standalone_newsletter_yes) AS standalone_newsletter_yes,
    SUM(standalone_newsletter_zero) AS standalone_newsletter_zero,
    SUM(customer_deleted_yes) AS customer_deleted_yes,
    SUM(customer_inactive_yes) AS customer_inactive_yes,
    SUM(customer_guest_yes) AS customer_guest_yes
  FROM (
    SELECT
      LOWER(TRIM(c.email)) AS email_key,
      1 AS customer_rows,
      0 AS standalone_rows,
      CASE WHEN c.newsletter = 1 THEN 1 ELSE 0 END AS customer_newsletter_yes,
      CASE WHEN c.newsletter = 0 THEN 1 ELSE 0 END AS customer_newsletter_zero,
      0 AS standalone_newsletter_yes,
      0 AS standalone_newsletter_zero,
      CASE WHEN c.newsletter = 1 AND c.deleted = 1 THEN 1 ELSE 0 END AS customer_deleted_yes,
      CASE WHEN c.newsletter = 1 AND c.active = 0 THEN 1 ELSE 0 END AS customer_inactive_yes,
      CASE WHEN c.newsletter = 1 AND c.is_guest = 1 THEN 1 ELSE 0 END AS customer_guest_yes
    FROM bc_customer AS c
    WHERE c.id_shop = 1
      AND c.email IS NOT NULL
      AND TRIM(c.email) <> ''

    UNION ALL

    SELECT
      LOWER(TRIM(n.email)) AS email_key,
      0 AS customer_rows,
      1 AS standalone_rows,
      0 AS customer_newsletter_yes,
      0 AS customer_newsletter_zero,
      CASE WHEN n.active = 1 THEN 1 ELSE 0 END AS standalone_newsletter_yes,
      CASE WHEN n.active = 0 THEN 1 ELSE 0 END AS standalone_newsletter_zero,
      0 AS customer_deleted_yes,
      0 AS customer_inactive_yes,
      0 AS customer_guest_yes
    FROM bc_emailsubscription AS n
    WHERE n.id_shop = 1
      AND n.email IS NOT NULL
      AND TRIM(n.email) <> ''
  ) AS origenes
  GROUP BY email_key
) AS por_email;
