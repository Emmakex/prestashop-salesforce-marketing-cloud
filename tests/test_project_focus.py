"""Negative tests to prevent silent ABH provider and rollout drift."""
import copy
import importlib.util
import json
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "ci" / "verify-project-focus.py"
spec = importlib.util.spec_from_file_location("verify_project_focus", SCRIPT)
focus = importlib.util.module_from_spec(spec)
spec.loader.exec_module(focus)


class ProjectFocusContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.record = json.loads((ROOT / "config" / "project-contract.json").read_text(encoding="utf-8"))

    def must_reject(self, change):
        other = copy.deepcopy(self.record)
        change(other)
        self.assertNotEqual(focus.validate_contract(other), [])

    def test_current_contract_is_canonical(self):
        self.assertEqual(focus.validate_contract(self.record), [])
        self.assertEqual(focus.validate_repository(ROOT), [])

    def test_switching_abh_to_engagement_fails(self):
        self.must_reject(lambda c: c["pilot"].update(provider="marketing_cloud_engagement"))

    def test_engagement_activating_for_abh_fails(self):
        self.must_reject(lambda c: c["provider_registry"]["marketing_cloud_engagement"].update(enable_for_abh=True))

    def test_growth_replaced_by_soap_fails(self):
        self.must_reject(lambda c: c["pilot"].update(route="engagement_soap_subscribers"))

    def test_switching_live_mode_fails(self):
        self.must_reject(lambda c: c["pilot"].update(prestashop_runtime_mode_observed="production"))

    def test_enabling_sends_fails(self):
        self.must_reject(lambda c: c["pilot"].update(live_campaign_sends_authorized=True))

    def test_enabling_ingestion_fails(self):
        self.must_reject(lambda c: c["pilot"].update(customer_data_live_ingestion_authorized=True))

    def test_enabling_backfill_fails(self):
        self.must_reject(lambda c: c["pilot"].update(historical_backfill_authorized=True))

    def test_falsely_claiming_pst_schema_approved_fails(self):
        self.must_reject(lambda c: c["pilot"].update(data_cloud_schema_status="approved"))

    def test_skipping_schema_gate_fails(self):
        self.must_reject(lambda c: c.update(current_delivery_gate="growth_campaigns"))

    def test_prestashop_version_scope_changes_fail(self):
        self.must_reject(lambda c: c.update(supported_prestashop_families=["9.x"]))

    def test_legacy_adapter_as_default_fails(self):
        self.must_reject(lambda c: c["provider_registry"]["marketing_cloud_engagement"].update(pilot=True))

    def test_non_boolean_flag_fails(self):
        self.must_reject(lambda c: c["pilot"].update(live_campaign_sends_authorized=0))

    def test_missing_schema_fails(self):
        self.must_reject(lambda c: c["pilot"].pop("data_cloud_schema_status"))

    def test_zero_or_missing_contract_fails(self):
        self.assertTrue(focus.validate_contract({}))
        self.assertTrue(focus.validate_contract(None))


if __name__ == "__main__":
    unittest.main()
