# G3 — Actualización PrestaShop 0.7.1 → candidato 0.8.0 (únicamente CI)

**Fecha:** 13-11-2026. **Comercio piloto:** ABH / Barcelona Confort.  
**Producto:** Salesforce Marketing Cloud Growth/Next + Data 360 (**no Engagement**).  
**Estado:** candidato reproducible de CI, sin módulo 0.8.0 distribuible y sin aprobación G3.

## Objetivo y mecanismo

En #53 se verificó el ciclo local de creación, comprobación y conservación de tablas en
PrestaShop 1.7.8.11, 8.2.8 y 9.1.5, pero no se ejecutó un **upgrade de módulo versionado**
mediante la consola real de PrestaShop.

Este corte agrega un ensayo estrictamente desechable:

1. Después de completar todas las pruebas existentes de 0.7.1, copiar el módulo 0.7.1
   a una carpeta temporal dentro del runner de CI.
2. Cambiar **solo esa copia** a versión 0.8.0 e incorporar el archivo experimental
   `tests/runtime/g3-candidate-upgrade-0.8.0.php` como
   `upgrade/upgrade-0.8.0.php`.
3. Realizar un inventario local antes del cambio: número agregado de filas en cola
   y ledgers legacy, modo, retención y configuración de carritos; **sin correos, tokens ni PII**.
4. Realizar la actualización usando el comando nativo
   `prestashop:module upgrade pssalesforcebridge`, ejecutado únicamente dentro
   de los tres contenedores efímeros de CI.
5. Exigir el proveedor **explicitamente** `marketing_cloud_growth`, tienda piloto
   `ABH`, namespace sintético de laboratorio y modo `test`.
6. Comprobar que la versión instalada pasa a 0.8.0 **solo en CI**, aparecen las tres
   tablas Growth aisladas, no cambia ninguno de los agregados o ajustes legacy,
   repetir el instalador es idempotente y el motor actual devuelve `test_mode`
   en los tres procesos de envío.
7. Añadir una observación **sintética y bloqueada**, simular la petición de retirada
   y confirmar que conserva tanto el registro como las tablas de este adaptador.

## Barreras y seguridad

El script de actualización contiene guardas obligatorias de GitHub Actions,
flag específico de CI, base `prestashop`, prefijo `ps_`, versión PrestaShop
exacta, módulo y modo Test, más proveedor/namespace/merchant explícitos. La
falta de cualquier condición devuelve fallo, sin elegir proveedor por defecto.

No se altera ningún archivo bajo `pssalesforcebridge/` en el repositorio.
La versión distribuida **continúa siendo 0.7.1** y su ZIP sigue exactamente
ese contrato. No se ejecuta SQL en Barcelona Confort, ni se cambian credenciales,
permisos, streams, schemas, OAuth, ingesta, campañas, Flow o envíos.

**Limitaciones:** esta prueba usa MySQL 5.7 en las tres instalaciones
PrestaShop de CI; las restricciones SQL `CHECK` no se aplican en MySQL 5.7.
No se habilita ningún emisor Growth. La prueba no constituye aprobación de
una implementación 0.8.0 para producción ni demuestra semántica completa de
reversión del módulo. La retirada del módulo original 0.7.1 es destructiva;
el futuro 0.8.0 deberá sustituir explícitamente ese comportamiento **antes**
de cualquier publicación o despliegue comercial.

## Pendiente para cerrar este diseño

- Aprobación del responsable de datos para PSCustomer/PSOrder (G3), namespace
  de instalación real, propósito/consentimiento, retención, erasure y origen UTC.
- Fijar política no destructiva en el desinstalador real y estrategia de
  recuperación frente a DDL parcial y migraciones entre versiones del motor DB.
- Separar y proteger físicamente todos los procesos de envío por proveedor
  (no bastará dejar el modo legacy en Test para una versión definitiva).
- Llevar el código del instalador a una versión real solo después de esa
  revisión y ejecutar nuevamente CI + Runtime Certification exact-head y main.

**BC sigue en 0.7.1 y modo Test. No instalar nada ni pedir acceso SQL.**
