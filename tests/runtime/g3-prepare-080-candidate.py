#!/usr/bin/env python3
"""Patch ONLY the disposable 0.8.0 module copy inside PrestaShop CI.

Never touch pssalesforcebridge/ in the git workspace or a merchant runtime.
Source anchors are exact; abort if the old module changes unexpectedly.
"""
from pathlib import Path
import os

if os.environ.get("GITHUB_ACTIONS") != "true" or os.environ.get("G3_RUNTIME_CI_ONLY") != "yes_disposable_prestashop":
    raise SystemExit("G3 0.8.0 fixture patch must run in disposable CI")

base = Path("/tmp/pssfmc-runtime-080/pssalesforcebridge")
path = base / "pssalesforcebridge.php"
if not path.is_file() or not (base / "upgrade").is_dir():
    raise SystemExit("Disposable candidate module copy missing")
source = path.read_text(encoding="utf-8")

def replace_once(old, new):
    global source
    if source.count(old) != 1:
        raise SystemExit("G3 fixture anchor missing or not unique")
    source = source.replace(old, new, 1)

replace_once("$this->version = '0.7.1';", "$this->version = '0.8.0';")
replace_once(
    "require_once __DIR__ . '/src/Compatibility/Platform.php';",
    "require_once __DIR__ . '/src/Compatibility/Platform.php';\n"
    "require_once __DIR__ . '/src/Provider/G3CandidateProviderGate.php';"
)
replace_once(
    """    public function uninstall()
    {
        return $this->uninstallDatabase()
            && $this->uninstallConfiguration()
            && parent::uninstall();
    }""",
    """    public function uninstall()
    {
        // CI-only proof: remove hooks/module registration, retain ALL tables,
        // historical observations and operator configuration.
        // Unlike 0.7.1, never call the destructive uninstall helpers.
        if (getenv('GITHUB_ACTIONS') !== 'true'
            || getenv('G3_RUNTIME_CI_ONLY') !== 'yes_disposable_prestashop'
            || getenv('G3_080_UPGRADE_FIXTURE') !== 'candidate_ci_only') {
            return false;
        }
        return parent::uninstall();
    }"""
)
for method, limit, extra in [
    ("processCustomerQueue", "50", ""),
    ("processOrderQueue", "50", ""),
    ("processAbandonedCartJourneyQueue", "20", "'suppressed' => 0, "),
]:
    old = "    public function " + method + "($limit = " + limit + ")\n    {\n"
    new = old + """        // Provider binding is independent of PS_SFMC_MODE. Never fallback to
        // the previously packaged Engagement transport for ABH/Growth.
        $g3Blocked = G3CandidateProviderGate::prestashopOutboundReason();
        if ($g3Blocked !== '') {
            return ['skipped' => $g3Blocked, 'claimed' => 0, 'processed' => 0,
                """ + extra + """'retry' => 0, 'failed' => 0];
        }

"""
    replace_once(old, new)

replace_once(
    "$this->confirmUninstall = $this->l('Uninstall the connector? Local queue and logs will be removed.');",
    "$this->confirmUninstall = $this->l('Uninstall the connector? Local records and settings will be retained.');"
)
if "$this->version = '0.7.1';" in source:
    raise SystemExit("Candidate version did not replace cleanly")
path.write_text(source, encoding="utf-8")
print("Disposable 0.8.0 provider-egress and non-destructive uninstall candidate patched")
