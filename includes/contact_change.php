<?php
declare(strict_types=1);

/**
 * Merchant email / mobile self-change — OTP gated only.
 * Never applies contact updates from a plain profile POST.
 * Strategy pack #4: request → OTP to new channel (+ old when real) → apply.
 */

/** Synthetic signup placeholders must not receive "old channel" OTP. */
function isPlaceholderMerchantEmail(string $email): bool
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return true;
    }
    return str_ends_with($email, '@signup.uniweb.co.in');
}

function isPlaceholderMerchantPhone(string $phone, ?int $merchantId = null): bool
{
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    if (strlen($digits) < 10) {
        return true;
    }
    // Email-signup temporary then unique synthetic: +919900000000 → +9199{id padded 8}
    if ($digits === '919900000000' || $digits === '9900000000') {
        return true;
    }
    if ($merchantId !== null && $merchantId > 0) {
        $expected = '9199' . str_pad((string)$merchantId, 8, '0', STR_PAD_LEFT);
        if ($digits === $expected) {
            return true;
        }
    }
    return false;
}

/** Normalize to E.164-ish +91########## for Indian mobiles. */
function normalizeMerchantContactPhone(string $raw): string
{
    $digits = preg_replace('/\D/', '', $raw) ?? '';
    if (strlen($digits) === 10) {
        $digits = '91' . $digits;
    }
    if (strlen($digits) < 11 || strlen($digits) > 15) {
        return '';
    }
    return '+' . ltrim($digits, '+');
}

function merchantContactChangePending(): ?array
{
    $p = $_SESSION['pending_contact_change'] ?? null;
    if (!is_array($p) || empty($p['merchant_id']) || empty($p['field']) || empty($p['new_value'])) {
        return null;
    }
    $started = (int)($p['started_at'] ?? 0);
    if ($started > 0 && (time() - $started) > 600) {
        cancelMerchantContactChange();
        return null;
    }
    return $p;
}

function cancelMerchantContactChange(): void
{
    unset($_SESSION['pending_contact_change']);
}

/**
 * Deliver a 6-digit OTP to email and/or mobile. Demo OTP when no channel sends.
 * @return array{ok:bool,email_sent:bool,mobile_sent:bool,demo_otp:?string,message:string}
 */
function deliverContactChangeOtp(string $otp, string $channel, string $target, string $purpose): array
{
    $msg = "Your UniWeb {$purpose} OTP is {$otp}. Valid 10 minutes. Do not share.";
    $emailSent = false;
    $mobileSent = false;

    if ($channel === 'email') {
        if (filter_var($target, FILTER_VALIDATE_EMAIL) && function_exists('sendPlatformEmail')) {
            $emailSent = (bool)sendPlatformEmail($target, 'UniWeb — verify contact change', $msg);
        }
    } else {
        $digits = preg_replace('/\D/', '', $target) ?? '';
        if (strlen($digits) >= 10 && function_exists('sendWhatsAppOtp')) {
            $wa = sendWhatsAppOtp($digits, $otp);
            $mobileSent = !empty($wa['ok']);
        }
        if (!$mobileSent && strlen($digits) >= 10 && function_exists('sendSMS')) {
            $mobileSent = (bool)sendSMS($digits, $msg);
        }
    }

    if ($emailSent || $mobileSent) {
        return [
            'ok' => true,
            'email_sent' => $emailSent,
            'mobile_sent' => $mobileSent,
            'demo_otp' => null,
            'message' => $channel === 'email'
                ? 'OTP sent to email.'
                : 'OTP sent to mobile via WhatsApp/SMS.',
        ];
    }

    // Dev / keys-pending: still allow verify so the flow works when providers are wired.
    return [
        'ok' => true,
        'email_sent' => false,
        'mobile_sent' => false,
        'demo_otp' => $otp,
        'message' => 'Demo mode: email/SMS/WhatsApp not configured. Use the OTP shown on screen.',
    ];
}

/**
 * @return array{ok:bool,message:string,demo_otp_new:?string,demo_otp_old:?string,require_old:bool}
 */
