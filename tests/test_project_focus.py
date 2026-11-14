"""Refuse to resurrect a second Salesforce provider or enable real sends."""
import copy
import importlib.util
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/ci/verify-project-focus.py"
spec = importlib.util.spec_from_file_location("focus", SCRIPT)
focus = importlib.util.module_from_spec(spec)
spec.loader.exec_module(focus)


class GrowthOnlyProjectFocusTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.contract = json.loads(
            (ROOT / "config/project-contract.json").read_text("utf-8"))

    def fails(self, change):
        candidate = copy.deepcopy(self.contract)
        change(candidate)
        self.assertTrue(focus.validate_contract(candidate))

    def test_growth_only_baseline(self):
        self.assertEqual(focus.validate_contract(self.contract), [])
        self.assertEqual(focus.validate_repository(ROOT), [])

    def test_extra_provider_cannot_be_added(self):
        self.fails(lambda x: x["provider_registry"].update({
            "marketing_cloud_engagement": {"enable_production": False}
        }))
        self.fails(lambda x: x["provider_registry"]["marketing_cloud_growth"].update({
            "enable_production": True
        }))
        self.fails(lambda x: x["pilot"].update(provider="marketing_cloud_engagement"))
        self.fails(lambda x: x.update(architecture="dual_provider"))
        self.fails(lambda x: x.update(delivery_policy="allow_any_provider"))

    def test_no_live_ingest_or_sends(self):
        self.fails(lambda x: x["pilot"].update(live_campaign_sends_authorized=True))
        self.fails(lambda x: x["pilot"].update(customer_data_live_ingestion_authorized=True))
        self.fails(lambda x: x["pilot"].update(historical_backfill_authorized=True))
        self.fails(lambda x: x["pilot"].update(prestashop_runtime_mode_observed="production"))
        self.fails(lambda x: x["pilot"].update(data_cloud_schema_status="approved"))
        self.fails(lambda x: x.update(current_delivery_gate="growth_campaign_live"))

    def test_legacy_installed_release_must_not_be_overstated(self):
        self.fails(lambda x: x["pilot"].update(installed_module_version_observed="0.8.0"))
        self.fails(lambda x: x["pilot"].update(target_module_version="0.7.1"))

    def test_no_changed_merchant_scope(self):
        self.fails(lambda x: x.update(supported_prestashop_families=["9.x"]))
        self.fails(lambda x: x["pilot"].update(merchant="OTHER"))
        self.fails(lambda x: x["pilot"].update(cart_abandon_minutes_observed="60"))
        self.fails(lambda x: x["pilot"].pop("data_cloud_schema_status"))

    def test_invalid_contract(self):
        self.assertTrue(focus.validate_contract(None))
        self.assertTrue(focus.validate_contract({}))


if __name__ == "__main__":
    unittest.main()
