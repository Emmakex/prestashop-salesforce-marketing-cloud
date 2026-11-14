"""Offline G3 typed-PK schema proposal must not change frozen PST v0.2."""
import hashlib
from pathlib import Path
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[1]
FROZEN = ROOT / "schemas/ABH_PST_SCHEMA_REVIEW.yaml"
PROPOSED = ROOT / "schemas/ABH_PST_TYPED_IDENTITY_CANDIDATE.yaml"
FROZEN_SHA = "8595c2f9acb5be388ba3b2ec156ec65379ab04bf5ebf3718e63231cb06b43e9d"

class TypedSchemaContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.frozen = yaml.safe_load(FROZEN.read_bytes())
        cls.candidate = yaml.safe_load(PROPOSED.read_bytes())

    def test_frozen_review_is_bitwise_unchanged(self):
        self.assertEqual(hashlib.sha256(FROZEN.read_bytes()).hexdigest(), FROZEN_SHA)

    def test_candidate_same_objects_and_22_fields(self):
        original = self.frozen["components"]["schemas"]
        proposed = self.candidate["components"]["schemas"]
        self.assertEqual(set(proposed), {"PSCustomer", "PSOrder"})
        self.assertEqual(set(original), set(proposed))
        self.assertEqual(sum(len(c["properties"]) for c in proposed.values()), 22)
        for entity in original:
            self.assertEqual(list(original[entity]["properties"]),
                             list(proposed[entity]["properties"]))
            for key, source in original[entity]["properties"].items():
                self.assertEqual(source["type"], proposed[entity]["properties"][key]["type"])
        self.assertEqual(self.candidate["paths"], {})

    def test_typed_keys_and_no_approved_cart_stream(self):
        c = self.candidate["components"]["schemas"]["PSCustomer"]["properties"]
        o = self.candidate["components"]["schemas"]["PSOrder"]["properties"]
        self.assertIn(":customer:", c["source_key"]["pattern"])
        self.assertIn(":order:", o["source_key"]["pattern"])
        self.assertIn(":customer:", o["customer_source_key"]["pattern"])
        self.assertIn(":cart:", o["cart_source_key"]["pattern"])
        self.assertNotIn("PSCart", self.candidate["components"]["schemas"])
        self.assertIn("DO NOT PUBLISH", self.candidate["info"]["title"])
        self.assertIn("review-only", self.candidate["info"]["version"])

if __name__ == "__main__":
    unittest.main()
