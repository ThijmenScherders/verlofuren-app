<?php
require_once __DIR__ . '/config.php';

$user = require_login();

header('Content-Type: application/json; charset=utf-8');

function json_error(string $message, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Zet een tijd zoals 09:30 om naar minuten vanaf middernacht.
 */
function time_to_minutes(string $time): int {
    $parts = explode(':', $time);

    return ((int)$parts[0] * 60) + (int)$parts[1];
}

/**
 * Berekent het aantal minuten binnen de normale werktijden.
 *
 * Normale werkdag:
 * 08:45 tot 16:30 = 7,75 uur.
 */
function working_minutes(string $startTime, string $endTime): int {
    $workStart = 8 * 60 + 45;
    $workEnd = 16 * 60 + 30;

    $start = max(time_to_minutes($startTime), $workStart);
    $end = min(time_to_minutes($endTime), $workEnd);

    return max(0, $end - $start);
}

/**
 * Bereken verlofuren voor een periode.
 *
 * Eerste dag: begintijd tot 16:30.
 * Laatste dag: 08:45 tot eindtijd.
 * Tussenliggende dagen: volledig volgens werkrooster.
 */
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

        // Eén dag: bereken alleen de periode tussen de ingevulde tijden.
        if ($start === $end) {

            $minutes = working_minutes($startTime, $endTime);
            $dayHours = $minutes / 60;

            $total += min($dayHours, $scheduledHours);

            continue;
        }

        // Eerste dag: begintijd tot einde normale werkdag.
        if ($date === $start) {

            $minutes = working_minutes($startTime, '16:30');
            $dayHours = $minutes / 60;

            $total += min($dayHours, $scheduledHours);

            continue;
        }

        // Laatste dag: begin normale werkdag tot ingevulde eindtijd.
        if ($date === $end) {

            $minutes = working_minutes('08:45', $endTime);
            $dayHours = $minutes / 60;

            $total += min($dayHours, $scheduledHours);

            continue;
        }

        // Tussenliggende werkdagen worden volledig gerekend.
        $total += $scheduledHours;
    }

    return round($total, 2);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {

    $start = (string)($_GET['start'] ?? date('Y-m-01'));
    $end = (string)($_GET['end'] ?? date('Y-m-t'));

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
            JOIN users u ON u.id=l.user_id
            WHERE l.start_date <= ? AND l.end_date >= ?';

    $params = [$end, $start];

    if (!$isAdmin) {
        $sql .= ' AND l.user_id=?';
        $params[] = $user['id'];
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $events = [];

    foreach ($stmt->fetchAll() as $r) {

        $events[] = [
            'id' => (string)$r['id'],

            'title' => ($isAdmin ? $r['name'] . ' — ' : '') .
                number_format((float)$r['hours'], 2, ',', '.') .
                ' uur' .
                ($r['note'] ? ' · ' . $r['note'] : ''),

            'start' => $r['start_date'],

            'end' => (new DateTimeImmutable($r['end_date']))
                ->modify('+1 day')
                ->format('Y-m-d'),

            'allDay' => true,

            'extendedProps' => [
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

    // Nationale feestdagen toevoegen.
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
                'extendedProps' => ['holiday' => true]
            ];
        }
    }

    echo json_encode($events, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    json_error('Methode niet toegestaan.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (!hash_equals(csrf_token(), (string)($input['csrf'] ?? ''))) {
    json_error('Sessie verlopen. Vernieuw de pagina.', 419);
}

$action = (string)($input['action'] ?? '');

if ($action === 'save') {

    $id = (int)($input['id'] ?? 0);

    $targetUser = $user['role'] === 'admin'
        ? (int)($input['user_id'] ?? $user['id'])
        : (int)$user['id'];

    $start = (string)($input['start_date'] ?? '');
    $end = (string)($input['end_date'] ?? '');

    $startTime = (string)($input['start_time'] ?? '');
    $endTime = (string)($input['end_time'] ?? '');

    $mode = (string)($input['calculation_mode'] ?? 'automatic');
    $note = trim((string)($input['note'] ?? ''));

    if (
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)
    ) {
        json_error('Kies een geldige begin- en einddatum.');
    }

    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end);

    if (
        !$startDate ||
        !$endDate ||
        $startDate->format('Y-m-d') !== $start ||
        $endDate->format('Y-m-d') !== $end
    ) {
        json_error('Kies geldige datums.');
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

    $stmt = db()->prepare('SELECT * FROM users WHERE id=?');
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
            json_error('Vul een geldig aantal uren in (meer dan 0 t/m 1000).');
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
            'SELECT user_id FROM leave_entries WHERE id=?'
        );

        $stmt->execute([$id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            json_error('Verlofboeking niet gevonden.', 404);
        }

        if (
            $user['role'] !== 'admin' &&
            (int)$existing['user_id'] !== (int)$user['id']
        ) {
            json_error('Geen toegang.', 403);
        }

        if ($user['role'] !== 'admin') {
            $targetUser = (int)$user['id'];
        }

        db()->prepare(
            'UPDATE leave_entries
             SET user_id=?,
                 start_date=?,
                 end_date=?,
                 start_time=?,
                 end_time=?,
                 hours=?,
                 calculation_mode=?,
                 note=?
             WHERE id=?'
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
             VALUES (?,?,?,?,?,?,?,?)'
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

if ($action === 'delete') {

    $id = (int)($input['id'] ?? 0);

    $stmt = db()->prepare(
        'SELECT user_id FROM leave_entries WHERE id=?'
    );

    $stmt->execute([$id]);
    $existing = $stmt->fetch();

    if (!$existing) {
        json_error('Verlofboeking niet gevonden.', 404);
    }

    if (
        $user['role'] !== 'admin' &&
        (int)$existing['user_id'] !== (int)$user['id']
    ) {
        json_error('Geen toegang.', 403);
    }

    db()->prepare(
        'DELETE FROM leave_entries WHERE id=?'
    )->execute([$id]);

    echo json_encode(['ok' => true]);
    exit;
}

json_error('Onbekende actie.');

function dutch_holidays(int $year, int $endYear): array {

    $all = [];

    for ($y = $year; $y <= $endYear; $y++) {

        $easter = new DateTimeImmutable('@' . easter_date($y));

        $easter = $easter->setTimezone(
            new DateTimeZone('Europe/Amsterdam')
        );

        $add = function(
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