"""Synthetic-only ABH G3-08 business outcome coverage contract."""
import copy
import importlib.util
import json
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
FILE = ROOT / "scripts/ci/verify-g3-business-goal-coverage.py"
spec = importlib.util.spec_from_file_location("g3_business_review", FILE)
checker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(checker)


class G3BusinessGoalReview(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.packet = json.loads((ROOT / "config/g3-pst-schema-review.json").read_text())
        cls.freeze = json.loads((ROOT / "config/g3-pst-review-fingerprint-freeze.json").read_text())
        cls.field_owner = json.loads((ROOT / "config/g3-field-purpose-owner-decisions.json").read_text())
        cls.mapping = json.loads((ROOT / "config/g3-pst-dlo-dmo-mapping-candidate.json").read_text())
        cls.contract = json.loads((ROOT / "config/project-contract.json").read_text())
        cls.blobs = {key: (ROOT / path).read_bytes() for key, path in checker.SCHEMAS.items()}
        cls.docs = {key: yaml.safe_load(raw) for key, raw in cls.blobs.items()}

    def test_real_source_state_and_customer_cart_gap(self):
        report, errors = checker.validate_repository(ROOT)
        self.assertEqual(errors, [])
        self.assertEqual(report["status"], "review_only_not_ready_for_g3_08")
        self.assertEqual(report["g3_decisions_pending"], checker.EXPECTED_GATES)
        self.assertEqual(report["candidate_schemas"]["v0.2"]["field_count"], 22)
        self.assertEqual(report["candidate_schemas"]["v0.3"]["field_count"], 24)
        for item in report["candidate_schemas"].values():
            self.assertEqual(item["source_objects"], ["PSCustomer", "PSOrder"])
            self.assertFalse(item["cart_stream_defined"])
        for name in ("customer_marketing_campaign", "abandoned_cart_recovery",
                     "customer_order_segmentation_in_data_360"):
            self.assertEqual(report["business_capabilities"][name]["status"], "blocked")
        self.assertEqual(report["business_capabilities"]["converted_cart_suppression"]["status"],
                         "local_source_precheck_candidate_only")
        self.assertFalse(report["g3_08_schema_approval_ready"])
        self.assertFalse(report["g4_schema_upload_authorized"])
        self.assertFalse(report["data_360_ingestion_authorized"])
        self.assertFalse(report["growth_campaign_send_authorized"])
        for forbidden in ("@", "access_token", "email", "ps_1_"):
            # Only metadata labels are emitted, never actual customer examples.
            self.assertNotIn(forbidden, json.dumps(report).lower())

    def expect_block(self, mutation):
        p, f, owner, m, c, docs, blobs = copy.deepcopy((
            self.packet, self.freeze, self.field_owner,
            self.mapping, self.contract, self.docs, self.blobs))
        mutation(p, f, owner, m, c, docs, blobs)
        report, problems = checker.review(p, f, owner, m, c, docs, blobs)
        self.assertIsNone(report)
        self.assertTrue(problems)

    def test_false_owner_signoff_never_becomes_readiness(self):
        for key, value in [
            ("approval_state", "approved"),
            ("schema_to_publish", "v0.3"),
            ("uploaded_to_tenant", True),
            ("streams_approved", True),
            ("real_data_ingestion_approved", True),
            ("marketing_sends_approved", True),
        ]:
            with self.subTest(key=key):
                self.expect_block(lambda p,f,o,m,c,d,b,k=key,v=value: p.__setitem__(k,v))

    def test_approval_must_not_be_inferred_from_metadata(self):
        self.expect_block(lambda p,f,o,m,c,d,b:
            m.__setitem__("tenant_dmo_catalog_verified", True))
        self.expect_block(lambda p,f,o,m,c,d,b:
            m["relation_proposal"].__setitem__("dmo_relationship_verified_in_tenant", True))
        self.expect_block(lambda p,f,o,m,c,d,b:
            m["relation_proposal"].__setitem__("selected_dmo_target_field", "ssot__SoldToCustomerId__c"))
        self.expect_block(lambda p,f,o,m,c,d,b:
            m["relation_proposal"].__setitem__("email_dedupe_authorized", True))
        self.expect_block(lambda p,f,o,m,c,d,b:
            m.__setitem__("dlo_dmo_mapping_save_authorized", True))

    def test_no_privacy_or_schema_shortcut(self):
        self.expect_block(lambda p,f,o,m,c,d,b:
            o["field_decisions"]["PSCustomer"][0].__setitem__("growth_send_authorized", True))
        self.expect_block(lambda p,f,o,m,c,d,b:
            o["field_decisions"]["PSOrder"][0].__setitem__("export_to_data_360_authorized", True))
        self.expect_block(lambda p,f,o,m,c,d,b:
            o.__setitem__("retention_signoff", "approved"))
        self.expect_block(lambda p,f,o,m,c,d,b:
            f.__setitem__("data_360_upload_authorized", True))
        self.expect_block(lambda p,f,o,m,c,d,b:
            p["open_decisions"][5].__setitem__("state", "approved"))
        self.expect_block(lambda p,f,o,m,c,d,b:
            c["pilot"].__setitem__("provider", "marketing_cloud_engagement"))
        self.expect_block(lambda p,f,o,m,c,d,b:
            c["pilot"].__setitem__("prestashop_runtime_mode_observed", "production"))

    def test_new_cart_object_or_drift_needs_separate_schema_approval(self):
        self.expect_block(lambda p,f,o,m,c,d,b:
            d["v0.3"]["components"]["schemas"].__setitem__(
                "PSCart", {"type":"object", "properties":{}}))
        self.expect_block(lambda p,f,o,m,c,d,b:
            d["v0.2"]["components"]["schemas"]["PSCustomer"]["properties"].pop("email"))
        self.expect_block(lambda p,f,o,m,c,d,b:
            b.__setitem__("v0.3", b["v0.3"] + b"\n# mutation"))
        self.expect_block(lambda p,f,o,m,c,d,b:
            f["artifacts"][checker.SCHEMAS["v0.2"]].__setitem__("sha256", "0" * 64))


if __name__ == "__main__":
    unittest.main()
