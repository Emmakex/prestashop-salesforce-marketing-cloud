# ABH · G4 — Informe unificado de preparación sin escritura

**Estado (14/11/2026): G4 READINESS ONLY. No es autorización de publicación.**

## Por qué se añade

La investigación anterior ya aportó OAuth Core, token exchange Data 360, PST `Schema Required`, dos YAML congelados (v0.2 / v0.3), 24 campos revisados, huellas SHA256, catálogo DMO y pruebas reales del módulo PrestaShop. No necesitamos repetir preguntas, SQL, instalaciones ni sustituir Growth por producto discontinuado.

El [PR #85](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/pull/85) dejó los pendientes humanos en **tres grupos**: identidad/finalidad/retención, relación cliente-pedido y esquema final con conformidades. El [PR #86](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/pull/86) certificó en laboratorio que **MySQL 5.7 ignora CHECK**, y el [PR #87](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/pull/87) añadió la inspección `Db` real, certificada [3/3](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/actions/runs/43624367487) para PrestaShop 1.7.8.11/8.2.8/9.1.5.

## Vías de identidad sujetas a firma

El informe de preparación exige ahora integridad SHA-256 de **v0.2** y de la alternativa tipada **v0.4**; comprueba que todas las firmas y permisos permanecen pendientes/bloqueados. Una manipulación de cualquiera de los dos esquemas, una firma falsa o una autorización de publicación invalida el informe. El candidato v0.4 no equivale a relación DMO autorizada.

## Entregables

- **Script sin red ni SQL:** `scripts/ci/render-g4-readiness-report.py`. Genera `g4-abh-readiness-sanitized.json` en el directorio local de la ejecución.
- **En CI normal:** revisa fuente y SHA real del YAML candidato **v0.2/22 campos**, snapshot firmado *pendiente* (no inventa firma), inventario 24 campos de privacidad, mapping DMO pendiente, namespace, proveedor Growth y modo Test. No consulta a Salesforce; sin artifact de metadatos marca `no_current_run_artifact`.
- **En el workflow GitHub ya protegido:** [`g3-dmo-catalog-readonly.yml`](../.github/workflows/g3-dmo-catalog-readonly.yml) sigue ejecutando el inspector DMO **GET-only** del proyecto. Después consume **solo** el archivo `g3-abh-dmo-catalog-sanitized.json` producido por ese mismo run, no lee payloads crudos, registros, emails ni secretos. Añade una tabla al Summary y sube un segundo artefacto `g4-abh-readiness-readonly-*` con un JSON restringido.
- **Pruebas negativas:** `tests/test_g4_unified_readiness.py` detecta cambios del SHA, aprobación falsa, permiso por campo, asociación indebida y control de privacidad alterado; rechaza metadata inválida, incompleta, relaciones no activas y datos arbitrarios potencialmente sensibles.

## Interpretación de fuentes

| Área | Qué aparece | Qué NO significa |
|---|---|---|
| Esquema | `v0.2`, `PSCustomer/PSOrder`, 22 campos, hash exacto `8595c2f9...` | No seleccionado ni aprobado para publicar en PST |
| Contrato G3 | Integridad técnica verificada; quedan 3 grupos de conformidad | No equivalen a firmas humanas o consentimiento marketing |
| PrestaShop | 0.7.1 `test` último estado observado; matriz real 3/3 con **MySQL 5.7 descartable** | No demuestra versión del **motor SQL de BC producción** |
| Data 360 | Metadatos restringidos de 5 DMO y rutas Account / AccountContact / ContactPointEmail cuando existe artifact del workflow protegido | Una ruta visible no demuestra mapping ABH ni asociación autorizada |
| G4 | Reporte de preparación; todas las autorizaciones `false` | Ningún Schema Upload, Data Stream, ingesta o campaña |

La **categoría del DMO `SalesOrder=UNASSIGNED`** que se observó previamente no debe confundirse con la categoría del futuro Data Stream `PSOrder`. Una relación estándar inactiva o inaccesible puede no aparecer como activa; no inferimos inexistencia ni un enlace por email. El valor `source_updated_utc` del esquema **v0.3** sigue siendo candidato sin procedencia histórica verificable, por eso v0.2 es solo la base elegida para **revisión técnica**.

## Ejecución y comprobaciones

En el repositorio, sin acceso externo:

```bash
python3 scripts/ci/render-g4-readiness-report.py
python3 -m unittest discover -s tests -p 'test_g4_unified_readiness.py'
```

El primer comando crea un archivo **saneado** con tres resultados independientes:

1. **Contrato local**: `verified_pending_owner_approval` si coincide con la fuente versionada, o `blocked_invalid_local_contract` si existe deriva.
2. **Catálogo del tenant de esta ejecución**: `no_current_run_artifact` en CI offline, `sanitized_metadata_observed_this_run` si el workflow protegido produjo evidencia GET-only validada, o `invalid_or_failed_metadata_artifact`.
3. **Capacidad de instalar Growth en BC**: `not_certified`; solo el runtime descartable evidencia MySQL 5.7 y la denegación de CHECK. No se atribuye a la producción la versión de MySQL de Docker.

Si hay discrepancia contractual, el script termina con error en CI (fail-closed); el workflow protegido conserva la tabla de evaluación y no otorga nuevos permisos. **Ningún JSON de este reporte puede pasar a `schema_uploaded=true` o `marketing_sends_authorized=true`.**

## Próximo paso permitido

El responsable de datos y el arquitecto Data 360 validan **las tres decisiones residuales del expediente [#45](https://github.com/Emmakex/prestashop-salesforce-marketing-cloud/issues/45)**. Mientras tanto puede continuar el trabajo en pruebas locales y preflight, no la publicación de PST ni la ingesta. Barcelona Confort conserva `pssalesforcebridge` **0.7.1 Test**; no solicitar reinstalación ni acceso SQL.

Este informe también aclara algo que el reporte **no** cubre: no existe aún un PSCart Data Stream ni una campaña Growth end-to-end aprobada. Se resuelve en G6/G7, no al cerrar G3.
