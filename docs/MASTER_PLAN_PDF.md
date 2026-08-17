# UniWeb Master Plan ΓÇö PDF Specs (Aug 2026)
**Updated:** 2026-08-08
**Source:** 3 PDFs (Merchant Agreement Template, Developer Spec Approved Points, Zero-Touch KYC Automation Spec)

---

## PDF Priority Order (from Zero-Touch KYC Spec v2)

| # | Work | Why | Status |
|---|------|-----|--------|
| 1 | Argon2id + secrets hygiene | Security first | Γ£à DONE |
| 2 | Aadhaar mask pipeline | UIDAI | Γ¥î NOT DONE |
| 3 | Automation core (Zero-Touch KYC) | Zero-touch | Γ¥î NOT DONE |
| 4 | Verify ΓåÆ Hold 90m / NightΓåÆ11AM queue ΓåÆ Partner forward | Zero-touch | Γ¥î NOT DONE |
| 5 | Fail path: manual + admin docs + email-in | Human backup | Γ¥î NOT DONE |
| 6 | Agreement eSign + IP/geo stamp | Legal | Γ£à DONE |
| 7 | Checkout branding builder | Merchant UX | Γ£à DONE |
| 8 | Multi QR + idempotent payments | P2M | Γ£à DONE |
| 9 | API docs + sandbox | Integrators | Γ¥î PARTIAL |
| 10 | Self-service | Merchant autonomy | Γ¥î NOT DONE |
| 11 | Beneficiary path + payout adapter | Settlement | Γ£à DONE |

---

## Γ£à COMPLETED (previous sessions + this session)

1. **Argon2id Password Hashing** ΓÇö All 12 files use PASSWORD_ARGON2ID. Login rehash for old bcrypt hashes.
2. **Multiple Instant UPI QR** ΓÇö Bulk generate (fixed + open amount), ZIP download, print, scan tracking.
3. **Agreement eSign + IP/Geo Stamp** ΓÇö eSign module, re-sign on partner approval, IP + lat/long + timestamp on acceptance, geo consent checkbox, Google Maps link in acceptance record.
4. **Checkout Branding Builder** ΓÇö Logo, colors, title, subtitle, custom CSS, success/failure message, redirect URL, live preview.
5. **Idempotent Webhooks** ΓÇö registerGatewayEvent atomic INSERT + unique constraint.
6. **Agreement Template** ΓÇö Clauses in public_legal_page.php, partner addendums, re-sign flow.
7. **Video KYC** ΓÇö Chunked upload, IP + geo + timestamp, malware scan.
8. **KYC Upload** ΓÇö IP + geo + malware scan, doc type validation, retention policy.
9. **Penny Drop / Beneficiary Verify** ΓÇö Exists in payout flow.
10. **Bulk Payout** ΓÇö Exists.
11. **Bank Holiday Calendar** ΓÇö Exists.
12. **DigiLocker/Aadhaar fetch** ΓÇö Exists.
13. **QR Analytics** ΓÇö Scan/payment KPIs, trend charts, top-performing QR table.
14. **QR Expiry + Low-scan Alerts** ΓÇö runQrHealthAlerts() in 10-min cron.
15. **High-Volume UPI Infra** ΓÇö Multiple VA + QR, smart assignment, fast webhook ack, rate limiting, retry/backoff, monitoring.
16. **Agreement Re-sign on Partner Approval** ΓÇö partner_names tracking, requires_resign flag, merchant notification.

---

## Γ¥î REMAINING WORK (in priority order)

### Phase A: Aadhaar Auto-Mask Pipeline (PDF priority #2)
- [ ] Aadhaar upload pe detect ΓåÆ first 8 digits black out ΓåÆ save only masked image
- [ ] Original unmasked file discard immediately
- [ ] Last 4 digits visible for ops
- [ ] Logs mein full Aadhaar number nahi
- [ ] Works for both image upload and DigiLocker fetch

