<?php
require_once __DIR__ . '/config.php';

$user = require_login();
require_admin($user);

$error = '';

$currentYear = (int)date('Y');
$yearStart = $currentYear . '-01-01';
$nextYearStart = ($currentYear + 1) . '-01-01';

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
                Maak accounts aan en stel saldo en werkrooster in.
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
                    E-mailadres

                    <input
                        type="email"
                        name="email"
                        required
                        maxlength="190"
                    >
                </label>

            </div>

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

                <?php
                foreach ([
                    'monday' => 'Maandag',
                    'tuesday' => 'Dinsdag',
                    'wednesday' => 'Woensdag',
                    'thursday' => 'Donderdag',
                    'friday' => 'Vrijdag',
                    'saturday' => 'Zaterdag',
                    'sunday' => 'Zondag'
                ] as $field => $label):
                ?>

                    <label>
                        <?= $label ?>

                        <input
                            type="number"
                            name="<?= $field ?>_hours"
                            min="0"
                            max="24"
                            step="0.25"
                            value="<?= in_array(
                                $field,
                                ['saturday', 'sunday'],
                                true
                            ) ? '0' : '7.75' ?>"
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

                        $creditHours =
                            (int)$emp['credit_minutes'] / 60;

                        $leaveDayHours =
                            (float)$emp['leave_day_hours'];

                        if ($leaveDayHours <= 0) {
                            $leaveDayHours = 7.75;
                        }

                        // Brugdag alleen meetellen als deze nog niet geboekt is.
                        if ((int)$emp['bridge_already_booked'] === 0) {
                            $usedHours += $leaveDayHours;
                        }

                        $remaining =
                            $allowance + $creditHours - $usedHours;
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
                            </td>

                            <td>
                                <?= e($emp['role']) ?>
                            </td>

                            <td>
                                <?= number_format(
                                    $usedHours,
                                    2,
                                    ',',
                                    '.'
                                ) ?> u
                            </td>

                            <td>
                                <?= number_format(
                                    $creditHours,
                                    2,
                                    ',',
                                    '.'
                                ) ?> u
                            </td>

                            <td>
                                <strong class="<?= $remaining < 0 ? 'negative' : '' ?>">
                                    <?= number_format(
                                        $remaining,
                                        2,
                                        ',',
                                        '.'
                                    ) ?> u
                                </strong>
                            </td>

                            <td>

                                <?php if (
                                    (int)$emp['id'] === (int)$user['id']
                                ): ?>

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
                                                Jaarlijks verlofrecht

                                                <input
                                                    type="number"
                                                    name="allowance_hours"
                                                    min="0"
                                                    max="5000"
                                                    step="0.25"
                                                    value="<?= e(
                                                        (string)$emp['allowance_hours']
                                                    ) ?>"
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
                                                    value="<?= e(
                                                        (string)$emp['leave_day_hours']
                                                    ) ?>"
                                                    required
                                                >
                                            </label>

                                            <div class="week-grid">

                                                <?php
                                                foreach ([
                                                    'monday' => 'Ma',
                                                    'tuesday' => 'Di',
                                                    'wednesday' => 'Wo',
                                                    'thursday' => 'Do',
                                                    'friday' => 'Vr',
                                                    'saturday' => 'Za',
                                                    'sunday' => 'Zo'
                                                ] as $field => $label):
                                                ?>

                                                    <label>
                                                        <?= $label ?>

                                                        <input
                                                            type="number"
                                                            name="<?= $field ?>_hours"
                                                            min="0"
                                                            max="24"
                                                            step="0.25"
                                                            value="<?= e(
                                                                (string)$emp[$field . '_hours']
                                                            ) ?>"
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