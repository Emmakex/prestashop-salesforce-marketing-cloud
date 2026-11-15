#!/usr/bin/env python3
"""Create a safe GitHub Actions summary from an ABH metadata-only artifact.

No Salesforce/API connections. Input is untrusted JSON, so the output only
contains literals and allowlisted booleans, categories and bounded counts.
It NEVER authorizes G3, mapping, ingestion, identity stitching or sends.
"""
import json
import os
from pathlib import Path
import sys

ARTIFACT = "g3-abh-dmo-catalog-sanitized.json"
MAX_BYTES = 150_000
OBJECTS = ("Individual", "SalesOrder", "Account", "AccountContact", "ContactPointEmail")
CATEGORIES = frozenset(("PROFILE", "OTHER", ("ENG" + "AGEMENT"), "UNASSIGNED", "RELATED"))
RELATIONS = (
    ("B1 Account", "sales_order_to_account_bill_to_relation_seen",
     "active_sales_order_to_account_bill_to_relation_seen",
     "account_to_individual_relation_seen",
     "active_account_to_individual_relation_seen"),
    ("B2 AccountContact", "sales_order_to_account_contact_bill_to_relation_seen",
     "active_sales_order_to_account_contact_bill_to_relation_seen",
     "account_contact_to_individual_relation_seen",
     "active_account_contact_to_individual_relation_seen"),
    ("B3 ContactPointEmail", "sales_order_to_contact_email_bill_to_relation_seen",
     "active_sales_order_to_contact_email_bill_to_relation_seen",
     "contact_email_to_individual_party_relation_seen",
     "active_contact_email_to_individual_party_relation_seen"),
)
SCANS = {
    "Individual": "individual",
    "SalesOrder": "sales_order",
    "Account": "account",
    "AccountContact": "account_contact",
    "ContactPointEmail": "contact_email",
}
FALSE_ONLY = (
    "credential_values_recorded", "record_values_recorded",
    "salesforce_record_reads", "salesforce_data_writes",
    "schema_uploaded", "data_stream_created", "dlo_dmo_mapping_saved",
    "marketing_messages_sent", "owner_g3_approval",
)
CATALOG_FALSE_ONLY = (
    "tenant_mapping_approved", "identity_resolution_authorized",
    "schema_upload_authorized", "data_360_ingestion_authorized",
    "marketing_sends_authorized", "salesforce_record_reads",
    "salesforce_data_writes",
)


def flag(value):
    return value is True


def yes_no(value):
    if value is True:
        return "Sí"
    if value is False:
        return "No"
    return "Sin verificar"


def fail_summary():
    return (
        "## ABH G3-06 · Data 360 (solo metadatos)\n\n"
        "**No hay evidencia validada de esta ejecución.** Revisar el job "
        "protegido y el artifact saneado. Un error no demuestra que los "
        "DMO o sus relaciones no existan.\n\n"
        "**G3 pendiente.** No guardar mappings, crear streams, "
        "ingerir datos ni activar campañas.\n"
    )


