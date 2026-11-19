# Pre-beta 0.9.0 — Back Office para cliente

**19/11/2026 · Autor ALFABH · Marketing Cloud Growth + Data Cloud exclusivamente**

Esta iteración **no amplía el motor de integración**: convierte la pantalla de configuración técnica G7 en una experiencia administrable por el cliente, sin alterar ni borrar la evidencia de etapas anteriores.

## Interfaz visible

1. Cabecera única con producto y versión pre-beta; estado de modo de pruebas, identidad de tienda y existencia de credenciales **en el servidor PHP**.
2. Formulario único para seleccionar **un cliente y un pedido del mismo cliente**. Validación local explícita, sin enviar datos. Los IDs no se guardan ni aparecen en mensajes de resultado.
3. Sección plegada para el **envío real individual**: un cliente por autorización expresa y un pedido después de verificar primero el cliente en Data Cloud. Mantiene los límites de un solo intento, 202 pendiente de DLO, detección de resultado incierto y ausencia de campañas.
4. Identidad y configuración de tienda plegadas por defecto cuando ya están configuradas; no se vuelve a solicitar una decisión aprobada.
5. Si una instalación antigua conserva la captura local activa, aparece únicamente **Detener captura**, para permitir salir de ese estado sin mostrar un asistente de DDL.

## Eliminado de la UI ordinaria

Se retiran las dos alertas repetitivas, el inventario SQL de preflight y los formularios G7 de captura de staging, lectura nativa y migración/rollback de outbox. **No se ejecutan ni son accesibles a través de POST de configuración los handlers de DDL/armado antiguos**. Las clases, los registros y la documentación histórica permanecen para auditoría, pruebas y reversibilidad: no se borran las tablas existentes.

## Alcance y salvaguardas

- Actualización `0.8.9 → 0.9.0` y `0.7.1 → 0.9.0`: mismo nombre `pssalesforcebridge`; modo Test y captura local OFF; sin DDL, backfill, purga ni envíos automáticos.
- Piloto real individual de ingesta disponible solo para **ABH**; no debe confundirse con sincronización masiva ni con autorización de marketing. Otros comercios requerirán configurar un conector Data Cloud propio antes de habilitar ingestión.
- Credenciales no expuestas ni almacenadas en el Back Office. **Detectadas** no significa conexión OAuth validada desde el servidor.
- Consentimiento para campañas Growth se comprueba de forma independiente; `newsletter` de PrestaShop no lo certifica.
- La verificación HTTP 202 exige **comprobar DLO/DMO en Salesforce**; no equivale a ingreso confirmado.
- CSS encapsulado, diseño responsive, foco de teclado visible, labels, summary/details accesibles y estado de pruebas persistente saneado, sin PII.

## Aceptación de cliente

1. Instalar el ZIP CI de `0.9.0` en **staging PrestaShop 9.1.5**, no desinstalar el módulo.
2. Comprobar visualmente escritorio/móvil, estado Test, configuración de tienda y ausencia de las antiguas pantallas G7.
3. Pulsar **Validar datos sin enviar** con los IDs del cliente y su pedido de prueba autorizados.
4. Verificar que los botones de envío no se habilitan si falta OAuth servidor o la tienda/identidad no está lista.
5. Solo tras aprobación, ejecutar **un envío individual** y conciliar en DLO/DMO; no pulsar reintento ante timeout.
6. Tras UAT y respaldo existente, actualizar BC producción 0.7.1 en Test y repetir el 1+1 autorizado.

**No se declara producto completo**: quedan pendientes el worker automático G3, conciliación operativa y los Flows de Growth/carritos abandonados.
