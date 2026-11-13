# BC — Cruce de newsletter histórico, deduplicación y conflictos

Estado: **diagnóstico offline/SQL de solo lectura**. 13/11/2026.

## Observaciones confirmadas del operador, BC shop 1

| Métrica | bc_customer | bc_emailsubscription |
| --- | ---: | ---: |
| Filas | 6.023 | 13 |
| Flag activo `newsletter=1` / `active=1` | 116 | 13 |
| Con fecha en los registros activos | 116 | 13 |
| Con campo IP registrado en activos | 9 | 13 |
| Distintos emails activos dentro de esa fuente | 86 | 13 |
| Flag inactivo | 5.907 | 0 |
| Registros activos guest | 54 | N/A |
| Registros activos de cliente eliminado | 0 | N/A |
| Flag inactivo con fecha no nula | **5.904** | 0 |

**Interpretación correcta:**
- Los 116 registros `newsletter=1` representan solo 86 emails únicos
  *dentro de customer*. El excedente de 30 filas no demuestra
  que existan 30 personas duplicadas: puede haber múltiples cuentas
  guest/registradas con el mismo email. No se borran ni fusionan IDs:
  pedidos, historiales y claves quedan estables.
- Los 13 emails activos de `bc_emailsubscription` pueden coincidir con
  cualquiera de los 86 positivos de `bc_customer`. La unión de emails
  positivos *está entre 86 y 99* hasta ejecutar el cruce. No sumar
  las fuentes como si fueran 129 contactos o 99 suscriptores únicos.
- `newsletter=0` incluye no suscritos y potenciales bajas: el campo
  histórico `newsletter_date_add` está relleno en **5.904 de 5.907**
  filas con flag a 0. La mera presencia de la fecha no demuestra
  consentimiento actual, ni tampoco una baja anterior.
- Los 54 registros con newsletter activa y `is_guest=1` no son 54
  emails diferentes necesariamente. No descartar invitados por estatus.
- Ni un email activo en SQL ni las IPs equivalen a autorización real
  de envío: comprobar texto de captación, finalidad, bajas, reclamaciones,
  supresiones y `All Subscribers` del BU correcto de Salesforce.

## Auditoría cruzada

Ejecutar `scripts/sql/bc_newsletter_cross_source_dedup_readonly.sql`
sobre BC real en phpMyAdmin tras comprobar prefijo `bc_` y ambas tablas.
La consulta trabaja temporalmente sobre emails normalizados (`LOWER(TRIM())`)
**solo dentro del servidor**, pero devuelve una sola fila agregada sin
ningún email/ID/IP/fecha individual. No modifica registros.

La salida calcula:
- Intersección y unión de emails con flag activo entre ambas fuentes;
- Emisiones duplicadas de flag activo dentro de customer y newsletter;
- Múltiples fichas de customer por email (no fusionar automáticamente);
- Casos con flags activos e inactivos coexistiendo para el mismo email,
  en una o entre fuentes, que precisan revisión de procedencia/estado;
- Emails marcados newsletter positiva en cuentas eliminadas/inactivas
  y la proporción de invitados;
- Número de filas positivas adicionales por email para dimensionar
  deduplicación técnica.

Los estados negativos coexistentes no son necesariamente bajas
verificadas. Se marcarán `review_required`; no se utilizará una
regla de `última fecha` sin certificar primero la procedencia UTC y
la semántica de esas fechas.

## Reglas de diseño para SFMC

1. La identidad fuente sigue siendo (merchant/instalación, tienda, ID);
   el email normalizado sirve **solo para evaluar** duplicidad y colisiones.
   No se fusionan pedidos ni personas por email sin contrato de identidad.
2. Conservar el histórico comercial cuando proceda; separar pertenencia
   a dataset analítico de elegibilidad de canal/purpose.
3. La audiencia publicitaria se deduplica por identidad Salesforce
   aprobada y `SubscriberKey` estable, con correspondencia shop/merchant.
   No reactivar `Unsubscribed` desde el flag local.
4. Se debe consultar `All Subscribers`, estado por publication list
   cuando corresponda y todas las supresiones/bajas/objeciones de Salesforce.
   **Sin adaptador real de lectura certificado, BC sigue en Test**.
5. Los carritos históricos no se enviarán retroactivamente a Journeys
   durante la carga de datos. Una recuperación exige carrito actual sin
   pedido, ventana de tiempo verificada, finalidad y autorización.
6. Este diagnóstico no autoriza ingesta Data Cloud, mensajes, activación
   de Production, backfill ni escritura de datos.
