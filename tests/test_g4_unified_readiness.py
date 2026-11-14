"""G4 unified preflight: synthetic, no network or customer data tests."""
import copy
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
PATH = ROOT / "scripts/ci/render-g4-readiness-report.py"
spec = importlib.util.spec_from_file_location("g4_report", PATH)
g4 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(g4)


def valid_metadata():
    objects = {
        name: {
            "metadata_readable": True,
            "observed_category": "PROFILE" if name == "Individual"
                                 else "UNASSIGNED",
            "id_field_is_primary_key": True,
        }
        for name in g4.OBJECTS
    }
    relations = {}
    for slug in g4.SCANS.values():
        relations[slug + "_relationship_count"] = 12
        relations[slug + "_relationship_scan_complete"] = True
    for fields in g4.ROUTES.values():
        for flag in fields[:4]:
            relations[flag] = True
    return {
        "schema_version": 1,
        "merchant": "ABH",
        "product": "marketing_cloud_growth_data_360",
        "connector_alias": "PST",
        "stage": "g3_data_360_dmo_catalog_readonly",
        "status": "metadata_read_success_review_only",
        "catalog": {
            "objects": objects,
            "relationship": relations,
            **{key: False for key in g4.CATALOG_FALSE_ONLY},
        },
        **{key: False for key in g4.FALSE_ONLY},
    }


