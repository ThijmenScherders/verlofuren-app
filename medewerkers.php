
<?php
require_once __DIR__ . '/config.php';

$user = require_login();
require_admin($user);

$error = '';

$currentYear = (int)date('Y');
$yearStart = $currentYear . '-01-01';
$nextYearStart = ($currentYear + 1) . '-01-01';

$scheduleFields = [
    'monday' => 'Maandag',
    'tuesday' => 'Dinsdag',
    'wednesday' => 'Woensdag',
    'thursday' => 'Donderdag',
    'friday' => 'Vrijdag',
    'saturday' => 'Zaterdag',
    'sunday' => 'Zondag'
];

/*
|--------------------------------------------------------------------------
| Formulieren verwerken
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    $workNumber = trim($_POST['work_number'] ?? '');

    if ($workNumber === '') {
        $workNumber = null;
    }

    if ($workNumber !== null && mb_strlen($workNumber) > 50) {
        $error = 'Het werknummer mag maximaal 50 tekens bevatten.';
    }

    if ($action === 'create' && !$error) {
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        $allowance = filter_var(
            $_POST['allowance_hours'] ?? null,
            FILTER_VALIDATE_FLOAT
        );

        $leaveDayHours = filter_var(
            $_POST['leave_day_hours'] ?? null,
            FILTER_VALIDATE_FLOAT
        );

        if ($name === '' || mb_strlen($name) > 120) {
            $error = 'Vul een geldige naam in.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Vul een geldig e-mailadres in.';
        } elseif (strlen($password) < 10) {
            $error = 'Het wachtwoord moet minimaal 10 tekens bevatten.';
        } elseif ($allowance === false || $allowance < 0 || $allowance > 5000) {
            $error = 'Vul een geldig jaarlijks verlofrecht in.';
        } elseif (
            $leaveDayHours === false ||
            $leaveDayHours <= 0 ||
            $leaveDayHours > 24
        ) {
            $error = 'Vul een geldig aantal uren per verlofdag in.';
        }

        $schedule = [];

        foreach ($scheduleFields as $field => $label) {
            $value = filter_var(
                $_POST[$field . '_hours'] ?? null,
                FILTER_VALIDATE_FLOAT
            );

            if ($value === false || $value < 0 || $value > 24) {
                $error = 'Vul geldige werkuren in voor ' . $label . '.';
                break;
            }

            $schedule[$field . '_hours'] = $value;
        }

        if (!$error) {
            try {
                $stmt = db()->prepare(
                    'INSERT INTO users (
                        name,
                        email,
                        password_hash,
                        role,
                        work_number,
                        allowance_hours,
                        leave_day_hours,
                        monday_hours,
                        tuesday_hours,
                        wednesday_hours,
                        thursday_hours,
                        friday_hours,
                        saturday_hours,
                        sunday_hours
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )'
                );

                $stmt->execute([
                    $name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    'employee',
                    $workNumber,
                    $allowance,
                    $leaveDayHours,
                    $schedule['monday_hours'],
                    $schedule['tuesday_hours'],
                    $schedule['wednesday_hours'],
                    $schedule['thursday_hours'],
                    $schedule['friday_hours'],
                    $schedule['saturday_hours'],
                    $schedule['sunday_hours']
                ]);

                set_flash('Medewerker is succesvol aangemaakt.');
                redirect('medewerkers.php');

            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = 'Dit e-mailadres is al in gebruik.';
                } else {
                    $error = 'Er ging iets mis bij het aanmaken van de medewerker.';
                }
            }
        }
    }

    if ($action === 'update' && !$error) {
        $id = filter_var(
            $_POST['id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $allowance = filter_var(
            $_POST['allowance_hours'] ?? null,
            FILTER_VALIDATE_FLOAT
        );

        $leaveDayHours = filter_var(
            $_POST['leave_day_hours'] ?? null,
            FILTER_VALIDATE_FLOAT
        );

        if (!$id || $id <= 0) {
            $error = 'Ongeldige medewerker geselecteerd.';
        } elseif ($id === (int)$user['id']) {
            $error = 'Je kunt je eigen instellingen hier niet wijzigen.';
        } elseif ($allowance === false || $allowance < 0 || $allowance > 5000) {
            $error = 'Vul een geldig jaarlijks verlofrecht in.';
        } elseif (
            $leaveDayHours === false ||
            $leaveDayHours <= 0 ||
            $leaveDayHours > 24
        ) {
            $error = 'Vul een geldig aantal uren per verlofdag in.';
        }

        $schedule = [];

        foreach ($scheduleFields as $field => $label) {
            $value = filter_var(
                $_POST[$field . '_hours'] ?? null,
                FILTER_VALIDATE_FLOAT
            );

            if ($value === false || $value < 0 || $value > 24) {
                $error = 'Vul geldige werkuren in voor ' . $label . '.';
                break;
            }

            $schedule[$field . '_hours'] = $value;
        }

        if (!$error) {
            try {
                $stmt = db()->prepare(
                    'UPDATE users
                     SET work_number = ?,
                         allowance_hours = ?,
                         leave_day_hours = ?,
                         monday_hours = ?,
                         tuesday_hours = ?,
                         wednesday_hours = ?,
                         thursday_hours = ?,
                         friday_hours = ?,
                         saturday_hours = ?,
                         sunday_hours = ?
                     WHERE id = ?'
                );

                $stmt->execute([
                    $workNumber,
                    $allowance,
                    $leaveDayHours,
                    $schedule['monday_hours'],
                    $schedule['tuesday_hours'],
                    $schedule['wednesday_hours'],
                    $schedule['thursday_hours'],
                    $schedule['friday_hours'],
                    $schedule['saturday_hours'],
                    $schedule['sunday_hours'],
                    $id
                ]);

                set_flash('Medewerker is succesvol bijgewerkt.');
                redirect('medewerkers.php');

            } catch (PDOException $e) {
                $error = 'Er ging iets mis bij het wijzigen van de medewerker.';
            }
        }
    }

    if (!in_array($action, ['create', 'update'], true) && !$error) {
        $error = 'Ongeldige actie.';
    }
}

/*
|--------------------------------------------------------------------------
| Brugdag berekenen
|--------------------------------------------------------------------------
*/

