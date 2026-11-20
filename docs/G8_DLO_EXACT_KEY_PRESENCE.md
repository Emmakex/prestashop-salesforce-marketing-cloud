# G8 — comprobación real de duplicados en Data Cloud (20/11/2026)

## Corte terminado en código

Se incorpora `GrowthG8DloPresenceReader::inspect`, lector de **presencia de registro exacto en DLO** mediante **Salesforce Data Cloud Query API V2**. Fuente oficial: [Request Data With Query V2](https://developer.salesforce.com/docs/data/data-cloud-query-guide/references/data-cloud-query-api-reference/c360a-api-query-v2.html).

Usa **POST /api/v2/query solo para ejecutar un SELECT SQL**, nunca Ingestion API ni campañas. Selecciona únicamente la columna de clave de origen, con `WHERE` exacto y `LIMIT 2`. Construye por código la clave `<namespace>:<shop_id>:customer|order:<id>`; los identificadores SQL se validan con caracteres permitidos, sin SQL arbitrario, y el host debe ser HTTPS real bajo `c360a.salesforce.com`. El token de Data Cloud es recibido en memoria del vault existente, nunca almacenado ni mostrado.

Devuelve **exclusivamente** una de estas respuestas:
- `present`: respuesta HTTP 200, consulta completada (`done=true`), al menos una fila cuya clave coincide exactamente; **no reenviar**.
- `absent_confirmed`: respuesta HTTP 200, consulta completada, `rowCount=0`, `data=[]`, sin batch adicional. **No equivale por sí solo a permiso de envío**: debe combinarse con la reserva única CAS del ledger y las comprobaciones del contacto.
- `unknown`: cualquier fallo de red, permiso, SQL, esquema, token, servidor, respuesta mal formada, clave diferente o batch incompleto. **Bloquear envío y no reintentar ciegamente.**

Se limita la respuesta a 64 KiB, HTTPS/TLS verificado, sin seguir redirects, timeouts acotados y sin registrar SQL/JSON/PII. Los tests cubren presencia, ausencia, respuestas parciales, paginación, errores 401/500, URL maliciosa, SQL injection, faltas de columnas y excepciones privadas. Funciona con la callback `$recordDloStatus` ya prevista en el coordinador G8 (PR #162).

## Requisitos para enlazar con BC y DO

**No adivinar API Names.** Antes de realizar una consulta a Salesforce con el token de la tienda, obtener por metadata read-only el **API Name real del DLO y del campo de clave** para ambos objetos, en su espacio de datos. En capturas BC aparecen los nombres visibles `PST-PSCustomer` y `PST-PSOrder`, pero no está acreditado que esos nombres sean SQL API Names; no reutilizarlos como tal ni asumir que la columna visible `source_key` sea `source_key__c`.

El siguiente adaptador debe tomar estos API Names desde una vinculación protegida por tienda, comprobar que corresponden al origen de ingesta aprobado (`GrowthShopSourceBinding`) y reutilizar el token cifrado existente (`GrowthShopCredentialVault`). Solo entonces podrá crear callbacks de consulta `recordDloStatus` y `customerDloVerified` del G8, con prueba no destructiva y sin exponer registros.

**Estado operativo:** lector implementado y certificado solo con HTTP sintético, **NO conectado al cron ni llamado en BC/DO**. La instalación BC 0.9.4 sigue sin envíos automáticos; el PR anterior 0.9.5 aporta el cron de inspección de staging, no un sender. No crear tablas ni copiar perfiles del CRM. La ingesta y DMO del cliente 6031 y pedido 612 ya están verificados y nunca deben reenviarse.

## Último camino de entrega

1. Certificar nombres SQL/columnas exactos DLO de PSCustomer/PSOrder mediante metadatos read-only; enlazar consulta por tienda a G8 con pruebas positivas/negativas.
2. Integrar reserva atómica de las tablas G3 existentes y segunda lectura nativa con bloqueos por bajas y cambios de pedido; la API Query jamás reemplaza el ledger.
3. Ensayar evento nuevo staging de extremo a extremo: 202 → `reconcile`, DLO confirmado → finalización, timeout sin replay; conectar cron únicamente con autorización expresa y rollback.