function requestMerchantEmailChange(array $merchant, string $newEmail): array
{
    if (function_exists('ensureOtpVerificationsSchema')) {
        ensureOtpVerificationsSchema();
    }
    $merchantId = (int)($merchant['id'] ?? 0);
    if ($merchantId <= 0) {
        return ['ok' => false, 'message' => 'Not signed in.', 'demo_otp_new' => null, 'demo_otp_old' => null, 'require_old' => false];
    }

    if (function_exists('checkVelocityBlock') && checkVelocityBlock('contact_change')['blocked']) {
        $v = checkVelocityBlock('contact_change');
        return [
            'ok' => false,
            'message' => 'Too many contact-change attempts. Try again in ~' . $v['retry_after_minutes'] . ' min.',
            'demo_otp_new' => null,
            'demo_otp_old' => null,
            'require_old' => false,
        ];
    }

    $newEmail = strtolower(trim($newEmail));
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Enter a valid email address.', 'demo_otp_new' => null, 'demo_otp_old' => null, 'require_old' => false];
    }

    $oldEmail = strtolower(trim((string)($merchant['email'] ?? '')));
    if ($newEmail === $oldEmail) {
        return ['ok' => false, 'message' => 'That is already your email.', 'demo_otp_new' => null, 'demo_otp_old' => null, 'require_old' => false];
    }

    $db = getDB();
    $dup = $db->prepare('SELECT id FROM merchants WHERE email = ? AND id != ? LIMIT 1');
    $dup->execute([$newEmail, $merchantId]);
    if ($dup->fetch()) {
        return ['ok' => false, 'message' => 'This email is already registered to another account.', 'demo_otp_new' => null, 'demo_otp_old' => null, 'require_old' => false];
    }

    $requireOld = !isPlaceholderMerchantEmail($oldEmail);
    $otpNew = generateOTP($newEmail, 'email_change_new');
    $delNew = deliverContactChangeOtp($otpNew, 'email', $newEmail, 'email change');

    $demoOld = null;
    if ($requireOld) {
        $otpOld = generateOTP($oldEmail, 'email_change_old');
        $delOld = deliverContactChangeOtp($otpOld, 'email', $oldEmail, 'email change confirmation');
        $demoOld = $delOld['demo_otp'];
    }

    if (function_exists('recordVelocityEvent')) {
        recordVelocityEvent('contact_change', 'email:' . $merchantId);
    }

    $_SESSION['pending_contact_change'] = [
        'merchant_id' => $merchantId,
        'field' => 'email',
        'new_value' => $newEmail,
        'old_value' => $oldEmail,
        'require_old' => $requireOld,
        'otp_id_new' => $newEmail,
        'otp_type_new' => 'email_change_new',
        'otp_id_old' => $requireOld ? $oldEmail : null,
        'otp_type_old' => $requireOld ? 'email_change_old' : null,
        'demo_otp_new' => $delNew['demo_otp'],
        'demo_otp_old' => $demoOld,
        'started_at' => time(),
    ];

    $msg = 'Verification required. Enter the OTP sent to your new email'
        . ($requireOld ? ' and your current email' : '')
        . '. Contact is not updated until both codes succeed.';
    if ($delNew['demo_otp'] !== null || $demoOld !== null) {
        $msg = 'Demo mode: use the OTP(s) shown below. Contact is not updated until verification succeeds.';
    }

    return [
        'ok' => true,
        'message' => $msg,
        'demo_otp_new' => $delNew['demo_otp'],
        'demo_otp_old' => $demoOld,
        'require_old' => $requireOld,
    ];
}

/**
 * @return array{ok:bool,message:string,demo_otp_new:?string,demo_otp_old:?string,require_old:bool}
 */