function get_bridge_day(int $year): string
{
    $easter = new DateTimeImmutable('@' . easter_date($year));

    $easter = $easter->setTimezone(
        new DateTimeZone('Europe/Amsterdam')
    );

    return $easter
        ->modify('+41 days')
        ->format('Y-m-d');
}

$bridgeDate = get_bridge_day($currentYear);

/*
|--------------------------------------------------------------------------
| Medewerkers ophalen met jaarberekening
|--------------------------------------------------------------------------
*/

$employeesStmt = db()->prepare("
    SELECT
        u.*,

        COALESCE(
            (
                SELECT SUM(l.hours)
                FROM leave_entries l
                WHERE l.user_id = u.id
                  AND l.booking_type = 'leave'
                  AND l.start_date >= ?
                  AND l.start_date < ?
            ),
            0
        ) AS used_hours,

        COALESCE(
            (
                SELECT SUM(tc.minutes)
                FROM time_credits tc
                WHERE tc.user_id = u.id
                  AND tc.work_date >= ?
                  AND tc.work_date < ?
            ),
            0
        ) AS credit_minutes,

        (
            SELECT COUNT(*)
            FROM leave_entries l
            WHERE l.user_id = u.id
              AND l.booking_type = 'leave'
              AND l.start_date <= ?
              AND l.end_date >= ?
        ) AS bridge_already_booked

    FROM users u
    ORDER BY role DESC, name
");

$employeesStmt->execute([
    $yearStart,
    $nextYearStart,
    $yearStart,
    $nextYearStart,
    $bridgeDate,
    $bridgeDate
]);

$employees = $employeesStmt->fetchAll();

$csrf = csrf_token();
?>

<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">

    <title>Medewerkers - Verlofuren</title>

    <link rel="stylesheet" href="assets/style.css">
</head>

<body>

<header class="topbar">

    <a class="brand" href="index.php">
        <span class="brand-mark small-mark">V</span>
        Verlofuren
    </a>

    <nav>
        <a href="index.php">Kalender</a>
        <a href="export.php">Export CSV</a>
        <a href="logout.php">Uitloggen</a>
    </nav>

</header>

<main class="container narrow-container">

    <div class="page-heading">
        <div>
            <p class="eyebrow">BEHEER</p>
            <h1>Medewerkers</h1>
            <p class="muted">
                Maak accounts aan en stel saldo, werknummer en werkrooster in.
            </p>
        </div>
    </div>

    <?= flash_html() ?>

    <?php if ($error): ?>
        <div class="alert error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- Nieuwe medewerker -->

    <section class="panel">

        <h2>Nieuwe medewerker</h2>

        <form method="post">

            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="create">

            <div class="form-grid">

                <label>
                    Naam

                    <input
                        name="name"
                        required
                        maxlength="120"
                    >
                </label>

                <label>
                    Werknummer

                    <input
                        name="work_number"
                        maxlength="50"
                        placeholder="Bijvoorbeeld 1024"
                    >
                </label>

            </div>

            <label>
                E-mailadres

                <input
                    type="email"
                    name="email"
                    required
                    maxlength="190"
                >
            </label>

            <label>
                Tijdelijk wachtwoord (minimaal 10 tekens)

                <input
                    type="password"
                    name="password"
                    minlength="10"
                    required
                >
            </label>

            <label>
                Jaarlijks verlofrecht in uren

                <input
                    type="number"
                    name="allowance_hours"
                    min="0"
                    max="5000"
                    step="0.25"
                    value="193.75"
                    required
                >
            </label>

            <label>
                Uren per verlofdag

                <input
                    type="number"
                    name="leave_day_hours"
                    min="0.01"
                    max="24"
                    step="0.01"
                    value="7.75"
                    required
                >
            </label>

            <h3>Werkuren per dag</h3>

            <p class="muted small">
                Gebruik 0 voor een vrije dag.
                Automatische berekening telt alle dagen volgens dit rooster.
            </p>

            <div class="week-grid">

                <?php foreach ($scheduleFields as $field => $label): ?>

                    <label>
                        <?= e($label) ?>

                        <input
                            type="number"
                            name="<?= e($field) ?>_hours"
                            min="0"
                            max="24"
                            step="0.25"
                            value="<?= in_array(
                                $field,
                                ['saturday', 'sunday'],
                                true
                            ) ? '0' : '7.75' ?>"
                            required
                        >
                    </label>

                <?php endforeach; ?>

            </div>

            <button class="button" type="submit">
                Medewerker aanmaken
            </button>

        </form>

    </section>

    <!-- Bestaande medewerkers -->

    <section class="panel">

        <h2>Saldo en werkrooster</h2>

        <p class="muted small">
            Saldo voor kalenderjaar <?= $currentYear ?>.
        </p>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Medewerker</th>
                        <th>Rol</th>
                        <th>Verlof gebruikt</th>
                        <th>Tijd-voor-tijd</th>
                        <th>Resterend saldo</th>
                        <th>Instellingen</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($employees as $emp): ?>

                        <?php
                        $allowance = (float)$emp['allowance_hours'];
                        $usedHours = (float)$emp['used_hours'];
                        $creditHours = (int)$emp['credit_minutes'] / 60;

                        $leaveDayHours = (float)$emp['leave_day_hours'];

                        if ($leaveDayHours <= 0) {
                            $leaveDayHours = 7.75;
                        }

                        if ((int)$emp['bridge_already_booked'] === 0) {
                            $usedHours += $leaveDayHours;
                        }

                        $remaining = $allowance + $creditHours - $usedHours;

                        $roleLabel = $emp['role'] === 'admin'
                            ? 'Beheerder'
                            : 'Medewerker';
                        ?>

                        <tr>

                            <td>
                                <strong>
                                    <?= e($emp['name']) ?>
                                </strong>

                                <br>

                                <span class="muted small">
                                    <?= e($emp['email']) ?>
                                </span>

                                <br>

                                <span class="muted small">
                                    Werknummer:
                                    <?= !empty($emp['work_number'])
                                        ? e($emp['work_number'])
                                        : 'Niet ingevuld' ?>
                                </span>
                            </td>

                            <td>
                                <?= e($roleLabel) ?>
                            </td>

                            <td>
                                <?= number_format($usedHours, 2, ',', '.') ?> u
                            </td>

                            <td>
                                <?= number_format($creditHours, 2, ',', '.') ?> u
                            </td>

                            <td>
                                <strong class="<?= $remaining < 0 ? 'negative' : '' ?>">
                                    <?= number_format($remaining, 2, ',', '.') ?> u
                                </strong>
                            </td>

                            <td>

                                <?php if ((int)$emp['id'] === (int)$user['id']): ?>

                                    <span class="muted small">
                                        Eigen account
                                    </span>

                                <?php else: ?>

                                    <details>

                                        <summary>Wijzigen</summary>

                                        <form
                                            method="post"
                                            class="compact-form"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf"
                                                value="<?= e($csrf) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="update"
                                            >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int)$emp['id'] ?>"
                                            >

                                            <label>
                                                Werknummer

                                                <input
                                                    type="text"
                                                    name="work_number"
                                                    maxlength="50"
                                                    value="<?= e($emp['work_number'] ?? '') ?>"
                                                    placeholder="Bijvoorbeeld 1024"
                                                >
                                            </label>

                                            <label>
                                                Jaarlijks verlofrecht

                                                <input
                                                    type="number"
                                                    name="allowance_hours"
                                                    min="0"
                                                    max="5000"
                                                    step="0.25"
                                                    value="<?= e((string)$emp['allowance_hours']) ?>"
                                                    required
                                                >
                                            </label>

                                            <label>
                                                Uren per verlofdag

                                                <input
                                                    type="number"
                                                    name="leave_day_hours"
                                                    min="0.01"
                                                    max="24"
                                                    step="0.01"
                                                    value="<?= e((string)$emp['leave_day_hours']) ?>"
                                                    required
                                                >
                                            </label>

                                            <div class="week-grid">

                                                <?php foreach ($scheduleFields as $field => $label): ?>

                                                    <label>
                                                        <?= e(substr($label, 0, 2)) ?>

                                                        <input
                                                            type="number"
                                                            name="<?= e($field) ?>_hours"
                                                            min="0"
                                                            max="24"
                                                            step="0.25"
                                                            value="<?= e((string)$emp[$field . '_hours']) ?>"
                                                            required
                                                        >
                                                    </label>

                                                <?php endforeach; ?>

                                            </div>

                                            <button
                                                class="button small-button"
                                                type="submit"
                                            >
                                                Opslaan
                                            </button>

                                        </form>

                                    </details>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

</body>
</html>
