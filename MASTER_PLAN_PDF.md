# UniWeb Master Plan — PDF Specs + Multiple QR + Checkout Customize
**Created:** 2026-08-07  
**Source:** 3 PDFs (Merchant Agreement Template, Developer Spec Approved Points, Zero-Touch KYC Automation Spec) + Owner verbal instructions

---

## PDF 1: Merchant Agreement Template
**Key points:**
- Agreement between UniWeb (Platform) and Merchant
- Aadhaar eSign + Partner Addendums
- Schedule A = Merchant Application, Schedule B = Partners enabled
- When new Partner goes live → Merchant must acknowledge/re-sign
- Services: hosted checkout, payment links, static/dynamic QR, webhooks/APIs/sandbox
- Merchant obligations: true KYC, lawful use, cooperate on disputes, secure credentials, 2FA
- Fees: blended or separate, GST exclusive, revision with prior notice
- Settlement: follows active Partner's cycle and rules
- **Status:** Agreement re-sign logic already implemented (partner_names tracking + requires_resign). Agreement template clauses already in `includes/public_legal_page.php`.

## PDF 2: Developer Spec — Approved Points
**DO (build these):**
1. Merchant Agreement + Aadhaar eSign + auto distribution
2. Transparent Checkout + simple global UI/UX
3. API Docs + Sandbox + Webhook + Error guide
4. Customer: Email/Mobile/Address change + OTP
5. Aadhaar first-8-digit auto mask before save
6. KYC fallback + Admin Document Manager + Partner forward
7. Checkout branding builder (merchant logo, colors, brand name)
8. Multi QR + idempotent payments (P2M)

**DON'T (do NOT build these):**
- Full Customer PPI Wallet / bahar UPI Scan & Pay (license chahiye)
- Unmasked Aadhaar original image store
- API keys/secrets public ya frontend
- Payment Aggregator claim bina PA license
- Plain-text passwords

**Priority order from PDF:**
1. Password hash all portals + no secrets in code (Security baseline)
2. Aadhaar auto-mask on upload (UIDAI)
3. Frictionless KYC + draft + mobile handoff (Onboarding)
4. KYC fallback + Admin docs + partner forward (Ops continuity)
5. Agreement + eSign + IP/Geo + emails (Legal pack)
6. Transparent + checkout branding (Trust/conversion)
7. Idempotent webhooks + multi QR (P2M)
8. API Docs + Sandbox (Integrators)
9. Self-service
10. Beneficiary path + payout adapter (Settlement ready)

## PDF 3: Zero-Touch KYC Automation Spec
**Key points:**
- Auto-verify KYC docs via registries (GST, MCA, PAN, IEC, IFSC, Udyam, Aadhaar)
- Hold window 60-90 min after verify → then auto-forward to partner
- Night schedule: 6 PM ke baad upload → agle din 11 AM ke baad partner forward
- Merchant 2-3 baar fail → "Need help?" UI + manual upload path
- Email-in KYC lane: merchant portal se docs email → admin queue → same path
- Checkout Design: logo, colors, brand name, success/failure message, redirect URL, live preview
- Argon2id password hashing (PASSWORD_ARGON2ID)
- Aadhaar: OCR → mask first 8 digits → save masked → discard original
- DON'T: raat ko turant partner spam, bina verify ke forward, unmasked Aadhaar, PPI wallet, public API secrets

---

## Owner's Additional Requirements (verbal)
1. **Multiple Instant QR Code generate** — fixed amount + open amount (no amount) dono options
2. **Checkout page customize** — merchant apna logo/color/theme laga sake (DONE)
3. **Agreement re-sign** — partner approval pe merchant ko sign karna pade, naya partner aaye toh re-sign (DONE)
4. PDF mein jo "na kare" hai wo na karein

---

## Master Plan — Task List