function requestMerchantPhoneChange(array $merchant, string $newPhoneRaw): array
{
    if (function_exists('ensureOtpVerificationsSchema')) {
        ensureOtpVerificationsSchema();
    }
    $merchantId = (int)($merchant['id'] ?? 0);
    if ($merchantId <= 0) {
        return ['ok' => false, 'message' => 'Not signed in.', 'demo_otp_new' => null, 'demo_otp_old' => null, 'require_old' => false];
    }

    if (function_exists('checkVelocityBlock') && checkVelocityBlock('contact_change')['blocked']) {
        $v = checkVelocityBlock('contact_change');
        return [
            'ok' => false,
            'message' => 'Too many contact-change attempts. Try again in ~' . $v['retry_after_minutes'] . ' min.',
            'demo_otp_new' => null,
            'demo_otp_old' => null,
            'require_old' => false,
        ];
    }

    $newPhone = normalizeMerchantContactPhone($newPhoneRaw);
    $newDigits = preg_replace('/\D/', '', $newPhone) ?? '';
    $last10 = substr($newDigits, -10);
    if ($newPhone === '' || !preg_match('/^[6-9]\d{9}$/', $last10)) {
        return ['ok' => false, 'message' => 'Enter a valid 10-digit Indian mobile number.', 'demo_otp_new' => null, 'demo_otp_old' => null, 'require_old' => false];
    }

    $oldPhone = trim((string)($merchant['phone'] ?? ''));
    $oldNorm = normalizeMerchantContactPhone($oldPhone);
    if ($oldNorm !== '' && $newPhone === $oldNorm) {
        return ['ok' => false, 'message' => 'That is already your mobile number.', 'demo_otp_new' => null, 'demo_otp_old' => null, 'require_old' => false];
    }

    $db = getDB();
    $dup = $db->prepare('SELECT id FROM merchants WHERE (phone = ? OR phone LIKE ?) AND id != ? LIMIT 1');
    $dup->execute([$newPhone, '%' . $last10, $merchantId]);
    if ($dup->fetch()) {
        return ['ok' => false, 'message' => 'This mobile number is already registered to another account.', 'demo_otp_new' => null, 'demo_otp_old' => null, 'require_old' => false];
    }

    $requireOld = !isPlaceholderMerchantPhone($oldPhone, $merchantId);
    $otpNew = generateOTP($newPhone, 'phone_change_new');
    $delNew = deliverContactChangeOtp($otpNew, 'phone', $newPhone, 'mobile change');

    $demoOld = null;
    $oldId = $oldNorm !== '' ? $oldNorm : $oldPhone;
    if ($requireOld && $oldId !== '') {
        $otpOld = generateOTP($oldId, 'phone_change_old');
        $delOld = deliverContactChangeOtp($otpOld, 'phone', $oldId, 'mobile change confirmation');
        $demoOld = $delOld['demo_otp'];
    } else {
        $requireOld = false;
    }

    if (function_exists('recordVelocityEvent')) {
        recordVelocityEvent('contact_change', 'phone:' . $merchantId);
    }

    $_SESSION['pending_contact_change'] = [
        'merchant_id' => $merchantId,
        'field' => 'phone',
        'new_value' => $newPhone,
        'old_value' => $oldPhone,
        'require_old' => $requireOld,
        'otp_id_new' => $newPhone,
        'otp_type_new' => 'phone_change_new',
        'otp_id_old' => $requireOld ? $oldId : null,
        'otp_type_old' => $requireOld ? 'phone_change_old' : null,
        'demo_otp_new' => $delNew['demo_otp'],
        'demo_otp_old' => $demoOld,
        'started_at' => time(),
    ];

    $msg = 'Verification required. Enter the OTP sent to your new mobile'
        . ($requireOld ? ' and your current mobile' : '')
        . '. Contact is not updated until verification succeeds.';
    if ($delNew['demo_otp'] !== null || $demoOld !== null) {
        $msg = 'Demo mode: use the OTP(s) shown below. Contact is not updated until verification succeeds.';
    }

    return [
        'ok' => true,
        'message' => $msg,
        'demo_otp_new' => $delNew['demo_otp'],
        'demo_otp_old' => $demoOld,
        'require_old' => $requireOld,
    ];
}

/**
 * Apply pending contact change only after OTP success.
 * @return array{ok:bool,message:string}
 */
