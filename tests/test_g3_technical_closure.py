"""Fail-closed regression tests for G3 technical consolidation / G4 no-write."""
import copy
import importlib.util
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "g3_technical", ROOT / "scripts/ci/verify-g3-technical-closure.py"
)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class TechnicalClosureTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.profile = json.loads(
            (ROOT / "config/g3-abh-technical-closure-candidate.json").read_text("utf-8")
        )
        cls.freeze = json.loads(
            (ROOT / "config/g3-pst-review-fingerprint-freeze.json").read_text("utf-8")
        )
        cls.packet = json.loads(
            (ROOT / "config/g3-pst-schema-review.json").read_text("utf-8")
        )
        cls.field_owner = json.loads(
            (ROOT / "config/g3-field-purpose-owner-decisions.json").read_text("utf-8")
        )
        cls.mapping = json.loads(
            (ROOT / "config/g3-pst-dlo-dmo-mapping-candidate.json").read_text("utf-8")
        )
        cls.project = json.loads(
            (ROOT / "config/project-contract.json").read_text("utf-8")
        )
        cls.yaml_bytes = (ROOT / mod.FILE).read_bytes()

    def run_case(self, profile=None, snapshot=None, packet=None, field_owner=None,
                 mapping=None, project=None, yaml_bytes=None):
        return mod.review(
            copy.deepcopy(profile if profile is not None else self.profile),
            copy.deepcopy(snapshot if snapshot is not None else self.freeze),
            copy.deepcopy(packet if packet is not None else self.packet),
            copy.deepcopy(field_owner if field_owner is not None else self.field_owner),
            copy.deepcopy(mapping if mapping is not None else self.mapping),
            copy.deepcopy(project if project is not None else self.project),
            yaml_bytes if yaml_bytes is not None else self.yaml_bytes,
        )

    def test_preflight_is_useful_but_never_authorizes(self):
        report, errors = self.run_case()
        self.assertEqual(errors, [])
        self.assertEqual(report["technical_tracks_consolidated"], 3)
        self.assertEqual(report["remaining_owner_actions"], mod.STEPS)
        self.assertFalse(report["signoffs_obtained"])
        self.assertFalse(report["g3_formally_closed"])
        self.assertFalse(report["g4_pst_publication_authorized"])
        self.assertFalse(report["data_360_ingestion_authorized"])
        self.assertFalse(report["marketing_sends_authorized"])

    def test_schema_hash_cannot_be_invented(self):
        _, errors = self.run_case(yaml_bytes=self.yaml_bytes + b"\n")
        self.assertIn("g3_technical_candidate_hash_invalid", errors)

    def test_technical_candidate_cannot_claim_approval(self):
        tests = [
            ("selected_for_technical_review", "schema_to_publish", mod.FILE),
            ("point_1_data_privacy", "export_to_data_360_authorized", True),
            ("point_1_data_privacy", "retention_days", 365),
            ("point_1_data_privacy", "raw_optins_are_marketing_consent", True),
            ("point_1_data_privacy", "namespace_registry_operator_approved", True),
            ("point_2_customer_order_relationship", "join_by_email_authorized", True),
            ("point_2_customer_order_relationship", "dmo_linked_target", "Individual"),
            ("point_2_customer_order_relationship", "dlo_dmo_mapping_save_authorized", True),
            ("point_3_schema_finalization", "data_owner_schema_signoff", "approved"),
            ("point_3_schema_finalization", "g4_publication_authorized", True),
        ]
        for section, field, value in tests:
            with self.subTest(section=section, field=field):
                candidate = copy.deepcopy(self.profile)
                candidate[section][field] = value
                result, errors = self.run_case(profile=candidate)
                self.assertIsNone(result)
                self.assertIn("g3_technical_profile_drift_" + section, errors)

    def test_existing_owner_contract_cannot_be_silently_approved(self):
        cases = [
            ("packet", "approval_state", "approved"),
            ("snapshot", "owner_signoff", "approved"),
            ("field_owner", "purpose_signoff", "approved"),
            ("mapping", "owner_signoff", "approved"),
            ("project", "current_delivery_gate", "g4"),
        ]
        for which, field, value in cases:
            with self.subTest(which=which, field=field):
                copy_value = copy.deepcopy({
                    "packet": self.packet,
                    "snapshot": self.freeze,
                    "field_owner": self.field_owner,
                    "mapping": self.mapping,
                    "project": self.project,
                }[which])
                copy_value[field] = value
                result, errors = self.run_case(**{which: copy_value})
                self.assertIsNone(result)
                self.assertTrue(errors)

    def test_field_permission_override_is_rejected(self):
        field = copy.deepcopy(self.field_owner)
        field["field_decisions"]["PSCustomer"][4]["export_to_data_360_authorized"] = True
        report, errors = self.run_case(field_owner=field)
        self.assertIsNone(report)
        self.assertIn("g3_technical_premature_field_approval_PSCustomer", errors)

    def test_unverified_relation_cannot_be_stitched(self):
        m = copy.deepcopy(self.mapping)
        m["relation_proposal"]["selected_dmo_target_field"] = "ssot__BillToContactId__c"
        report, errors = self.run_case(mapping=m)
        self.assertIsNone(report)
        self.assertIn("g3_technical_unverified_dmo_relationship", errors)


if __name__ == "__main__":
    unittest.main()
