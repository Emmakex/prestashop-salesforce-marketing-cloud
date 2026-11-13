# G3 · Compatibilidad transaccional con las tablas reales de PrestaShop 0.7.1

**ABH: Marketing Cloud Growth + Data 360.** El objetivo no es activar
Marketing Cloud Engagement. **Estado: prototipo aislado en GitHub Actions
que NO forma parte del módulo instalable ni cambia Barcelona Confort.**

## Evidencia verificada por revisión del módulo

El instalador de `pssalesforcebridge.php` 0.7.1 define:

- `sfmc_queue`, clave única `dedupe_key` **sin namespace**,
  estados actualmente reclamables `pending` y `retry`.
- `sfmc_customer_sync`, clave única por `id_shop, id_customer` y
  otra sobre `contact_key`.
- `sfmc_order_sync`, clave única por `id_shop, id_order`.
- Customer/Order Event Services llaman primero a `observe()` del
  ledger y después a `QueueRepository::enqueue()`: **sin transacción
  conjunta** en el camino 0.7.1.
- El worker legacy puede realizar un `upsert` Engagement antes de
  verificar el hash actual de su ledger. No reutilizar este procesador
  para enviar eventos Growth.

El fichero `tests/integration/g3_prestashop_071_mirror_schema.sql`
replica los tipos, índices y columnas de esas **tres tablas reales**,
pero con prefijo de laboratorio `g3_`. El script
`scripts/ci/verify-g3-legacy-mirror.py` comprueba la estructura contra
el DDL verdadero del instalador. Adicionalmente, la base efímera tiene
`g3_growth_journal` con
`UNIQUE(source_namespace, dedupe_fingerprint)` y vínculo exclusivo
`legacy_queue_id`.

## Acoplamiento probado sin intervención en BC

`tests/integration/G3SandboxLegacyQueueBridge.php` implementa el
contrato `StagedAtomicUtcAcceptance` usando **PDO real** y **InnoDB**,
pero solo sobre tablas fijas `g3_` en la BD temporal `g3_ci`.
No es un adaptador productivo.

1. Adquiere un bloqueo por **instalación/tienda/tipo/entidad**, no solo
   por hash, para serializar versiones comerciales distintas que
   afecten al mismo ledger.
2. Abre transacción, verifica el diario original con
   `SELECT ... FOR UPDATE`, y reutiliza su hora UTC si es duplicado.
3. Si no hay diario, revisa el `dedupe_key` antiguo en
   `sfmc_queue`. **Una fila preexistente nunca se adopta**: aborta
   y mantiene el estado original. Esto importa porque la clave legacy
   carece de namespace por instalación.
4. Si el ledger original está en un estado distinto de
   `blocked`, también **aborta** sin sobrescribir el proceso
   legacy. No presupone permiso para migrar filas `pending`,
   `synced` o `failed`.
5. Inserta journal + fila de cola con `status=blocked`, enlaza
   ambos IDs y actualiza el ledger del espejo en una misma transacción.
   El evento está **bloqueado** para los claimers legacy, que solo
   seleccionan `pending`/`retry`.
6. Prueba rollback tras journal, cola, enlace y ledger. Reintentos
   antiguos no reescriben una aceptación más reciente.
7. Ocho procesos distintos, cada uno con su propia conexión, disputan
   el mismo evento: solo se admite una fila y todos deben recuperar
   la misma marca UTC. Los tests corren en **MySQL 8.0 y MariaDB 10.11**.

Los payloads y contactos del laboratorio son valores ficticios fijos,
sin IDs, emails ni pedidos reales de BC. Incluso una prueba correcta
**no demuestra** que el viejo worker Growth sea seguro; este código
**no se invoca desde hooks, cron ni Back Office**.

## Requisitos para conectar el componente real (TODAVÍA bloqueados)

- **Namespace:** aprobación G3-01 y mecanismo global para cada
  instalación. La cola legacy **no tiene namespace**, por lo que
  una migración real debe elegir aislamiento de clave/tabla o nueva
  cola de Growth, sin alterar las claves de filas existentes.
- **Versiones y orden:** la aceptación local no demuestra la fecha
  causal de modificación fuente. Hace falta verificar estado actual
  y versión antes de cualquier escritura hacia Data 360.
- **Migración/rollback:** preparar una nueva tabla outbox Growth o una
  actualización compatible con PrestaShop con transacción y esquema
  reversible, sin tocar filas legacy. Revisar backup, prefijos,
  engine InnoDB y colisiones, y probar upgrade/uninstall en PS
  1.7.8.x, 8.x y 9.x. Ningún upgrade se publica aquí.
- **Consentimiento, retención y supresiones:** cerrar G3-07, los
  campos `PSCustomer`/`PSOrder`, la procedencia temporal G3-03/04
  y el mapeo en Data 360. Nada de asumir que newsletter permite
  contactar al cliente.
- **Autorización separada:** aprobación explícita antes de publicar
  esquema PST, crear streams, enviar datos de clientes o activar
  campañas de Growth. Los tests solo autorizan continuar diseñando,
  **nunca** envíos reales.

**Límites del corte:** sin acceso SQL a producción por parte del usuario,
sin credenciales nuevas, sin ZIP, sin ejecución de SQL en BC, sin
migraciones ni actualizaciones en el módulo instalado 0.7.1.
