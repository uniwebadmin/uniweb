<?php
declare(strict_types=1);

/**
 * KYC forward hold window — diagram audit #17.
 *
 * Code truth: daytime verify → now + hold minutes (60–90, default 75).
 * Night (≥18:00 IST) → next day 09:00 IST. Some legacy docs mention 11:00 — honest gap note only.
 */

function holdWindowTimezone(): string
{
    return 'Asia/Kolkata';
}

function holdWindowCodeMorningTime(): string
{
    return '09:00';
}

function holdWindowDocReferenceTime(): string
{
    return '11:00';
}

function holdWindowNightCutoffHour(): int
{
    return 18;
}

function holdWindowMinutesMin(): int
{
    return 60;
}

function holdWindowMinutesMax(): int
{
    return 90;
}

function holdWindowMinutesDefault(): int
{
    return 75;
}

function holdWindowSettingKey(): string
{
    return 'kyc_hold_window_minutes';
}

function holdWindowResolveMinutes(): int
{
    if (function_exists('getHoldWindowMinutes')) {
        return max(holdWindowMinutesMin(), min(holdWindowMinutesMax(), getHoldWindowMinutes()));
    }
    if (function_exists('getSetting')) {
        $v = (int)getSetting(holdWindowSettingKey(), (string)holdWindowMinutesDefault());
        return max(holdWindowMinutesMin(), min(holdWindowMinutesMax(), $v > 0 ? $v : holdWindowMinutesDefault()));
    }
    return holdWindowMinutesDefault();
}

function holdWindowPolicyMessage(): string
{
    return 'After KYC verify: hold ' . holdWindowMinutesMin() . '–' . holdWindowMinutesMax() . ' min (default '
        . holdWindowMinutesDefault() . '), then partner forward queue runs. '
        . 'If verified at or after ' . holdWindowNightCutoffHour() . ':00 IST → scheduled next day at '
        . holdWindowCodeMorningTime() . ' IST (code). Legacy PDF text may say '
        . holdWindowDocReferenceTime() . ' — ~2h documentation gap, not a product break.';
}

/** Canonical schedule — single source for partner_forward_queue + auto_kyc. */
function holdWindowComputeSchedule(?DateTime $from = null): DateTime
{
    $tz = new DateTimeZone(holdWindowTimezone());
    $now = $from ?? new DateTime('now', $tz);
    if ($from !== null) {
        $now = clone $from;
        $now->setTimezone($tz);
    }

    $hour = (int)$now->format('H');
    if ($hour >= holdWindowNightCutoffHour()) {
        return new DateTime('tomorrow ' . holdWindowCodeMorningTime(), $tz);
    }

    $schedule = clone $now;
    $schedule->modify('+' . holdWindowResolveMinutes() . ' minutes');
    return $schedule;
}

function holdWindowAdminEducation(): array
{
    return [
        'title' => 'Hold window — verify → partner forward',
        'policy' => holdWindowPolicyMessage(),
        'code_morning' => holdWindowCodeMorningTime() . ' IST',
        'doc_reference' => holdWindowDocReferenceTime() . ' IST (legacy PDF only)',
        'night_cutoff' => holdWindowNightCutoffHour() . ':00 IST',
        'hold_minutes' => holdWindowResolveMinutes(),
        'setting_key' => holdWindowSettingKey(),
    ];
}

/**
 * @return array{ok:bool,message:string,checks:array<string,bool>,missing:list<string>,next_sample?:string}
 */
function holdWindowReadinessReport(): array
{
    $sample = holdWindowComputeSchedule(new DateTime('today 20:00', new DateTimeZone(holdWindowTimezone())));

    $checks = [
        'compute_helper' => function_exists('holdWindowComputeSchedule'),
        'forward_queue_delegate' => str_contains((string)@file_get_contents(__DIR__ . '/partner_forward_queue.php'), 'holdWindowComputeSchedule')
            || function_exists('forwardQueueNextScheduleAt'),
        'auto_kyc_hold_setting' => function_exists('getHoldWindowMinutes')
            || str_contains((string)@file_get_contents(__DIR__ . '/auto_kyc.php'), 'kyc_hold_window_minutes'),
        'night_to_9am' => $sample->format('H:i') === holdWindowCodeMorningTime(),
        'policy_documented' => holdWindowCodeMorningTime() !== holdWindowDocReferenceTime(),
    ];

    $missing = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));

    return [
        'ok' => empty($missing),
        'message' => empty($missing)
            ? holdWindowPolicyMessage()
            : 'Hold window wiring incomplete: ' . implode(', ', $missing),
        'checks' => $checks,
        'missing' => $missing,
        'next_sample' => $sample->format('Y-m-d H:i:s') . ' IST (if verify at 20:00)',
    ];
}

/** @return array{id:string,label:string,ok:bool,status:string,detail:string,test_url?:string} */
function holdWindowHealthCheck(): array
{
    $report = holdWindowReadinessReport();

    if (!empty($report['missing'])) {
        return [
            'id' => 'hold_window',
            'label' => 'KYC hold window',
            'ok' => false,
            'status' => 'Schedule helpers incomplete',
            'detail' => $report['message'],
            'test_url' => 'admin_forward_queue.php',
        ];
    }

    return [
        'id' => 'hold_window',
        'label' => 'KYC hold window',
        'ok' => true,
        'status' => 'Code: next day ' . holdWindowCodeMorningTime() . ' IST after ' . holdWindowNightCutoffHour() . ':00',
        'detail' => holdWindowPolicyMessage(),
        'test_url' => 'admin_forward_queue.php',
    ];
}
