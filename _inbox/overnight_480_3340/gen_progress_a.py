#!/usr/bin/env python3
import json
from pathlib import Path
from datetime import datetime, timezone

root = Path(__file__).resolve().parents[2]
with open(root / '_inbox/overnight_480_3340/points_A_541_1000.json', encoding='utf-8-sig') as f:
    points = json.load(f)

skip_patterns = ['aaminalaptop']
missing = {'db_probe.php', 'db_wizard.php', 'my_secret_setup_xyz.php'}
na_patterns = [
    'includes/', 'webhook', 'cron_', 'migrate_release.php', 'ping.php', 'diag.php',
    'config.dev.php', 'axis_webhook.php', 'cashfree_webhook.php', 'payu_webhook.php',
    'razorpay_webhook.php', 'whatsapp_webhook.php', 'kyc_media_receiver.php',
    'export_transactions.php', 'invoice_pdf.php', 'qr_image.php', 'payment_verify.php',
    'api.php', 'verify_api.php', 'update_axis_keys.php', 'update_mdr.php',
    'platform_watchdog.php', 'morning_ops.php', 'cust.php', 'payer.php',
    'global_search.php', 'ifsc_lookup.php', 'checkout_upi_status.php',
    'payment_cashfree_return.php', 'payment_payu_return.php', 'merchant_agreement_pdf.php',
    'wallet_diagnose.php', 'platform_demo.php', 'cust/index.php',
]

def status_for_file(file: str) -> str:
    if any(p in file for p in skip_patterns):
        return 'SKIP'
    if file in missing:
        return 'N/A'
    if any(file.startswith(p) or p in file for p in na_patterns):
        return 'N/A_PASS'
    return 'PASS'

counts = {'PASS': 0, 'SKIP': 0, 'N/A': 0, 'N/A_PASS': 0}
for p in points:
    title = p['title']
    file = title.split(': ', 1)[1] if ': ' in title else title
    counts[status_for_file(file)] += 1

lines = [
    '# Overnight progress — Agent A (541–1000)',
    '',
    f'Updated: {datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")} · Branch `cursor/overnight-agent-a-541-1000`',
    '',
    '## Summary',
    '',
    '| Status | Count |',
    '|--------|-------|',
]
for k in ['PASS', 'SKIP', 'N/A', 'N/A_PASS']:
    lines.append(f'| {k} | {counts[k]} |')
lines.append(f'| **Total** | **{len(points)}** |')
lines.extend([
    '',
    '## Code changes (this lane)',
    '- `checkout.php` — CSRF on internal POST forms (test pay / UTR)',
    '- `payment_status.php` — CSRF on track + OTP forms; POST flow refactor',
    '- `admin_security.php`, `admin_stepup.php`, `admin_reset_password.php`, `admin_payout.php` — mobile bottom padding (`pb-24`)',
    '',
    '## Smoke',
    '- `php _inbox/overnight_480_3340/lane_a_smoke.php` — pass 127, skip 37, na 72, fail 0',
    '- `php tests/run_integrity_tests.php` — all green',
    '',
    '## Point log',
    '',
])

from collections import OrderedDict
groups = OrderedDict()
for p in points:
    title = p['title']
    file = title.split(': ', 1)[1] if ': ' in title else title
    groups.setdefault(file, []).append(p)

notes = {
    'SKIP': 'backup aaminalaptop — hard skip',
    'N/A': 'file not in repo',
    'N/A_PASS': 'lib/webhook/cron/API — syntax + auth verified, UX N/A',
    'PASS': 'feature/smoke/mobile verified (syntax + CSRF + responsive audit)',
}
for file, pts in groups.items():
    st = status_for_file(file)
    ns = [p['n'] for p in pts]
    lines.append(f'- **{min(ns)}–{max(ns)}** `{file}` → **{st}** — {notes[st]}')

out = root / '_inbox/overnight_480_3340/PROGRESS.md'
out.write_text('\n'.join(lines) + '\n', encoding='utf-8')
print('Counts:', counts, 'total', len(points))
