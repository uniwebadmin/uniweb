<?php
/** Reusable international address fields — include inside a form */
$prefix = $addressPrefix ?? 'addr';
$vals = $addressValues ?? [];
?>
<div id="<?= e($prefix) ?>-address-picker" data-address-picker class="space-y-4">
    <div class="flex items-center justify-between gap-2">
        <p class="text-xs text-brand-400 font-medium uppercase tracking-wide"><?= $addressTitle ?? 'Address' ?></p>
        <button type="button" data-addr="use-location" class="text-xs px-3 py-1.5 rounded-lg border border-brand-500/30 text-brand-400 hover:bg-brand-500/10 flex items-center gap-1 whitespace-nowrap">
            📍 Use my location
        </button>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="sm:col-span-2 relative">
            <label class="text-sm text-gray-400">Street Address <span class="text-gray-600">(type to search, like Google Maps)</span></label>
            <textarea name="address" rows="2" data-addr="street" class="input-field mt-1" placeholder="Start typing your area, village or landmark..."><?= e($vals['address'] ?? '') ?></textarea>
            <div data-addr="suggestions" class="hidden absolute z-20 left-0 right-0 mt-1 bg-dark-800 border border-gray-700 rounded-xl shadow-xl overflow-hidden max-h-56 overflow-y-auto"></div>
            <p data-addr="loc-status" class="text-[11px] text-gray-500 mt-1"></p>
        </div>
        <div>
            <label class="text-sm text-gray-400">Country *</label>
            <select name="country" required data-addr="country" data-value="<?= e($vals['country'] ?? 'India') ?>" class="input-field mt-1"></select>
        </div>
        <div>
            <label class="text-sm text-gray-400">State / Province *</label>
            <select name="state" required data-addr="state" data-value="<?= e($vals['state'] ?? '') ?>" class="input-field mt-1"></select>
        </div>
        <div>
            <label class="text-sm text-gray-400">District *</label>
            <select name="district" required data-addr="district" data-value="<?= e($vals['district'] ?? '') ?>" class="input-field mt-1"></select>
        </div>
        <div>
            <label class="text-sm text-gray-400">City *</label>
            <input type="text" name="city" required data-addr="city" data-value="<?= e($vals['city'] ?? '') ?>" value="<?= e($vals['city'] ?? '') ?>" class="input-field mt-1" placeholder="City name">
        </div>
        <div>
            <label class="text-sm text-gray-400">Postal / ZIP Code *</label>
            <input type="text" name="pincode" required data-addr="postal" maxlength="12" class="input-field mt-1" value="<?= e($vals['pincode'] ?? '') ?>" placeholder="Pin / ZIP">
        </div>
    </div>
</div>
<script src="assets/js/address-picker.js" defer></script>
