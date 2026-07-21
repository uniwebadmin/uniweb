# Multi-Gateway Forward System — Gap Analysis

_Evaluation of the proposed "Single Button Multi-Gateway Forward System" against what UniWeb already has, what is missing, what to avoid, and the recommended path. For advisor review._

## Legend
- ✅ Already built  · 🟡 Partially built  · ❌ Not built  · ⚠️ Risk / caution

---

## Part-by-part status

### PART 1 — Merchant Onboarding & one-time document upload
**Status: ✅ Built**
- Merchant signup (Individual / Proprietorship / Partnership / Pvt Ltd) with **entity-based KYC** (only relevant docs shown per entity).
- Central document store: `kyc_documents` table (Aadhaar, PAN, GST, bank proof, photo, partnership deed, etc.), plus Video KYC and bank details.
- Documents uploaded **once**, stored centrally — reusable for all gateways.

### PART 2 — Admin Unified Dashboard
**Status: ✅ Built (comments 🟡)**
- `admin_kyc.php` — all pending merchants queue + document viewer + Approve / Reject / Verify + Video-KYC verify.
- `admin_view_merchant.php` — single merchant profile (all docs + data on one screen).
- Reject reason exists; free-form per-document comment thread is partial.

### PART 3 — One-Click Multi-Gateway Forward (the core ask)
**Status: 🟡 Partial — this is the real work**
- ✅ `admin_gateway_submit.php` + `submitMerchantToGateway()` already **package** merchant + all KYC docs into a JSON payload and record a submission.
- ❌ Currently **one gateway at a time** (dropdown), not a single "Forward to ALL" button.
- ❌ No **actual delivery** — it records internally; it does **not** email docs or call gateway APIs.
- ❌ No per-gateway onboarding **adapter** (Razorpay Route / Cashfree Easy Split / PayU child MID / Axis VA each need different fields + API).

### PART 4 — Status Tracking
**Status: 🟡 Partial**
- ✅ `gateway_submissions` table tracks status per gateway (draft/submitted/approved/rejected/pending_review) and shows recent submissions.
- ❌ No per-gateway **status matrix** dashboard for a single merchant.
- ❌ No **auto-update** from gateways (needs gateway APIs/webhooks) and no auto-notification on approval.

### PART 5 — Auto Actions
**Status: 🟡 Partial**
- ✅ Virtual Account infra (`includes/axis.php`, `includes/provision.php`); Test↔Live toggle; API key generation exists.
- ❌ "All gateways approved → auto-enable Live" orchestration not built (and see ⚠️ below — per-gateway go-live is safer).

### PART 6 — Additional
**Status: 🟡 / ❌**
- ❌ Document versioning & history (audit trail) — high compliance value, not built.
- ❌ Bulk forward (multiple merchants at once).
- 🟡 Compliance report generation — financial reports exist; a dedicated onboarding-compliance export does not.

---

## ⚠️ What we should NOT do (avoid these)
1. **Do NOT auto-email raw KYC docs (Aadhaar/PAN/bank) to all gateways.** This is a data-protection (DPDP Act) and security risk; Aadhaar sharing is legally restricted. Use each gateway's **secure onboarding API / portal**, not email attachments.
2. **Do NOT assume one unified payload fits all gateways.** Razorpay Route, Cashfree Easy Split, PayU child-merchant, Axis VA each need different fields/formats. A single "send same package to all" will be rejected.
3. **Do NOT auto-enable Live only when ALL gateways approve.** That blocks go-live behind the slowest partner. Go live **per gateway** as each approves, with smart routing.
4. **Do NOT build a full 6-gateway auto-forward orchestrator before we have even ONE gateway's real sub-merchant API + live credentials.** That is premature engineering.
5. **Do NOT remove the human approval gate** before enabling real money movement.

## ⭐ What is actually better for us (recommended path)
1. **Keep** the internal unified profile + one-time packaging (already done).
2. **One-click "Forward to selected/all gateways"** that: (a) creates submission records for each chosen gateway in one click, (b) generates a **pre-filled secure onboarding email/link per gateway** using existing partner templates, (c) calls a **real API adapter only where keys exist** — start with ONE partner (Razorpay Route or Cashfree), prove it, then generalise.
3. **Per-merchant status matrix** on the admin screen (build on `gateway_submissions`): admin updates status → merchant auto-notified. Low risk, high demo value.
4. **Per-gateway go-live** + existing smart routing (not all-or-nothing).
5. **Document versioning / audit trail** — strong for bank/compliance trust.
6. Internally, a **"Unified Compliance API" as an adapter pattern** (one interface, per-gateway adapters) — good architecture, but built incrementally, one adapter at a time.

## ⚠️ What could harm our journey (risks)
- **Regulatory:** aggregating merchants and pushing them live across multiple PA/PGs touches **RBI PA-PG guidelines**. Each PG onboards sub-merchants under its own licence + process. Positioning must match our actual licence path (own PA licence vs. white-label partner).
- **Data protection:** mishandling Aadhaar/PAN in transit or storage → legal exposure.
- **Partner T&Cs:** bulk/auto submission may violate a gateway's onboarding terms; confirm per partner.
- **Over-engineering risk:** time spent on a 6-way orchestrator delays the one integration that actually earns revenue.

## Recommended build order (safe, demo-ready)
1. One-click multi-forward (records for all selected gateways) + per-gateway status matrix + merchant auto-notify. **[low risk, do now]**
2. Pre-filled secure onboarding email/link per gateway (reuse existing templates). **[low risk]**
3. Document versioning / audit trail. **[medium]**
4. First real API adapter for the partner we actually sign. **[after live keys]**
5. Generalise adapters + optional bulk forward. **[later]**
