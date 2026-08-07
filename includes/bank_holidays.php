<?php
declare(strict_types=1);

/**
 * Bank Holiday Calendar — RBI holiday list + weekend detection.
 * Used by settlement engine to skip bank holidays when scheduling settlements.
 * Admin can add custom holidays; system auto-detects Sundays, 2nd/4th Saturdays.
 */

function ensureBankHolidayTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS bank_holidays (
            id INT AUTO_INCREMENT PRIMARY KEY,
            holiday_date DATE NOT NULL UNIQUE,
            holiday_name VARCHAR(200) NOT NULL,
            holiday_type ENUM('national','state','festival','custom','weekend') NOT NULL DEFAULT 'national',
            state VARCHAR(60) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_holiday_date (holiday_date),
            INDEX idx_holiday_state (state)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('ensureBankHolidayTable: ' . $e->getMessage()); }
}

function isBankHoliday(string $date = 'today', ?string $state = null): bool
{
    ensureBankHolidayTable();
    $ts = strtotime($date);
    if ($ts === false) return false;
    $dateStr = date('Y-m-d', $ts);
    $dow = (int)date('N', $ts);

    if ($dow === 7) return true;
    if (isSecondOrFourthSaturday($ts)) return true;

    try {
        $db = getDB();
        if ($state) {
            $st = $db->prepare("SELECT COUNT(*) FROM bank_holidays WHERE holiday_date=? AND is_active=1 AND (state IS NULL OR state=? OR state='')");
            $st->execute([$dateStr, $state]);
        } else {
            $st = $db->prepare("SELECT COUNT(*) FROM bank_holidays WHERE holiday_date=? AND is_active=1 AND (state IS NULL OR state='')");
            $st->execute([$dateStr]);
        }
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function isSecondOrFourthSaturday(int $ts): bool
{
    $dow = (int)date('N', $ts);
    if ($dow !== 6) return false;
    $dayOfMonth = (int)date('j', $ts);
    $weekNum = (int)(($dayOfMonth - 1) / 7) + 1;
    return $weekNum === 2 || $weekNum === 4;
}

function isWorkingDay(string $date = 'today', ?string $state = null): bool
{
    return !isBankHoliday($date, $state);
}

function getNextWorkingDay(string $date = 'today', ?string $state = null): string
{
    $ts = strtotime($date);
    if ($ts === false) $ts = time();
    $ts = strtotime('+1 day', $ts);
    while (isBankHoliday(date('Y-m-d', $ts), $state)) {
        $ts = strtotime('+1 day', $ts);
    }
    return date('Y-m-d', $ts);
}

function calculateSettlementDate(string $txnDate, int $tPlusDays, ?string $state = null): string
{
    $ts = strtotime($txnDate);
    if ($ts === false) $ts = time();
    $workingDaysAdded = 0;
    while ($workingDaysAdded < $tPlusDays) {
        $ts = strtotime('+1 day', $ts);
        if (isWorkingDay(date('Y-m-d', $ts), $state)) {
            $workingDaysAdded++;
        }
    }
    return date('Y-m-d', $ts);
}

function addBankHoliday(string $date, string $name, string $type = 'custom', ?string $state = null): array
{
    ensureBankHolidayTable();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ['ok' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD.'];
    }
    try {
        getDB()->prepare('INSERT INTO bank_holidays (holiday_date, holiday_name, holiday_type, state) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE holiday_name=?, holiday_type=?, state=?, is_active=1')
            ->execute([$date, $name, $type, $state, $name, $type, $state]);
        return ['ok' => true, 'message' => "Holiday added: {$date} — {$name}"];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function removeBankHoliday(int $holidayId): array
{
    ensureBankHolidayTable();
    try {
        getDB()->prepare('UPDATE bank_holidays SET is_active=0 WHERE id=?')->execute([$holidayId]);
        return ['ok' => true, 'message' => 'Holiday deactivated.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function getBankHolidays(int $year = 0, ?string $state = null, int $limit = 200): array
{
    ensureBankHolidayTable();
    if ($year === 0) $year = (int)date('Y');
    $start = "{$year}-01-01";
    $end = "{$year}-12-31";
    try {
        if ($state) {
            $st = getDB()->prepare("SELECT * FROM bank_holidays WHERE holiday_date BETWEEN ? AND ? AND is_active=1 AND (state IS NULL OR state=? OR state='') ORDER BY holiday_date ASC LIMIT {$limit}");
            $st->execute([$start, $end, $state]);
        } else {
            $st = getDB()->prepare("SELECT * FROM bank_holidays WHERE holiday_date BETWEEN ? AND ? AND is_active=1 ORDER BY holiday_date ASC LIMIT {$limit}");
            $st->execute([$start, $end]);
        }
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function getUpcomingHolidays(int $days = 30, ?string $state = null): array
{
    ensureBankHolidayTable();
    $start = date('Y-m-d');
    $end = date('Y-m-d', time() + $days * 86400);
    try {
        if ($state) {
            $st = getDB()->prepare("SELECT * FROM bank_holidays WHERE holiday_date BETWEEN ? AND ? AND is_active=1 AND (state IS NULL OR state=? OR state='') ORDER BY holiday_date ASC");
            $st->execute([$start, $end, $state]);
        } else {
            $st = getDB()->prepare("SELECT * FROM bank_holidays WHERE holiday_date BETWEEN ? AND ? AND is_active=1 ORDER BY holiday_date ASC");
            $st->execute([$start, $end]);
        }
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function seedDefaultHolidays(int $year = 0): array
{
    if ($year === 0) $year = (int)date('Y');
    $holidays = getRbiHolidayList($year);
    $added = 0;
    foreach ($holidays as $h) {
        $res = addBankHoliday($h['date'], $h['name'], $h['type'] ?? 'national', $h['state'] ?? null);
        if (!empty($res['ok'])) $added++;
    }
    return ['ok' => true, 'added' => $added, 'message' => "{$added} RBI holidays seeded for {$year}."];
}

function getRbiHolidayList(int $year): array
{
    $list = [];
    $fixed = [
        ['01-01', 'New Year\'s Day'],
        ['01-26', 'Republic Day'],
        ['08-15', 'Independence Day'],
        ['10-02', 'Gandhi Jayanti'],
        ['12-25', 'Christmas'],
    ];
    foreach ($fixed as [$md, $name]) {
        $list[] = ['date' => "{$year}-{$md}", 'name' => $name, 'type' => 'national'];
    }

    $holi = getHoliDate($year);
    if ($holi) $list[] = ['date' => $holi, 'name' => 'Holi', 'type' => 'festival'];

    $diwali = getDiwaliDate($year);
    if ($diwali) $list[] = ['date' => $diwali, 'name' => 'Diwali', 'type' => 'festival'];

    $dussehra = getDussehraDate($year);
    if ($dussehra) $list[] = ['date' => $dussehra, 'name' => 'Dussehra (Vijaya Dashami)', 'type' => 'festival'];

    $eid = getEidUlFitrDate($year);
    if ($eid) $list[] = ['date' => $eid, 'name' => 'Eid-ul-Fitr', 'type' => 'festival'];

    $christmasEve = "{$year}-12-24";
    $list[] = ['date' => $christmasEve, 'name' => 'Christmas Eve (Holiday for Banks)', 'type' => 'national'];

    return $list;
}

function getHoliDate(int $year): ?string
{
    $dates = [2024 => '2024-03-25', 2025 => '2025-03-14', 2026 => '2026-03-04', 2027 => '2027-03-22', 2028 => '2028-03-11'];
    return $dates[$year] ?? null;
}

function getDiwaliDate(int $year): ?string
{
    $dates = [2024 => '2024-11-01', 2025 => '2025-10-21', 2026 => '2026-11-09', 2027 => '2027-10-29', 2028 => '2028-10-17'];
    return $dates[$year] ?? null;
}

function getDussehraDate(int $year): ?string
{
    $dates = [2024 => '2024-10-12', 2025 => '2025-10-02', 2026 => '2026-10-20', 2027 => '2027-10-09', 2028 => '2028-10-26'];
    return $dates[$year] ?? null;
}

function getEidUlFitrDate(int $year): ?string
{
    $dates = [2024 => '2024-04-11', 2025 => '2025-03-31', 2026 => '2026-03-20', 2027 => '2027-03-10', 2028 => '2028-02-27'];
    return $dates[$year] ?? null;
}

function getWorkingDaysInRange(string $startDate, string $endDate, ?string $state = null): int
{
    $count = 0;
    $ts = strtotime($startDate);
    $end = strtotime($endDate);
    while ($ts <= $end) {
        if (isWorkingDay(date('Y-m-d', $ts), $state)) $count++;
        $ts = strtotime('+1 day', $ts);
    }
    return $count;
}

function getSettlementScheduleLabel(int $tPlusDays): string
{
    return match ($tPlusDays) {
        0 => 'T+0 (Same day)',
        1 => 'T+1 (Next working day)',
        2 => 'T+2 (Second working day)',
        default => "T+{$tPlusDays}",
    };
}
