"""Proprietary copyright distribution contract for Eduardo Yauri.

Checks only local repository contents. No network, credentials or dispatch.
"""
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class CopyrightLicenseContract(unittest.TestCase):
    def test_same_notice_repository_and_module(self):
        root_license = (ROOT / "LICENSE").read_text(encoding="utf-8")
        embedded_license = (ROOT / "pssalesforcebridge/LICENSE").read_text(
            encoding="utf-8"
        )
        self.assertEqual(root_license, embedded_license)
        for text in (
            "Copyright © 2026 Eduardo Yauri.",
            "Todos los derechos reservados.",
            "All rights reserved.",
            "PROPRIETARY LICENSE",
            "MIT License",
        ):
            self.assertIn(text, root_license)
        self.assertNotIn("Permission is hereby granted, free of charge", root_license)

    def test_package_metadata_and_public_author_are_distinct(self):
        composer = json.loads(
            (ROOT / "pssalesforcebridge/composer.json").read_text(
                encoding="utf-8"
            )
        )
        module = (ROOT / "pssalesforcebridge/pssalesforcebridge.php").read_text(
            encoding="utf-8"
        )
        self.assertEqual(composer["license"], "proprietary")
        self.assertEqual(composer["authors"], [{"name": "ALFABH"}])
        self.assertIn("Copyright (c) 2026 Eduardo Yauri.", module)
        self.assertIn("$this->author = 'ALFABH';", module)


if __name__ == "__main__":
    unittest.main()
