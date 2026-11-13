# G3-01 · Prueba de posesión de clave externa y desafío de un solo uso (laboratorio)

**13/11/2026 · CI-only / sin autorización G3.** ABH/Barcelona Confort utiliza Marketing Cloud **Growth (Next) + Data 360**, **no Engagement**. El módulo instalado sigue siendo `pssalesforcebridge` 0.7.1 en `test`.

## Qué problema añade el PR #59 y cómo lo reducimos

El registro central de namespaces del [corte anterior](G3_NAMESPACE_GLOBAL_REGISTRY_CI_CANDIDATE.md) rechaza que **dos identidades de instalación distintas** reserven el mismo namespace. Pero una copia de la BD y de la configuración puede copiar también el `external_installation_id` y presentarse con **la misma identidad declarada**. El registro, por sí solo, no tiene prueba de que esa declaración procede del despliegue original.

Este ensayo añade una **prueba de posesión de clave privada**, que en la propuesta de producción debería estar custodiada por un agente/servicio independiente de los archivos y la BD clonables de PrestaShop.

## Contrato técnico probado — solo base de datos `g3_ci`

`tests/integration/G3InstallationProofSandbox.php` almacena **solo la clave pública**, su SHA-256, el namespace y el identificador de instalación. Una clave pública se incorpora mediante una operación de **registro externo** (simulada en CI con flag específico); un módulo de tienda no puede registrarla de forma autónoma. La identidad registrada en el corte #59 sigue siendo inmutable.

1. El registro comprueba `source_namespace + external_installation_id + merchant + provider` y que la clave pública fue registrada externamente.
2. Emite 32 bytes aleatorios de desafío con `random_bytes` y caducidad de 60 segundos. Guarda **SHA-256 del nonce**, no el nonce en claro. No contiene email, pedido ni datos personales.
3. El titular de la clave firma un mensaje canónico que vincula **namespace, instalación, merchant, proveedor, nonce y caducidad** (RSA 2048, SHA-256 en este ensayo).
4. El registro verifica la firma contra la clave pública previamente registrada y **consume el desafío mediante una única actualización condicional**. Una segunda comprobación, incluso concurrente, no puede consumirlo otra vez.
5. Aunque la prueba sea válida, la respuesta conserva `identity_owner_approved=false`, `schema_upload_authorized=false`, `data_360_ingestion_authorized=false` y `marketing_sends_authorized=false`.

La verificación de prueba no devuelve autorización comercial, ni habilita otro proveedor, ni modifica datos reales.

## Pruebas negativas (MySQL 8 y MariaDB 10.11)

`tests/integration/g3_installation_proof.php` usa claves RSA generadas **en memoria dentro del proceso de CI**, sin guardar privadas en GitHub, artifacts o logs, y prueba:

- Falta de clave registrada y registro realizado sin la operación externa.
- Registro repetido idempotente y sustitución de clave que exige revisión humana.
- Clon con misma identidad declarada pero **otra clave privada**: firma rechazada.
- Namespace, proveedor, UUID o caducidad manipulados: rechazo.
- Respuesta válida aceptada **una sola vez**; replay y nonce no emitido: rechazo.
- Caducidad real desde la perspectiva de la BD, con un desafío de CI caducado deliberadamente.
- Segunda instalación independiente sin clave que no puede aprovechar el registro de ABH.
- Imposibilidad de destruir una tabla con reservas o desafíos; limpieza explícita solo de datos sintéticos.

El conjunto de pruebas se ejecuta con los indicadores y credenciales **desechables de `g3_ci`** del workflow ya existente. No hay servidor público, claves de Salesforce, API externa, módulo real ni ampliación de permisos.

## Límite de seguridad imprescindible

**Este mecanismo NO identifica de forma infalsificable un servidor ni impide todo clonado.** Si una clave privada es exportable y se copia junto con la instalación, ambas copias pueden superar el mismo tipo de desafío. El ensayo solo prueba que un clon **sin la clave externa** falla. Un despliegue de producción requerirá, tras aprobación de arquitectura y seguridad:

- Custodia separada de claves (agente externo, HSM/TPM o servicio de identidad con protección equivalente), política de recuperación y rotación auditada.
- Enrolamiento por operador autenticado con prueba **fuera de la instalación clonable**; revocación al restaurar, migrar o transferir una tienda, con estados de cuarentena.
- Transporte autenticado (TLS) y registro central bajo administración definida, con auditoría y control de acceso; ninguno de ellos está implementado en este ensayo.
- Modelo de disponibilidad/caducidad, límites de emisión, respuesta a robos y desincronización del reloj; estos controles no se pueden presumir por usar un nonce.
- Aprobación humana G3-01 para namespace/identidad, **G3-02…08 para datos**, y autorizaciones separadas G4–G8 para publicación, ingesta y campañas.

**Resultado esperado de este corte:** evidencia técnica de desafío+firma+anti-replay **sin cerrar G3-01** y sin tocar Barcelona Confort. No se genera ZIP y no se requiere SQL ni reinstalación.
