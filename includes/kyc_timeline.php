<?php
declare(strict_types=1);

/**
 * KYC timeline + doc matrix scaffold (bucket 4: 3285–3314).
 * Partner forward actions are UI-only until owner provides gateway keys.
 */

function kycTimelineDocTypes(): array
{
    return [
        'aadhaar' => 'Aadhaar',
        'pan' => 'PAN',
        'gst' => 'GST',
        'mca' => 'MCA / CIN',
        'udyam' => 'Udyam',
        'cancelled_cheque' => 'Cancelled Cheque',
        'board_resolution' => 'Board Resolution',
        'partnership_deed' => 'Partnership Deed',
        'video_kyc' => 'Video KYC',
        'selfie' => 'Selfie',
        'address_proof' => 'Address Proof',
    ];
}

function kycTimelineStepsForMerchant(int $merchantId): array
{
    ensureKycSchema();
    $db = getDB();
    $steps = [];
    foreach (kycTimelineDocTypes() as $docType => $label) {
        $dbType = match ($docType) {
            'mca' => 'cin',
            'cancelled_cheque' => 'bank_cheque',
            default => $docType,
        };
        $st = $db->prepare('SELECT status, created_at, reviewed_at FROM kyc_documents WHERE merchant_id=? AND doc_type=? ORDER BY created_at DESC LIMIT 1');
        $st->execute([$merchantId, $dbType]);
        $row = $st->fetch() ?: null;
        $steps[] = [
            'doc_type' => $docType,
            'label' => $label,
            'status' => $row['status'] ?? 'missing',
            'uploaded_at' => $row['created_at'] ?? null,
            'reviewed_at' => $row['reviewed_at'] ?? null,
            'show_in_timeline' => true,
            'forward_to_partner' => 'scaffold',
        ];
    }
    return $steps;
}

function renderKycTimeline(int $merchantId, bool $staffView = false): string
{
    $steps = kycTimelineStepsForMerchant($merchantId);
    $html = '<div class="kyc-timeline space-y-3" role="list" aria-label="KYC document timeline">';
    foreach ($steps as $step) {
        $status = (string)$step['status'];
        $badge = match ($status) {
            'approved' => 'text-emerald-400',
            'rejected' => 'text-red-400',
            'pending', 'submitted' => 'text-amber-400',
            default => 'text-gray-500',
        };
        $html .= '<div class="flex flex-wrap items-center justify-between gap-2 glass rounded-lg px-4 py-3 border border-gray-800" role="listitem">';
        $html .= '<div><p class="text-sm font-medium">' . e($step['label']) . '</p>';
        if ($step['uploaded_at']) {
            $html .= '<p class="text-[10px] text-gray-600">Uploaded ' . e(formatDate($step['uploaded_at'])) . '</p>';
        }
        $html .= '</div><span class="text-xs font-semibold ' . $badge . '">' . e(ucfirst($status)) . '</span>';
        if ($staffView) {
            $html .= '<span class="text-[10px] text-gray-600 w-full">Partner forward: scaffold (owner keys required)</span>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

function kycProgressPercent(int $merchantId): int
{
    $steps = kycTimelineStepsForMerchant($merchantId);
    if ($steps === []) {
        return 0;
    }
    $approved = count(array_filter($steps, static fn(array $s): bool => ($s['status'] ?? '') === 'approved'));
    return (int)round(($approved / count($steps)) * 100);
}

/** Location autocomplete scaffold — Google Places requires owner API key. */
function renderLocationAutocompleteScaffold(string $inputName = 'business_address'): string
{
    return '<div class="location-autocomplete-scaffold" data-scaffold="google-places">'
        . uxFormLabel(uxFieldId($inputName), 'Business address')
        . '<input type="text" name="' . e($inputName) . '" id="' . e(uxFieldId($inputName)) . '" class="input-field mt-1" placeholder="Start typing address…" autocomplete="street-address" aria-describedby="' . e(uxFieldId($inputName)) . '-hint">'
        . '<p id="' . e(uxFieldId($inputName)) . '-hint" class="text-[10px] text-gray-600 mt-1">Google Location API dropdown scaffold — owner must paste Places API key to enable autocomplete.</p>'
        . '<button type="button" class="mt-2 text-xs text-sky-400 no-print" disabled title="Requires Google API key from owner">Use my location (scaffold)</button>'
        . '</div>';
}
