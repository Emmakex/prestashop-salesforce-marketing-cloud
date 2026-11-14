"""G3 pending approval receipt must not be forged by recommendations/CI."""
import copy
import importlib.util
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "g3_handoff", ROOT / "scripts/ci/verify-g3-signoff-handoff.py"
)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class G3HandoffTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.receipt = json.loads(
            (ROOT / "config/g3-abh-signoff-handoff.json").read_text("utf-8"))
        cls.closure = json.loads(
            (ROOT / "config/g3-abh-technical-closure-candidate.json").read_text("utf-8"))
        cls.schema = (ROOT / mod.SCHEMA).read_bytes()

    def verify(self, record=None, closure=None, schema=None):
        return mod.validate(
            copy.deepcopy(record if record is not None else self.receipt),
            copy.deepcopy(closure if closure is not None else self.closure),
            schema if schema is not None else self.schema,
        )

    def test_pending_review_only_passes(self):
        self.assertEqual(self.verify(), [])
        self.assertEqual(
            [r["id"] for r in self.receipt["decisions_to_sign"]],
            ["DATA_AND_PRIVACY", "DATA_360_RELATIONSHIP", "SCHEMA_AND_PREVIEW"],
        )
        self.assertTrue(all(not x for x in self.receipt["authorizations"].values()))

    def test_forged_roles_signatures_and_permissions_fail(self):
        for idx, (field, value) in enumerate([
            ("retention_policy_approved", True),
            ("dmo_pk_fk_mapping_approved", True),
            ("preview_in_pst_reviewed", True),
        ]):
            with self.subTest(field=field):
                r = copy.deepcopy(self.receipt)
                r["decisions_to_sign"][idx]["record"][field] = value
                self.assertTrue(self.verify(record=r))
        for idx in range(3):
            r = copy.deepcopy(self.receipt)
            r["decisions_to_sign"][idx]["status"] = "approved"
            self.assertTrue(self.verify(record=r))
            r = copy.deepcopy(self.receipt)
            r["decisions_to_sign"][idx]["record"]["approver_identity"] = "someone"
            self.assertTrue(self.verify(record=r))
        for permission in mod.DENIED:
            with self.subTest(permission=permission):
                r = copy.deepcopy(self.receipt)
                r["authorizations"][permission] = True
                self.assertIn("g3_handoff_unauthorized_g4_or_marketing",
                              self.verify(record=r))

    def test_candidate_is_not_final_schema(self):
        for change in [
            lambda x: x["schema_technical_recommendation"].update(
                selection_formally_approved=True),
            lambda x: x["schema_technical_recommendation"].update(
                sha256="0" * 64),
            lambda x: x["technical_recommendations"].update(
                destination_personal_data="export_email"),
            lambda x: x["technical_recommendations"].update(
                refresh_mode="partial"),
            lambda x: x["technical_recommendations"].update(
                salesforce_dmo_relation="BillToAccountId"),
        ]:
            r = copy.deepcopy(self.receipt)
            change(r)
            self.assertTrue(self.verify(record=r))
        self.assertIn("g3_handoff_frozen_schema_mismatch",
                      self.verify(schema=self.schema + b"\n"))
        closure = copy.deepcopy(self.closure)
        closure["point_1_data_privacy"]["export_to_data_360_authorized"] = True
        self.assertIn("g3_handoff_prior_gate_changed",
                      self.verify(closure=closure))

    def test_no_owner_identity_or_retention_was_invented(self):
        text = json.dumps(self.receipt)
        self.assertNotIn('"retention_days": 365', text)
        self.assertNotIn('"signed_at_utc": "2026', text)
        self.assertNotIn('"approved_schema_sha256": "8595', text)


if __name__ == "__main__":
    unittest.main()
