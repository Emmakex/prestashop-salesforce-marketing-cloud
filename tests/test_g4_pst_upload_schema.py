"""G4 PST exact upload file must be equivalent to the certified v0.4 payload."""
import copy
import importlib.util
import json
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "g4_upload_guard", ROOT / "scripts/ci/verify-g4-pst-upload-schema.py"
)
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)


class G4PstUploadSchemaTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.owner = json.loads(
            (ROOT / guard.g4.ACCEPTANCE_PATH).read_text("utf-8")
        )
        cls.release = guard.UPLOADED.read_bytes()
        cls.preview, _ = guard.g4.build_preview(
            (ROOT / guard.g4.BASE_PATH).read_bytes(),
            (ROOT / guard.g4.TYPED_PATH).read_bytes(),
            cls.owner,
        )

    def test_upload_file_and_tenant_writes_are_separate(self):
        result = guard.check_release(self.release, self.preview, self.owner)
        self.assertEqual(result["source"], "ABH_PrestaShop_Data_Cloud")
        self.assertEqual(result["objects"], ["PSCustomer", "PSOrder"])
        self.assertEqual(result["fields"], 22)
        self.assertEqual(len(result["schema_sha256"]), 64)
        for denied in (
            "uploaded_to_salesforce", "data_streams_deployed",
            "dlo_dmo_mapping_deployed", "data_cloud_ingestion_authorized",
            "marketing_sends_authorized"
        ):
            self.assertFalse(result[denied], denied)
        doc = yaml.safe_load(self.release)
        self.assertEqual(doc["components"],
                         yaml.safe_load(self.preview)["components"])
        self.assertEqual(doc["info"]["version"], "0.4.0")

    def test_field_changes_and_extra_objects_always_fail(self):
        for mutation in (
            lambda d: d["components"]["schemas"]["PSOrder"]["properties"]
                ["total_paid"].update(type="string"),
            lambda d: d["components"]["schemas"]["PSCustomer"]["properties"]
                .update(marketing_consent={"type": "boolean"}),
            lambda d: d["components"]["schemas"].update(
                PSCart={"type": "object", "properties": {"id": {"type": "number"}}}
            ),
            lambda d: d["components"]["schemas"]["PSOrder"]
                .update(required=["order_id"]),
            lambda d: d["info"].update(version="0.2.0"),
            lambda d: d.update(paths={"/api/v1/ingest": {"post": {}}}),
        ):
            with self.subTest(mutation=mutation):
                changed = yaml.safe_load(self.release)
                mutation(changed)
                raw = yaml.safe_dump(changed, sort_keys=False).encode()
                with self.assertRaises(ValueError):
                    guard.check_release(raw, self.preview, self.owner)

    def test_unrecorded_owner_direction_cannot_be_replaced_with_permissions(self):
        for bad in (
            lambda a: a["owner_accepts_g3_decisions"]
                .update(schema_review_direction=False),
            lambda a: a["limits"]
                .update(schema_upload_authorized=True),
            lambda a: a["limits"]
                .update(third_party_signatures_inferred=True),
        ):
            with self.subTest(bad=bad):
                changed = copy.deepcopy(self.owner)
                bad(changed)
                with self.assertRaisesRegex(ValueError, "owner_record_invalid"):
                    guard.check_release(self.release, self.preview, changed)


if __name__ == "__main__":
    unittest.main()