### Phase B: Zero-Touch KYC Automation Core (PDF priority #3-4)
- [ ] Auto-verify via registries: GST (trade name, address, status), PAN, CIN/LLPIN (MCA), IEC (DGFT), IFSC+Account (penny drop), Udyam
- [ ] Inline green tick jab number valid (IFSC, PAN, GSTIN)
- [ ] Name match score store
- [ ] Fail reason simple language (Hindi/English) mapping
- [ ] Hold window 60-90 min after verify ΓåÆ then auto-forward to partner
- [ ] Night schedule: 6 PM ke baad upload ΓåÆ agle din 11 AM ke baad partner forward
- [ ] Admin dashboard mein queue status visible (Pause/Edit/Reject options)
- [ ] Default action = automatic (admin approval mandatory mat banao jab score pass ho)
- [ ] PAN vs Multi-GSTIN: same PAN+same GST block; same PAN+new GSTIN allow + multi-business login switch

### Phase C: KYC Fail Path + Admin Docs + Email-In (PDF priority #5)
- [ ] Merchant 2-3 baar auto-KYC fail ΓåÆ "Need help?" UI + short guide + WhatsApp/support link
- [ ] Manual upload path ΓåÆ Pending Admin queue
- [ ] Admin view/replace/approve docs
- [ ] One-click Forward to Partner API/secure package
- [ ] Status matrix (per merchant, per doc, per partner)
- [ ] Admin actions audit log
- [ ] Email-in KYC lane: merchant portal se docs email ΓåÆ admin queue ΓåÆ same verify/forward rules
- [ ] Email inbound authenticated; virus scan; only allowed file types; link to merchant_id

### Phase D: API Docs + Sandbox + Developer Portal (PDF priority #9)
- [ ] API docs page (api_docs.php exists ΓÇö check completeness)
- [ ] Test vs Live keys alag
- [ ] Webhook signature docs
- [ ] Error code table
- [ ] Secret rotate process docs

### Phase E: Customer Profile Change + OTP (PDF ΓÇö Customer section)
- [ ] Customer can view/change: Name, Email, Mobile, Address
- [ ] Email/Mobile change = OTP verification
- [ ] OTP on sensitive fields
- [ ] Secure session for changes
- [ ] No heavy KYC force on first checkout; optional PAN from profile later

### Phase F: Password Policy + Rate Limiting (PDF ΓÇö Security ops rules)
- [ ] Password policy: min 8-10 chars, not only digits
- [ ] Rate-limit login attempts (check if exists)
- [ ] Purana MD5/SHA/plain mile ΓåÆ force reset

### Phase G: Transparent Checkout + Global UI/UX (PDF ΓÇö Checkout section)
- [ ] Amount, fees, tax clear on checkout
- [ ] T&C/Privacy/Refund links hamesha dikhen
- [ ] Trust badges
- [ ] No hidden charges
- [ ] Mobile-first, bade buttons, simple language
- [ ] Fail reason mapping: Technical code ΓåÆ simple Hindi/English message

### Phase H: Self-Service (PDF priority #10)
- [ ] Merchant self-service portal for common tasks
- [ ] Reduce admin dependency for routine operations

---

## Γ¥î DO NOT BUILD (from PDFs ΓÇö explicitly forbidden)

- Γ¥î Full Customer PPI Wallet / top-up / bahar UPI Scan & Pay (RBI PPI license chahiye)
- Γ¥î Unmasked Aadhaar original image store (UIDAI violation)
- Γ¥î Plain-text passwords (use Argon2id hash only)
- Γ¥î Public open API secrets (security breach)
- Γ¥î Bina verify ke partner forward / Live collect
- Γ¥î Khud ko RBI PA declare karna bina license
- Γ¥î Customer balance hold, top-up, bahar UPI pay bina PPI license
- Γ¥î Raat ko turant partner spam (night schedule: 6 PM ΓåÆ 11 AM)
- Γ¥î Admin approval mandatory jab auto-KYC score pass ho
- Γ¥î Open public upload without merchant session/token
- Γ¥î Unlimited fail loops without cooldown
- Γ¥î Unmasked temp file chhodna
- Γ¥î Email mein full Aadhaar number
- Γ¥î MD5, SHA1, reversible encrypt for passwords
- Γ¥î Hash ko "decrypt" karne ki koshish
- Γ¥î Technical jargon se customer confuse
- Γ¥î Desktop-only layout
- Γ¥î Merchant ko gateway secret keys dena
- Γ¥î Hidden fees
- Γ¥î Ek lamba static form (KYC)
- Γ¥î Saari errors sirf Submit par (inline validation needed)
