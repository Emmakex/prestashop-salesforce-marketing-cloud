# Cierre de beta BC — única ruta restante (20/11/2026)

## Estado confirmado (no repetir)

Piloto: **Barcelona Confort / BCABH**, fuente Salesforce existente `ABH_PrestaShop_Data_Cloud`, PrestaShop 1.7.8.11, módulo **0.9.4** en **Test**. El propietario confirmó en Salesforce **Data Explorer / default** la persistencia individual de **un PSCustomer y su PSOrder vinculado** en DLO, tras exactamente un POST manual por objeto. Las claves de origen coincidían en namespace, tienda y relación pedido→cliente. Los canarios sintéticos anteriores no cuentan como registros del piloto. No almacenar correos, nombres, payloads o credenciales en GitHub. Recibo: [issue #12](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/issues/12).

**Certificación de código en main SHA 79ef0c9b6d9002cba95fad7e7c2cea8c5b2ed5c9:** [CI 17/17](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/44561521177) y [PrestaShop runtime 1.7/8/9 3/3](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/44561521159). **No reinstalar, no repetir PSCustomer/PSOrder ni volver a configurar OAuth.** La UI 0.9.4 corrige el diagnóstico local.

El atributo `paid_flag=false` es conservador: **no demuestra impago**. No aprobar semántica de pagos ni campañas por este dato. La prueba cubre **ingesta DLO individual**, NO una sincronización operativa o masiva.

## Lo único pendiente para terminar

| Puerta | Criterio objetivo de cierre | Estado |
| --- | --- | --- |
| **DMO (G4)** | Comprobar en Data Explorer `default` y tipo **Data Model Object** los registros del piloto en **Individual** y el DMO personalizado **PST-PSOrder** (según mapeos UI de 16/11), incluida la relación entre pedido y cliente; capturas saneadas | **Por verificar** |
| **Consentimiento G6** | Confirmar suscripción Growth Marketing/Correo electrónico y Consent Check nativo de envío, ámbito de Business Unit y prueba negativa de una baja; `newsletter/optin` nunca autoriza campañas | **No certificado** |
| **G7 automatismo mínimo** | Capturar/deduplicar en staging eventos nuevos PSCustomer/PSOrder, cancelar/retener versiones obsoletas, fence final con exclusiones actuales y conciliación de 202/timeout; pruebas sin backfill y sin reintento ciego. Carritos requieren gate 60m, compra/optout recientes, consentimiento y recuperación, por separado | **No activado: código actual solo 1+1 manual** |
| **G8 beta / despliegue** | CI exact-head 17/17, runtime 1.7/8/9, upgrade reversibilidad, canario y rollback, autorización expresa del propietario para habilitar el flujo acotado | **Pendiente; Test continúa** |

**No confundir DLO con DMO ni el HTTP 202 con datos persistidos.** La ingesta 1+1 ya ha sido confirmada en DLO, pero Salesforce no tiene autorización general para campañas ni ingesta ilimitada.

## Evidencia actual sobre DMO: por qué se pide comprobación UI, no otra API

El workflow GET-only [#44561521286](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/44561521286) inspeccionó únicamente metadatos en `default`:

- **Individual:** objeto PROFILE accesible; se observó un mapeo activo a `ssot__Id__c`; **no certifica que venga de PSCustomer**.
- **SalesOrder estándar:** objeto accesible y con clave primaria, pero la consulta del endpoint de *mapeos* devolvió **HTTP 404**. **Este no es el destino del pedido de BC según la evidencia UI:** el DLO `PST-PSOrder` se mapeó al DMO personalizado homónimo `PST-PSOrder`. El 404 sobre `SalesOrder` no sirve para concluir ausencia de mapeo de BC.
- La inspección automática **no verificó** la relación activa `PST-PSOrder` → Individual ni los registros del piloto. Históricamente existen capturas del propietario de los streams y mapeos marcados LISTO; **no deshacerlos ni recrearlos** basándose solo en metadatos GET.

**Comprobación humana mínima (sin cambios):**

1. Data Cloud → Explorador de datos → `default` → selector **Objeto de modelo de datos**.
2. Seleccionar **Individual**. Filtrar por las claves adecuadas al mapeo real del DLO PSCustomer. Confirmar la existencia del registro originado por el piloto, sin compartir PII.
3. Seleccionar el DMO **`PST-PSOrder`** (tipo Objeto de modelo de datos; no confundirlo con el DLO homónimo ni con `SalesOrder`). Confirmar el pedido real y su relación con Individual. Si no aparece, inspeccionar la asignación concreta en Data Streams → `PST-PSOrder` → Mapeo.
4. Si falta, abrir los mapeos de los DLO `PST-PSCustomer` y `PST-PSOrder` **solo lectura** y capturar el destino DMO y los campos de enlace. **No remapear, borrar, recrear stream ni disparar POST.**

## Reglas para el cierre rápido

- Continuar exclusivamente **Marketing Cloud Growth + Data Cloud**, sin rutas de proveedor alternativas ni recrear credenciales, conectores o estructuras.
- No declarar funcionalidad de campañas o recuperación de carritos terminada por un smoke DLO.
- Preferir una única corrección sobre la causa observada y cerrar el gate con evidencia, sin módulos auxiliares ni nueva cola.
- Nunca reenviar los dos registros manuales: sus slots de intento ya están consumidos.
- Mantener modo Test y captura por defecto OFF, sin worker ni cron outbound hasta autorización independiente y verificaciones de consentimiento y antirreplay.