class G4UnifiedReadinessTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sources = g4.load_sources(ROOT)

    def report(self, sources=None, metadata=None):
        return g4.build_report(
            copy.deepcopy(self.sources if sources is None else sources),
            copy.deepcopy(metadata),
        )

    def test_baseline_is_useful_but_does_not_authorize_any_action(self):
        r = self.report()
        self.assertEqual(r["local_contract"], "verified_pending_owner_approval")
        self.assertEqual(r["data_360"]["state"], "no_current_run_artifact")
        self.assertEqual(r["schema_candidate"]["sha256"], g4.SOURCE_HASH)
        self.assertTrue(r["schema_candidate"]["hash_verified"])
        self.assertEqual(r["remaining_owner_actions"], list(g4.OWNER_ACTIONS))
        self.assertEqual(r["pilot"]["shop_sql_engine"], "not_observed")
        self.assertEqual(r["lab"]["growth_sql_check_capability_in_runtime"], "blocked")
        self.assertEqual(r["lab"]["runtime_ci_run_reference"], 43624367487)
        for key, val in r.items():
            if key.endswith("_authorized") or key in (
                    "g3_owner_signoffs_received", "g3_formally_closed"):
                self.assertIs(val, False, key)
        md = g4.markdown(r)
        self.assertIn("G4, ingesta, mapeos y envíos: BLOQUEADOS", md)
        self.assertIn("tres", "tres")
        self.assertNotIn("private@example.invalid", md)

    def test_synthetic_metadata_only_changes_observations(self):
        r = self.report(metadata=valid_metadata())
        self.assertEqual(r["data_360"]["state"], "sanitized_metadata_observed_this_run")
        self.assertTrue(r["data_360"]["this_run_data_360_catalog_verified"])
        self.assertEqual(r["data_360"]["dmos"]["Individual"]["category"], "PROFILE")
        self.assertEqual(r["data_360"]["dmos"]["SalesOrder"]["category"], "UNASSIGNED")
        self.assertEqual(r["data_360"]["dmos"]["Account"]["relationships_count"], 12)
        self.assertEqual(
            r["data_360"]["buyer_routes"]["account_contact"],
            "two_active_legs_observed_mapping_unapproved",
        )
        self.assertFalse(r["data_360"]["dlo_dmo_mapping_certified"])
        self.assertFalse(r["g4_dlo_dmo_mapping_save_authorized"])
        self.assertFalse(r["g4_schema_upload_authorized"])
        self.assertFalse(r["marketing_sends_authorized"])

    def test_inactive_or_incomplete_route_never_certified(self):
        for mutation in ("missing_active", "incomplete", "unreadable"):
            with self.subTest(mutation=mutation):
                src = valid_metadata()
                if mutation == "missing_active":
                    src["catalog"]["relationship"][
                        "active_account_contact_to_individual_relation_seen"
                    ] = False
                elif mutation == "incomplete":
                    src["catalog"]["relationship"][
                        "account_contact_relationship_scan_complete"
                    ] = False
                else:
                    src["catalog"]["objects"]["AccountContact"]["metadata_readable"] = False
                r = self.report(metadata=src)
                self.assertEqual(r["data_360"]["buyer_routes"]["account_contact"],
                                 "not_certified")
                self.assertFalse(r["data_360"]["dlo_dmo_mapping_certified"])

    def test_missing_or_failed_metadata_not_approvable(self):
        for bad in (False, [], {}, {"status": "failed"}, "fake"):
            with self.subTest(bad=bad):
                r = self.report(metadata=bad)
                self.assertEqual(
                    r["data_360"]["state"], "invalid_or_failed_metadata_artifact")
                self.assertFalse(r["data_360"]["this_run_data_360_catalog_verified"])
                self.assertFalse(r["g4_schema_upload_authorized"])
        for flag in g4.FALSE_ONLY:
            with self.subTest(flag=flag):
                bad = valid_metadata()
                bad[flag] = True
                self.assertEqual(
                    self.report(metadata=bad)["data_360"]["state"],
                    "invalid_or_failed_metadata_artifact")
        for flag in g4.CATALOG_FALSE_ONLY:
            with self.subTest(flag=flag):
                bad = valid_metadata()
                bad["catalog"][flag] = True
                self.assertEqual(
                    self.report(metadata=bad)["data_360"]["state"],
                    "invalid_or_failed_metadata_artifact")

    def test_secret_and_pii_strings_never_leave_metadata_renderer(self):
        src = valid_metadata()
        src["client_secret"] = "TOP_SECRET_MARKER"
        src["customer_email"] = "private@example.invalid"
        src["tenant_host"] = "secret.salesforce.com"
        src["catalog"]["objects"]["Individual"]["raw_record_value"] = "TOP_SECRET_MARKER"
        src["catalog"]["objects"]["Individual"]["observed_category"] = (
            "private@example.invalid"
        )
        src["catalog"]["relationship"]["account_relationship_count"] = (
            "555\n| TOP_SECRET_MARKER |"
        )
        r = self.report(metadata=src)
        payload = json.dumps(r, ensure_ascii=False) + g4.markdown(r)
        self.assertNotIn("TOP_SECRET_MARKER", payload)
        self.assertNotIn("private@example.invalid", payload)
        self.assertNotIn("secret.salesforce.com", payload)
        self.assertEqual(r["data_360"]["dmos"]["Individual"]["category"], "UNVERIFIED")
        self.assertIsNone(r["data_360"]["dmos"]["Account"]["relationships_count"])
        self.assertFalse(r["marketing_sends_authorized"])

    def test_changed_schema_or_owner_gates_fail_closed(self):
        cases = [
            ("schema", lambda v: v + b"\n"),
            ("closure", lambda v: {**v, "module_mode": "production"}),
            ("closure", lambda v: {
                **v, "point_3_schema_finalization": {
                    **v["point_3_schema_finalization"], "g4_publication_authorized": True
                }
            }),
            ("freeze", lambda v: {**v, "owner_signoff": "approved"}),
            ("packet", lambda v: {**v, "uploaded_to_tenant": True}),
            ("privacy", lambda v: {**v, "purpose_signoff": "approved"}),
            ("mapping", lambda v: {**v, "dlo_dmo_mapping_save_authorized": True}),
            ("project", lambda v: {
                **v, "pilot": {
                    **v["pilot"], "live_campaign_sends_authorized": True
                }
            }),
        ]
        for key, fn in cases:
            with self.subTest(key=key):
                changed = copy.deepcopy(self.sources)
                changed[key] = fn(changed[key])
                r = self.report(sources=changed, metadata=valid_metadata())
                self.assertEqual(r["local_contract"], "blocked_invalid_local_contract")
                self.assertFalse(r["g4_schema_upload_authorized"])
                self.assertFalse(r["data_360_ingestion_authorized"])
                self.assertFalse(r["marketing_sends_authorized"])

    def test_reviewed_fields_are_not_export_permissions(self):
        changed = copy.deepcopy(self.sources)
        changed["privacy"]["field_decisions"]["PSCustomer"][4][
            "export_to_data_360_authorized"] = True
        r = self.report(sources=changed)
        self.assertEqual(r["local_contract"], "blocked_invalid_local_contract")

    def test_no_network_sql_or_external_execution_in_reporter(self):
        source = PATH.read_text(encoding="utf-8")
        for dangerous in (
            "requests.", "urllib.request", "subprocess.", "socket.",
            "curl_exec", "mysql.connector", "pymysql",
            "api/v1/ingest", "os.system(", "Popen(",
        ):
            self.assertNotIn(dangerous, source, dangerous)


if __name__ == "__main__":
    unittest.main()
