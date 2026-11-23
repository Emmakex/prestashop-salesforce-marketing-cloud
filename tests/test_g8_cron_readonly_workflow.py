"""G8 manual cron check must remain read-only, unscheduled, and secret-safe."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/g8-cron-readonly-check.yml"


class ReadOnlyCronWorkflowTests(unittest.TestCase):
    def test_no_scheduler_or_sender(self):
        source = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("workflow_dispatch:", source)
        self.assertNotIn("\n  schedule:", source)
        self.assertIn("default: status", source)
        self.assertIn("options:\n          - status\n          - inspect", source)
        self.assertNotIn("dispatch_pair", source)
        self.assertNotIn("operation=dispatch", source)
        self.assertIn('if operation not in ("status", "inspect"):', source)

    def test_secret_and_readonly_response_guards(self):
        source = WORKFLOW.read_text(encoding="utf-8")
        for marker in (
            "contents: read",
            "secrets.G8_STAGING_CRON_ENDPOINT",
            "secrets.G8_STAGING_CRON_TOKEN",
            "--proto '=https'",
            "--max-redirs 0",
            '--data-urlencode "operation=DOLLAR{CRON_OPERATION}"',
            '--data-urlencode "token=DOLLAR{STAGING_CRON_TOKEN}"',
            'data.get("status") != "blocked_test"',
            '("database_write_performed", False)',
            '("http_request_performed", False)',
            '("data_cloud_ingestion_authorized", False)',
            '("automatic_retry_authorized", False)',
            '("marketing_sends_authorized", False)',
            '"staging_events_review_only"',
            'not 0 <= count <= 10',
        ):
            self.assertIn(marker.replace("DOLLAR", "$"), source)
        self.assertNotIn("--verbose", source)
        self.assertNotIn('cat "$response"', source)


if __name__ == "__main__":
    unittest.main()
