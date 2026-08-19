-- Migration 073: Normalize legacy onboarding_state values for KYC state machine.
UPDATE merchants SET onboarding_state = 'kyc_submitted'
WHERE onboarding_state IN ('submitted', 'under_review', '') AND kyc_status IN ('pending', 'submitted', 'clarification');

UPDATE merchants SET onboarding_state = 'kyc_verified'
WHERE onboarding_state = 'verified' AND kyc_status = 'verified';

UPDATE merchants SET onboarding_state = 'kyc_verified'
WHERE kyc_status = 'verified' AND (onboarding_state IS NULL OR onboarding_state = '' OR onboarding_state = 'submitted');
