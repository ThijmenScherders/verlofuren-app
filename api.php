<?php
require_once __DIR__ . '/config.php';

$user = require_login();

header('Content-Type: application/json; charset=utf-8');

function json_error(string $message, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function time_to_minutes(string $time): int
{
    $parts = explode(':', $time);

    return ((int)$parts[0] * 60) + (int)$parts[1];
}

function working_minutes(string $startTime, string $endTime): int
{
    $workStart = 8 * 60 + 45;
    $workEnd = 16 * 60 + 30;

    $start = max(time_to_minutes($startTime), $workStart);
    $end = min(time_to_minutes($endTime), $workEnd);

    return max(0, $end - $start);
}

function hours_for_range(
    array $employee,
    string $start,
    string $end,
    string $startTime,
    string $endTime
): float {

    $hoursByDay = [
        1 => (float)$employee['monday_hours'],
        2 => (float)$employee['tuesday_hours'],
        3 => (float)$employee['wednesday_hours'],
        4 => (float)$employee['thursday_hours'],
        5 => (float)$employee['friday_hours'],
        6 => (float)$employee['saturday_hours'],
        7 => (float)$employee['sunday_hours']
    ];

    $from = new DateTimeImmutable($start);
    $to = new DateTimeImmutable($end);

    if ($to < $from) {
        throw new InvalidArgumentException(
            'Einddatum ligt vóór de begindatum.'
        );
    }

    $holidays = dutch_holidays(
        (int)$from->format('Y'),
        (int)$to->format('Y')
    );

    $total = 0.0;

    for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {

        $date = $d->format('Y-m-d');
        $weekday = (int)$d->format('N');

        if (isset($holidays[$date])) {
            continue;
        }

        $scheduledHours = $hoursByDay[$weekday];

        if ($scheduledHours <= 0) {
            continue;
        }

        if ($start === $end) {
            $minutes = working_minutes($startTime, $endTime);
            $total += min($minutes / 60, $scheduledHours);
            continue;
        }

        if ($date === $start) {
            $minutes = working_minutes($startTime, '16:30');
            $total += min($minutes / 60, $scheduledHours);
            continue;
        }

        if ($date === $end) {
            $minutes = working_minutes('08:45', $endTime);
            $total += min($minutes / 60, $scheduledHours);
            continue;
        }

        $total += $scheduledHours;
    }

    return round($total, 2);
}

function valid_date(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function get_target_user(array $user, array $input): int
{
    return $user['role'] === 'admin'
        ? (int)($input['user_id'] ?? $user['id'])
        : (int)$user['id'];
}

function check_entry_access(array $user, array $entry): void
{
    if (
        $user['role'] !== 'admin' &&
        (int)$entry['user_id'] !== (int)$user['id']
    ) {
        json_error('Geen toegang.', 403);
    }
}

/*
|--------------------------------------------------------------------------
| Vrijdag na Hemelvaartsdag berekenen
|--------------------------------------------------------------------------
*/

function mandatory_bridge_days(int $year, int $endYear): array
{
    $days = [];

    for ($y = $year; $y <= $endYear; $y++) {

        $easter = new DateTimeImmutable('@' . easter_date($y));

        $easter = $easter->setTimezone(
            new DateTimeZone('Europe/Amsterdam')
        );

        $ascension = $easter->modify('+39 days');
        $bridgeDay = $ascension->modify('+1 day');

        $days[$bridgeDay->format('Y-m-d')] =
            'Verplichte vrije dag na Hemelvaartsdag';
    }

    return $days;
}

/*
|--------------------------------------------------------------------------
| GET: kalendergegevens en jaarsaldo ophalen
|--------------------------------------------------------------------------
*/

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {

    /*
    |--------------------------------------------------------------------------
    | Saldo van een specifiek kalenderjaar ophalen
    |--------------------------------------------------------------------------
    |
    | Voorbeeld:
    | api.php?balance_year=2027
    |
    */

    if (isset($_GET['balance_year'])) {

        $year = filter_var(
            $_GET['balance_year'],
            FILTER_VALIDATE_INT
        );

        if ($year === false || $year < 2000 || $year > 2200) {
            json_error('Ongeldig kalenderjaar.');
        }

        $yearStart = $year . '-01-01';
        $nextYearStart = ($year + 1) . '-01-01';

        $annualAllowance = (float)$user['allowance_hours'];

        /*
        |--------------------------------------------------------------------------
        | Opgenomen verlof van het geselecteerde jaar
        |--------------------------------------------------------------------------
        */

        $balanceStmt = db()->prepare(
            'SELECT COALESCE(SUM(hours), 0)
             FROM leave_entries
             WHERE user_id = ?
               AND start_date >= ?
               AND start_date < ?'
        );

        $balanceStmt->execute([
            $user['id'],
            $yearStart,
            $nextYearStart
        ]);

        $used = (float)$balanceStmt->fetchColumn();

        /*
        |--------------------------------------------------------------------------
        | Verplichte brugdag van het geselecteerde jaar
        |--------------------------------------------------------------------------
        */

        $bridgeDays = mandatory_bridge_days($year, $year);

        $bridgeDate = array_key_first($bridgeDays);

        $bridgeHours = (float)$user['leave_day_hours'];

        if ($bridgeHours <= 0) {
            $bridgeHours = 7.75;
        }

        $bridgeCheck = db()->prepare(
            'SELECT COUNT(*)
             FROM leave_entries
             WHERE user_id = ?
               AND start_date <= ?
               AND end_date >= ?'
        );

        $bridgeCheck->execute([
            $user['id'],
            $bridgeDate,
            $bridgeDate
        ]);

        $bridgeAlreadyBooked = (int)$bridgeCheck->fetchColumn() > 0;

        if (!$bridgeAlreadyBooked) {
            $used += $bridgeHours;
        }

        /*
        |--------------------------------------------------------------------------
        | Tijd-voor-tijd van het geselecteerde jaar
        |--------------------------------------------------------------------------
        */

        $creditStmt = db()->prepare(
            'SELECT COALESCE(SUM(minutes), 0)
             FROM time_credits
             WHERE user_id = ?
               AND work_date >= ?
               AND work_date < ?'
        );

        $creditStmt->execute([
            $user['id'],
            $yearStart,
            $nextYearStart
        ]);

        $creditMinutes = (int)$creditStmt->fetchColumn();

        $creditHours = $creditMinutes / 60;

        /*
        |--------------------------------------------------------------------------
        | Totaal saldo berekenen
        |--------------------------------------------------------------------------
        */

        $totalAvailable = $annualAllowance + $creditHours;
        $remaining = $totalAvailable - $used;

        $hoursPerDay = (float)$user['leave_day_hours'];

        if ($hoursPerDay <= 0) {
            $hoursPerDay = 7.75;
        }

        echo json_encode([
            'year' => $year,
            'annual_allowance' => round($annualAllowance, 2),
            'credit_hours' => round($creditHours, 2),
            'total_available' => round($totalAvailable, 2),
            'used' => round($used, 2),
            'remaining' => round($remaining, 2),
            'hours_per_day' => round($hoursPerDay, 2),
            'available_days' => round($totalAvailable / $hoursPerDay, 2),
            'used_days' => round($used / $hoursPerDay, 2),
            'remaining_days' => round($remaining / $hoursPerDay, 2)
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Gewone kalendergegevens ophalen
    |--------------------------------------------------------------------------
    */

    $start = (string)($_GET['start'] ?? date('Y-m-01'));
    $end = (string)($_GET['end'] ?? date('Y-m-t'));

    if (!valid_date($start) || !valid_date($end)) {
        json_error('Ongeldige kalenderdatums.');
    }

    $isAdmin = $user['role'] === 'admin';

    $sql = 'SELECT
                l.id,
                l.user_id,
                l.start_date,
                l.end_date,
                l.start_time,
                l.end_time,
                l.hours,
                l.calculation_mode,
                l.note,
                u.name
            FROM leave_entries l
            JOIN users u ON u.id = l.user_id
            WHERE l.start_date <= ? AND l.end_date >= ?';

    $params = [$end, $start];

    if (!$isAdmin) {
        $sql .= ' AND l.user_id = ?';
        $params[] = $user['id'];
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $events = [];

    // Gewone verlofboekingen.
    foreach ($stmt->fetchAll() as $r) {

        $events[] = [
            'id' => (string)$r['id'],

            'title' =>
                ($isAdmin ? $r['name'] . ' — ' : '') .
                number_format((float)$r['hours'], 2, ',', '.') .
                ' uur verlof' .
                ($r['note'] ? ' · ' . $r['note'] : ''),

            'start' => $r['start_date'],

            'end' => (new DateTimeImmutable($r['end_date']))
                ->modify('+1 day')
                ->format('Y-m-d'),

            'allDay' => true,

            'backgroundColor' => '#dc2626',
            'borderColor' => '#b91c1c',
            'textColor' => '#ffffff',

            'extendedProps' => [
                'entry_type' => 'leave',
                'user_id' => (int)$r['user_id'],
                'hours' => (float)$r['hours'],
                'mode' => $r['calculation_mode'],
                'note' => $r['note'],
                'name' => $r['name'],
                'start_time' => $r['start_time'],
                'end_time' => $r['end_time']
            ]
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Tijd-voor-tijdboekingen ophalen
    |--------------------------------------------------------------------------
    */

    $creditSql = 'SELECT
                    t.id,
                    t.user_id,
                    t.work_date,
                    t.minutes,
                    t.note,
                    u.name
                  FROM time_credits t
                  JOIN users u ON u.id = t.user_id
                  WHERE t.work_date >= ? AND t.work_date <= ?';

    $creditParams = [$start, $end];

    if (!$isAdmin) {
        $creditSql .= ' AND t.user_id = ?';
        $creditParams[] = $user['id'];
    }

    $creditStmt = db()->prepare($creditSql);
    $creditStmt->execute($creditParams);

    foreach ($creditStmt->fetchAll() as $r) {

        $creditHours = (int)$r['minutes'] / 60;

        $events[] = [
            'id' => 'credit-' . $r['id'],

            'title' =>
                ($isAdmin ? $r['name'] . ' — ' : '') .
                '+' .
                number_format($creditHours, 2, ',', '.') .
                ' uur tijd-voor-tijd' .
                ($r['note'] ? ' · ' . $r['note'] : ''),

            'start' => $r['work_date'],

            'end' => (new DateTimeImmutable($r['work_date']))
                ->modify('+1 day')
                ->format('Y-m-d'),

            'allDay' => true,

            'backgroundColor' => '#26965b',
            'borderColor' => '#26965b',
            'textColor' => '#ffffff',

            'extendedProps' => [
                'entry_type' => 'time_credit',
                'user_id' => (int)$r['user_id'],
                'minutes' => (int)$r['minutes'],
                'note' => $r['note'],
                'name' => $r['name']
            ]
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Nationale feestdagen toevoegen
    |--------------------------------------------------------------------------
    */

    foreach (
        dutch_holidays(
            (int)substr($start, 0, 4),
            (int)substr($end, 0, 4)
        ) as $date => $name
    ) {

        if ($date >= $start && $date <= $end) {

            $events[] = [
                'title' => $name,
                'start' => $date,
                'allDay' => true,
                'classNames' => ['holiday-event'],
                'editable' => false,
                'extendedProps' => [
                    'holiday' => true
                ]
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Verplichte brugdagen toevoegen
    |--------------------------------------------------------------------------
    */

    $bridgeDays = mandatory_bridge_days(
        (int)substr($start, 0, 4),
        (int)substr($end, 0, 4)
    );

    if ($isAdmin) {

        $employeeStmt = db()->query(
            'SELECT id, name FROM users ORDER BY name'
        );

        $calendarEmployees = $employeeStmt->fetchAll();

    } else {

        $calendarEmployees = [[
            'id' => $user['id'],
            'name' => $user['name']
        ]];
    }

    foreach ($bridgeDays as $date => $name) {

        if ($date < $start || $date > $end) {
            continue;
        }

        foreach ($calendarEmployees as $employee) {

            $events[] = [
                'id' => 'bridge-' . $employee['id'] . '-' . $date,

                'title' =>
                    ($isAdmin ? $employee['name'] . ' — ' : '') .
                    $name,

                'start' => $date,

                'end' => (new DateTimeImmutable($date))
                    ->modify('+1 day')
                    ->format('Y-m-d'),

                'allDay' => true,

                'backgroundColor' => '#7c3aed',
                'borderColor' => '#6d28d9',
                'textColor' => '#ffffff',

                'classNames' => ['mandatory-bridge-event'],
                'editable' => false,

                'extendedProps' => [
                    'holiday' => true,
                    'mandatory_bridge' => true,
                    'user_id' => (int)$employee['id']
                ]
            ];
        }
    }

    echo json_encode($events, JSON_UNESCAPED_UNICODE);
    exit;
}

/*
|--------------------------------------------------------------------------
| POST: wijzigingen verwerken
|--------------------------------------------------------------------------
*/

if ($method !== 'POST') {
    json_error('Methode niet toegestaan.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (!hash_equals(
    csrf_token(),
    (string)($input['csrf'] ?? '')
)) {
    json_error('Sessie verlopen. Vernieuw de pagina.', 419);
}

$action = (string)($input['action'] ?? '');

/*
|--------------------------------------------------------------------------
| Tijd-voor-tijd opslaan of aanpassen
|--------------------------------------------------------------------------
*/

if ($action === 'save_credit') {

    $id = (string)($input['id'] ?? '');

    if (str_starts_with($id, 'credit-')) {
        $id = substr($id, 7);
    }

    $id = (int)$id;

    $targetUser = get_target_user($user, $input);

    $workDate = (string)($input['work_date'] ?? '');

    $minutes = filter_var(
        $input['minutes'] ?? null,
        FILTER_VALIDATE_INT
    );

    $note = trim((string)($input['note'] ?? ''));

    if (!valid_date($workDate)) {
        json_error('Kies een geldige datum.');
    }

    if ($minutes === false || $minutes < 15) {
        json_error('Tijd-voor-tijd moet minimaal 15 minuten zijn.');
    }

    if ($minutes > 2147483647) {
        json_error('Het ingevoerde aantal minuten is te groot.');
    }

    $targetStmt = db()->prepare(
        'SELECT id FROM users WHERE id = ?'
    );
    $targetStmt->execute([$targetUser]);

    if (!$targetStmt->fetch()) {
        json_error('Medewerker niet gevonden.');
    }

    if ($id > 0) {

        $existingStmt = db()->prepare(
            'SELECT * FROM time_credits WHERE id = ?'
        );
        $existingStmt->execute([$id]);
        $existing = $existingStmt->fetch();

        if (!$existing) {
            json_error('Tijd-voor-tijdboeking niet gevonden.', 404);
        }

        check_entry_access($user, $existing);

        if ($user['role'] !== 'admin') {
            $targetUser = (int)$user['id'];
        }

        db()->prepare(
            'UPDATE time_credits
             SET user_id = ?, work_date = ?, minutes = ?, note = ?
             WHERE id = ?'
        )->execute([
            $targetUser,
            $workDate,
            $minutes,
            mb_substr($note, 0, 255),
            $id
        ]);

    } else {

        db()->prepare(
            'INSERT INTO time_credits
                (user_id, work_date, minutes, note)
             VALUES (?, ?, ?, ?)'
        )->execute([
            $targetUser,
            $workDate,
            $minutes,
            mb_substr($note, 0, 255)
        ]);
    }

    echo json_encode([
        'ok' => true,
        'minutes' => $minutes,
        'hours' => round($minutes / 60, 2)
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Tijd-voor-tijd verwijderen
|--------------------------------------------------------------------------
*/

if ($action === 'delete_credit') {

    $id = (string)($input['id'] ?? '');

    if (str_starts_with($id, 'credit-')) {
        $id = substr($id, 7);
    }

    $id = (int)$id;

    $stmt = db()->prepare(
        'SELECT * FROM time_credits WHERE id = ?'
    );
    $stmt->execute([$id]);

    $existing = $stmt->fetch();

    if (!$existing) {
        json_error('Tijd-voor-tijdboeking niet gevonden.', 404);
    }

    check_entry_access($user, $existing);

    db()->prepare(
        'DELETE FROM time_credits WHERE id = ?'
    )->execute([$id]);

    echo json_encode(['ok' => true]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Gewoon verlof opslaan of aanpassen
|--------------------------------------------------------------------------
*/

if ($action === 'save') {

    $id = (int)($input['id'] ?? 0);

    $targetUser = get_target_user($user, $input);

    $start = (string)($input['start_date'] ?? '');
    $end = (string)($input['end_date'] ?? '');

    $startTime = (string)($input['start_time'] ?? '');
    $endTime = (string)($input['end_time'] ?? '');

    $mode = (string)($input['calculation_mode'] ?? 'automatic');
    $note = trim((string)($input['note'] ?? ''));

    if (!valid_date($start) || !valid_date($end)) {
        json_error('Kies een geldige begin- en einddatum.');
    }

    if ($end < $start) {
        json_error('De einddatum mag niet vóór de begindatum liggen.');
    }

    if (
        !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime) ||
        !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endTime)
    ) {
        json_error('Vul een geldige begin- en eindtijd in.');
    }

    if ($start === $end && $endTime <= $startTime) {
        json_error('De eindtijd moet later zijn dan de begintijd.');
    }

    if (!in_array($mode, ['automatic', 'manual'], true)) {
        json_error('Ongeldige berekeningsmethode.');
    }

    if ($startTime < '08:45' || $endTime > '16:30') {
        json_error(
            'De tijden moeten binnen je normale werktijden vallen: 08:45 tot 16:30.'
        );
    }

    $stmt = db()->prepare(
        'SELECT * FROM users WHERE id = ?'
    );
    $stmt->execute([$targetUser]);
    $target = $stmt->fetch();

    if (!$target) {
        json_error('Medewerker niet gevonden.');
    }

    if ($mode === 'automatic') {

        try {

            $hours = hours_for_range(
                $target,
                $start,
                $end,
                $startTime,
                $endTime
            );

        } catch (Throwable $e) {

            json_error($e->getMessage());

        }

    } else {

        $hours = filter_var(
            $input['hours'] ?? null,
            FILTER_VALIDATE_FLOAT
        );

        if ($hours === false || $hours <= 0 || $hours > 1000) {
            json_error(
                'Vul een geldig aantal uren in (meer dan 0 t/m 1000).'
            );
        }

        $hours = round((float)$hours, 2);

        $holidays = dutch_holidays(
            (int)substr($start, 0, 4),
            (int)substr($end, 0, 4)
        );

        $from = new DateTimeImmutable($start);
        $to = new DateTimeImmutable($end);
        $holidayNames = [];

        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {

            $date = $d->format('Y-m-d');

            if (isset($holidays[$date])) {
                $holidayNames[] = $holidays[$date];
            }
        }

        if (!empty($holidayNames)) {
            json_error(
                'Let op: deze periode bevat een feestdag. ' .
                'Hier hoef je geen verlof voor aan te vragen. (' .
                implode(', ', array_unique($holidayNames)) . ').'
            );
        }
    }

    if ($hours <= 0) {
        json_error('Deze verlofboeking bevat geen verlofuren.');
    }

    if ($id > 0) {

        $stmt = db()->prepare(
            'SELECT user_id FROM leave_entries WHERE id = ?'
        );
        $stmt->execute([$id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            json_error('Verlofboeking niet gevonden.', 404);
        }

        check_entry_access($user, $existing);

        if ($user['role'] !== 'admin') {
            $targetUser = (int)$user['id'];
        }

        db()->prepare(
            'UPDATE leave_entries
             SET user_id = ?,
                 start_date = ?,
                 end_date = ?,
                 start_time = ?,
                 end_time = ?,
                 hours = ?,
                 calculation_mode = ?,
                 note = ?
             WHERE id = ?'
        )->execute([
            $targetUser,
            $start,
            $end,
            $startTime,
            $endTime,
            $hours,
            $mode,
            mb_substr($note, 0, 255),
            $id
        ]);

    } else {

        db()->prepare(
            'INSERT INTO leave_entries
                (
                    user_id,
                    start_date,
                    end_date,
                    start_time,
                    end_time,
                    hours,
                    calculation_mode,
                    note
                )
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $targetUser,
            $start,
            $end,
            $startTime,
            $endTime,
            $hours,
            $mode,
            mb_substr($note, 0, 255)
        ]);
    }

    echo json_encode([
        'ok' => true,
        'hours' => $hours
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Gewoon verlof verwijderen
|--------------------------------------------------------------------------
*/

if ($action === 'delete') {

    $id = (int)($input['id'] ?? 0);

    $stmt = db()->prepare(
        'SELECT * FROM leave_entries WHERE id = ?'
    );
    $stmt->execute([$id]);

    $existing = $stmt->fetch();

    if (!$existing) {
        json_error('Verlofboeking niet gevonden.', 404);
    }

    check_entry_access($user, $existing);

    db()->prepare(
        'DELETE FROM leave_entries WHERE id = ?'
    )->execute([$id]);

    echo json_encode(['ok' => true]);
    exit;
}

json_error('Onbekende actie.');

/*
|--------------------------------------------------------------------------
| Nederlandse feestdagen
|--------------------------------------------------------------------------
*/

function dutch_holidays(int $year, int $endYear): array
{
    $all = [];

    for ($y = $year; $y <= $endYear; $y++) {

        $easter = new DateTimeImmutable('@' . easter_date($y));

        $easter = $easter->setTimezone(
            new DateTimeZone('Europe/Amsterdam')
        );

        $add = function (
            DateTimeImmutable $d,
            string $name
        ) use (&$all) {
            $all[$d->format('Y-m-d')] = $name;
        };

        $add(new DateTimeImmutable("$y-01-01"), 'Nieuwjaarsdag');
        $add(new DateTimeImmutable("$y-04-27"), 'Koningsdag');

        $add($easter->modify('-2 days'), 'Goede Vrijdag');
        $add($easter, 'Eerste paasdag');
        $add($easter->modify('+1 day'), 'Tweede paasdag');
        $add($easter->modify('+39 days'), 'Hemelvaartsdag');
        $add($easter->modify('+49 days'), 'Eerste pinksterdag');
        $add($easter->modify('+50 days'), 'Tweede pinksterdag');

        $add(new DateTimeImmutable("$y-12-25"), 'Eerste kerstdag');
        $add(new DateTimeImmutable("$y-12-26"), 'Tweede kerstdag');

        if ($y % 5 === 0) {
            $add(new DateTimeImmutable("$y-05-05"), 'Bevrijdingsdag');
        }

        if (
            (int)(new DateTimeImmutable("$y-04-27"))->format('N') === 7
        ) {
            unset($all["$y-04-27"]);

            $add(
                new DateTimeImmutable("$y-04-26"),
                'Koningsdag'
            );
        }
    }

    return $all;
}