function verifyMerchantContactChange(array $merchant, string $otpNew, string $otpOld = ''): array
{
    if (function_exists('ensureOtpVerificationsSchema')) {
        ensureOtpVerificationsSchema();
    }
    $pending = merchantContactChangePending();
    $merchantId = (int)($merchant['id'] ?? 0);
    if (!$pending || (int)$pending['merchant_id'] !== $merchantId) {
        return ['ok' => false, 'message' => 'No pending contact change. Start again.'];
    }

    $otpNew = preg_replace('/\D/', '', $otpNew) ?? '';
    $otpOld = preg_replace('/\D/', '', $otpOld) ?? '';
    if (strlen($otpNew) !== 6) {
        return ['ok' => false, 'message' => 'Enter the 6-digit OTP sent to the new contact.'];
    }

    $requireOld = !empty($pending['require_old']);
    if ($requireOld && strlen($otpOld) !== 6) {
        return ['ok' => false, 'message' => 'Enter the 6-digit OTP sent to your current contact.'];
    }

    if (function_exists('checkVelocityBlock') && checkVelocityBlock('otp_fail')['blocked']) {
        $v = checkVelocityBlock('otp_fail');
        return ['ok' => false, 'message' => 'Too many wrong OTP attempts. Try again in ~' . $v['retry_after_minutes'] . ' min.'];
    }

    $newOk = verifyOTP((string)$pending['otp_id_new'], $otpNew, (string)$pending['otp_type_new']);
    if (!$newOk) {
        if (function_exists('recordVelocityEvent')) {
            recordVelocityEvent('otp_fail', 'contact_new:' . $merchantId);
        }
        return ['ok' => false, 'message' => 'Invalid or expired OTP for the new contact. Request a new code.'];
    }

    if ($requireOld) {
        $oldOk = verifyOTP((string)$pending['otp_id_old'], $otpOld, (string)$pending['otp_type_old']);
        if (!$oldOk) {
            if (function_exists('recordVelocityEvent')) {
                recordVelocityEvent('otp_fail', 'contact_old:' . $merchantId);
            }
            // New OTP already consumed — force restart so email/phone cannot flip half-verified.
            cancelMerchantContactChange();
            return ['ok' => false, 'message' => 'Invalid or expired OTP for your current contact. Start the change again.'];
        }
    }

    $field = (string)$pending['field'];
    $newValue = (string)$pending['new_value'];
    $db = getDB();

    if ($field === 'email') {
        $dup = $db->prepare('SELECT id FROM merchants WHERE email = ? AND id != ? LIMIT 1');
        $dup->execute([$newValue, $merchantId]);
        if ($dup->fetch()) {
            cancelMerchantContactChange();
            return ['ok' => false, 'message' => 'This email was just taken by another account. Choose a different one.'];
        }
        $db->prepare('UPDATE merchants SET email = ? WHERE id = ?')->execute([$newValue, $merchantId]);
        cancelMerchantContactChange();
        if (function_exists('createNotification')) {
            createNotification($merchantId, 'Email updated', 'Your login email was changed after OTP verification.');
        }
        return ['ok' => true, 'message' => 'Email updated successfully after OTP verification.'];
    }

    if ($field === 'phone') {
        $last10 = substr(preg_replace('/\D/', '', $newValue) ?? '', -10);
        $dup = $db->prepare('SELECT id FROM merchants WHERE (phone = ? OR phone LIKE ?) AND id != ? LIMIT 1');
        $dup->execute([$newValue, '%' . $last10, $merchantId]);
        if ($dup->fetch()) {
            cancelMerchantContactChange();
            return ['ok' => false, 'message' => 'This mobile was just taken by another account. Choose a different one.'];
        }
        $db->prepare('UPDATE merchants SET phone = ? WHERE id = ?')->execute([$newValue, $merchantId]);
        cancelMerchantContactChange();
        if (function_exists('createNotification')) {
            createNotification($merchantId, 'Mobile updated', 'Your login mobile was changed after OTP verification.');
        }
        return ['ok' => true, 'message' => 'Mobile number updated successfully after OTP verification.'];
    }

    cancelMerchantContactChange();
    return ['ok' => false, 'message' => 'Unknown contact field.'];
}
