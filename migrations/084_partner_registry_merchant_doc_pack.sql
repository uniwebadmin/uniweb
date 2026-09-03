-- Phase 1b: doc_pack_json = merchant KYC codes; partner compliance in separate column.
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS partner_compliance_docs_json TEXT DEFAULT NULL;

-- Remap legacy uppercase compliance codes out of doc_pack_json (safe no-op if already migrated in PHP).
-- MERCHANT_AGREEMENT, PG_MSA, KYC_POLICY, REFUND_POLICY, WEBHOOK_SPEC, API_DOCS, PCI_AOC, SOC2_REPORT
-- are moved to partner_compliance_docs_json on first admin load via migratePartnerRegistryDocPackSemantics().