### Phase 1: Multiple Instant QR Code (PRIORITY — owner's #1 request)
- [ ] `qr_code.php` mein "Bulk Generate" option already exists (one name per line, max 50)
- [ ] Add "Fixed Amount" vs "Open Amount" (no amount) toggle in bulk create
- [ ] Fixed amount: har QR pe specific amount set ho
- [ ] Open amount: QR bina amount ke generate ho, customer checkout pe amount enter kare
- [ ] Bulk download as ZIP already exists
- [ ] Preview before generate

### Phase 2: Aadhaar Auto-Mask Pipeline (PDF priority #2)
- [ ] Aadhaar upload pe OCR/detect → first 8 digits black out → save only masked
- [ ] Original unmasked file discard
- [ ] Last 4 digits visible for ops
- [ ] Logs mein full Aadhaar number nahi

### Phase 3: Argon2id Password Hashing (PDF priority #1)
- [ ] Check all password create/change/reset paths use PASSWORD_ARGON2ID
- [ ] Purana MD5/SHA/plain mile to login par rehash ya force reset
- [ ] Password policy: min 8-10 chars, not only digits
- [ ] Rate-limit login attempts (already exists?)

### Phase 4: Zero-Touch KYC Automation (PDF priority #3-4)
- [ ] Auto-verify via registries: GST, PAN, CIN, IEC, IFSC, Udyam, Aadhaar
- [ ] Hold window 60-90 min → auto-forward to partner
- [ ] Night schedule: 6 PM ke baad → next day 11 AM forward
- [ ] Merchant 2-3 fail → "Need help?" UI + manual upload
- [ ] Email-in KYC lane
- [ ] Inline green tick on valid numbers
- [ ] Name match score store
- [ ] Fail reason simple language (Hindi/English)

### Phase 5: Agreement eSign + IP/Geo Stamp (PDF priority #6)
- [x] eSign module created (includes/esign.php)
- [x] Agreement re-sign on partner approval
- [ ] IP + lat/long stamp on agreement PDF (consent-based geo)
- [ ] Agreement PDF auto-distribution to merchant + partner

### Phase 6: Checkout Branding Builder (PDF priority #7)
- [x] Checkout customize page created (checkout_customize.php)
- [x] Logo, colors, button color, title, custom CSS
- [ ] Success/Failure message customization
- [ ] Redirect URL after payment
- [ ] Live preview (basic preview added)

### Phase 7: Multi QR + Idempotent Payments (PDF priority #8)
- [x] Bulk QR create exists
- [x] Idempotent webhooks exist (registerGatewayEvent)
- [ ] Fixed + Open amount QR bulk generate (Phase 1)
- [ ] QR analytics exists

### Phase 8: KYC Fallback + Admin Docs + Partner Forward (PDF priority #4)
- [ ] Auto-KYC 3 fail → Manual Upload → Pending Admin
- [ ] Admin view/replace/approve docs
- [ ] One-click Forward to Partner API/secure package
- [ ] Status matrix
- [ ] Admin actions audit

### Phase 9: API Docs + Sandbox (PDF priority #8)
- [ ] API docs page exists (api_docs.php)
- [ ] Test vs Live keys alag
- [ ] Webhook signature docs
- [ ] Error code table

### What NOT to build (from PDFs):
- ❌ Full Customer PPI wallet / top-up / bahar UPI Scan & Pay
- ❌ Unmasked Aadhaar retention
- ❌ Plain-text passwords
- ❌ Public open API secrets
- ❌ Bina verify ke partner forward / Live collect
- ❌ Khud ko RBI PA declare karna bina license
- ❌ Customer balance hold, top-up, bahar UPI pay bina PPI license

---

## Already Completed (previous sessions):
- [x] Penny Drop API structure
- [x] Bulk Payout
- [x] Bank Holiday Calendar
- [x] eSign module
- [x] DigiLocker/Aadhaar fetch
- [x] Checkout Customize (logo, colors, theme)
- [x] Mandate Debit gateway adapters
- [x] Agreement re-sign on partner approval
- [x] Agreement template clauses in public_legal_page.php