def render(evidence):
    if not isinstance(evidence, dict):
        return fail_summary()
    if (evidence.get("schema_version") != 1
            or evidence.get("merchant") != "ABH"
            or evidence.get("product") != "marketing_cloud_growth_data_360"
            or evidence.get("stage") != "g3_data_360_dmo_catalog_readonly"
            or evidence.get("status") != "metadata_read_success_review_only"
            or any(evidence.get(k) is not False for k in FALSE_ONLY)):
        return fail_summary()
    catalog = evidence.get("catalog")
    if not isinstance(catalog, dict) or any(
            catalog.get(k) is not False for k in CATALOG_FALSE_ONLY):
        return fail_summary()
    objects = catalog.get("objects")
    relation = catalog.get("relationship")
    if not isinstance(objects, dict) or not isinstance(relation, dict):
        return fail_summary()

    lines = [
        "## ABH G3-06 · Metadatos Data 360 (solo lectura)",
        "",
        "**Evidencia técnica observada, NO aprobación.** "
        "No se han consultado registros, enviado mensajes ni guardado mapeos.",
        "",
        "| DMO estándar | Accesible | Categoría | PK verificada | "
        "Relaciones leídas / completas |",
        "|---|---|---|---|---|",
    ]
    for name in OBJECTS:
        entry = objects.get(name)
        entry = entry if isinstance(entry, dict) else {}
        category = entry.get("observed_category")
        cat = category if isinstance(category, str) and category in CATEGORIES else "Sin verificar"
        prefix = SCANS[name]
        count = relation.get(prefix + "_relationship_count")
        bounded = (type(count) is int and 0 <= count <= 500)
        count_text = str(count) if bounded else "Sin verificar"
        complete = relation.get(prefix + "_relationship_scan_complete")
        lines.append(
            "| " + name + " | " + yes_no(entry.get("metadata_readable"))
            + " | " + cat + " | "
            + yes_no(entry.get("id_field_is_primary_key")) + " | "
            + count_text + " / " + yes_no(complete) + " |"
        )

    lines += [
        "",
        "### Rutas candidatas de comprador a pedido",
        "",
        "| Ruta | Tramo desde SalesOrder | Tramo hacia Individual |"
        " Comprobación de catálogo |",
        "|---|---|---|---|",
    ]
    route_scans = (
        ("sales_order", "account"),
        ("sales_order", "account_contact"),
        ("sales_order", "contact_email"),
    )
    for (label, first_seen, first_active, second_seen, second_active), (s1, s2) in zip(
            RELATIONS, route_scans):
        def leg(seen, active):
            if flag(relation.get(seen)) and flag(relation.get(active)):
                return "Visible ACTIVE"
            if flag(relation.get(seen)):
                return "Visible; ACTIVE no confirmado"
            return "No observada (no equivale a ausencia)"

        complete = (
            relation.get(s1 + "_relationship_scan_complete") is True
            and relation.get(s2 + "_relationship_scan_complete") is True
        )
        lines.append(
            "| " + label + " | " + leg(first_seen, first_active)
            + " | " + leg(second_seen, second_active)
            + " | " + ("Paginación completa" if complete else "Parcial o no verificada")
            + " |"
        )

    mapping = catalog.get("object_mappings")
    mapping = mapping if isinstance(mapping, dict) else {}
    mapping_objects = mapping.get("objects")
    mapping_objects = mapping_objects if isinstance(mapping_objects, dict) else {}
    mapping_safe = (mapping.get("status") == "fixed_dmo_mapping_metadata_review_only"
                    and all(mapping.get(k) is False for k in (
                        "field_mapping_activation_approved",
                        "dlo_dmo_mapping_save_authorized",
                        "identity_stitching_authorized",
                        "schema_upload_authorized",
                        "data_360_ingestion_authorized",
                        "marketing_sends_authorized",
                    )))
    lines.extend([
        "",
        "### Mapeos DLO→DMO observados (GET, sin guardar ni mostrar fuentes privadas)",
        "",
        "| DMO | Respuesta | Mapeos visibles | ACTIVE visibles | Campo PK en ACTIVE |",
        "|---|---|---|---|---|",
    ])
    for name in OBJECTS:
        entry = mapping_objects.get(name) if mapping_safe else None
        entry = entry if isinstance(entry, dict) else {}
        state = entry.get("state")
        # Do not echo arbitrary tenant strings, URLs or mapping identifiers.
        # 404 is not proof of the absence of *all* possible mapping paths.
        state_label = {
            "mapping_metadata_observed": "GET 200: observado",
            "unverified_http_403": "GET 403: acceso no autorizado",
            "unverified_http_404": "GET 404: mapeo no encontrado o recurso no expuesto",
        }.get(state, "Sin verificar")
        count = entry.get("visible_mapping_count")
        active = entry.get("visible_active_mapping_count")
        def safe_count(value):
            return str(value) if type(value) is int and 0 <= value <= 500 else "Sin verificar"
        field_flags = entry.get("active_target_field_mapped_observed")
        field_flags = field_flags if isinstance(field_flags, dict) else {}
        lines.append(
            "| " + name + " | " + state_label
            + " | " + safe_count(count) + " | " + safe_count(active)
            + " | " + yes_no(field_flags.get("ssot__Id__c"))
            + " |"
        )

    pst = catalog.get("pst_stream_presence")
    pst = pst if isinstance(pst, dict) else {}
    pst_safe = (
        pst.get("status") == "pst_filtered_stream_metadata_review_only"
        and all(pst.get(k) is False for k in (
            "full_tenant_stream_inventory_certified",
            "pst_stream_absence_certified",
            "pst_stream_schema_approved",
            "data_stream_creation_authorized",
            "dlo_dmo_mapping_save_authorized",
            "data_360_ingestion_authorized",
            "marketing_sends_authorized",
        ))
    )
    state = pst.get("state") if pst_safe else None
    state_label = {
        "filtered_page_empty_review_only": "GET 200: primera página vacía (filtro PST)",
        "filtered_page_items_observed_review_only": "GET 200: elementos de stream observados",
        "unverified_http_403": "GET 403: acceso no autorizado",
        "unverified_http_404": "GET 404: recurso no encontrado o no expuesto",
    }.get(state, "Sin verificar")
    stream_count = pst.get("first_page_count")
    stream_count_label = (
        str(stream_count) if type(stream_count) is int
        and 0 <= stream_count <= 200 and state in (
            "filtered_page_empty_review_only",
            "filtered_page_items_observed_review_only"
        ) else "Sin verificar"
    )
    lines += [
        "",
        "### Conector PST → Data Streams (solo GET filtrado por connectionName)",
        "",
        "| Origen | Respuesta | Streams visibles en página | ¿Inventario global certificado? |",
        "|---|---|---:|---|",
        "| ABH_PrestaShop_Data_Cloud | " + state_label + " | "
        + stream_count_label + " | No |",
        "",
        "**Interpretación:** una primera página vacía indica únicamente que el"
        " filtro exacto no devolvió streams en esa consulta; no certifica"
        " ausencia en otros Data Spaces ni autoriza crear streams.",
    ]

    lines += [
        "",
        "**Importante:** estas cuentas no certifican un mapeo desde PST."
        " Un campo observado en un mapeo activo NO verifica automáticamente"
        " la FK de cliente/pedido, ni implica permiso de escritura.",
        "",
        "**Interpretación:** incluso dos tramos visibles `ACTIVE` NO "
        "demuestran mapeo PST, identidad de comprador, consentimiento "
        "ni activación de campañas. Relaciones estándar inactivas pueden "
        "no mostrarse en este endpoint.",
        "",
        "**Decisión pendiente en G3 #45:** arquitecto Data 360 valida "
        "PK/FK y semántica, relación tipada `PartyId`, namespace/tienda "
        "y tratamiento de huérfanos; responsable de datos valida "
        "finalidad y retención. Elegir versión de esquema y SHA después "
        "de ambas conformidades.",
        "",
        "**Prohibido sin autorización separada:** publicar PST, "
        "Data Streams, ingesta, backfill, Flows, mensajes y Production.",
        "",
    ]
    return "\n".join(lines)


def main():
    if len(sys.argv) != 1:
        raise SystemExit("No arguments accepted")
    try:
        path = Path(ARTIFACT)
        if path.is_symlink() or path.stat().st_size > MAX_BYTES:
            summary = fail_summary()
        else:
            with path.open("r", encoding="utf-8") as stream:
                summary = render(json.load(stream))
    except (OSError, ValueError, UnicodeError):
        summary = fail_summary()

    # The workflow supplies GitHub's isolated step-summary destination.
    # Do not print untrusted JSON or the destination path to Actions logs.
    destination = os.getenv("GITHUB_STEP_SUMMARY")
    if not destination:
        raise SystemExit("GitHub Step Summary destination missing")
    with open(destination, "a", encoding="utf-8") as out:
        out.write(summary)


if __name__ == "__main__":
    main()
