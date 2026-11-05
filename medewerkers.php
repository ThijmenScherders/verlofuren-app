<?php
require_once __DIR__ . '/config.php';

$user = require_login();
require_admin($user);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string)($_POST['action'] ?? 'create');

    // Nieuwe medewerker aanmaken
    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');

        $allowance = (float)($_POST['allowance_hours'] ?? 193.75);
        $leaveDayHours = (float)($_POST['leave_day_hours'] ?? 7.75);

        $weekdays = [
            'monday_hours',
            'tuesday_hours',
            'wednesday_hours',
            'thursday_hours',
            'friday_hours',
            'saturday_hours',
            'sunday_hours'
        ];

        $workHours = [];

        foreach ($weekdays as $field) {
            $workHours[] = (float)($_POST[$field] ?? 0);
        }

        if (
            $name === '' ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            strlen($password) < 10 ||
            $allowance < 0 ||
            $allowance > 5000 ||
            $leaveDayHours <= 0 ||
            $leaveDayHours > 24
        ) {
            $error = 'Controleer naam, e-mailadres, wachtwoord, saldo en uren per verlofdag.';
        } else {
            foreach ($workHours as $hours) {
                if ($hours < 0 || $hours > 24) {
                    $error = 'Werkuren moeten tussen 0 en 24 uur liggen.';
                    break;
                }
            }
        }

        if ($error === '') {
            try {
                $stmt = db()->prepare("
                    INSERT INTO users (
                        name,
                        email,
                        password_hash,
                        role,
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
                        ?, ?, ?, 'employee', ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )
                ");

                $stmt->execute([
                    $name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $allowance,
                    $leaveDayHours,
                    ...$workHours
                ]);

                set_flash('Medewerker aangemaakt.');
                redirect('medewerkers.php');

            } catch (PDOException $e) {
                $error = 'Dit e-mailadres bestaat mogelijk al.';
            }
        }
    }

    // Bestaande medewerker aanpassen
    elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);

        $allowance = (float)($_POST['allowance_hours'] ?? 0);
        $leaveDayHours = (float)($_POST['leave_day_hours'] ?? 7.75);

        $fields = [
            'monday_hours',
            'tuesday_hours',
            'wednesday_hours',
            'thursday_hours',
            'friday_hours',
            'saturday_hours',
            'sunday_hours'
        ];

        $vals = [];

        foreach ($fields as $field) {
            $vals[] = (float)($_POST[$field] ?? 0);
        }

        if ($id <= 0) {
            $error = 'Ongeldige medewerker.';
        } elseif ($id === (int)$user['id']) {
            $error = 'Je kunt je eigen rooster en saldo hier niet aanpassen.';
        } elseif ($allowance < 0 || $allowance > 5000) {
            $error = 'Saldo moet tussen 0 en 5000 uur liggen.';
        } elseif ($leaveDayHours <= 0 || $leaveDayHours > 24) {
            $error = 'Uren per verlofdag moeten tussen 0,01 en 24 liggen.';
        } else {
            foreach ($vals as $hours) {
                if ($hours < 0 || $hours > 24) {
                    $error = 'Werkuren moeten tussen 0 en 24 uur liggen.';
                    break;
                }
            }
        }

        if ($error === '') {
            $stmt = db()->prepare("
                UPDATE users SET
                    allowance_hours = ?,
                    leave_day_hours = ?,
                    monday_hours = ?,
                    tuesday_hours = ?,
                    wednesday_hours = ?,
                    thursday_hours = ?,
                    friday_hours = ?,
                    saturday_hours = ?,
                    sunday_hours = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $allowance,
                $leaveDayHours,
                ...$vals,
                $id
            ]);

            set_flash('Saldo en werkrooster bijgewerkt.');
            redirect('medewerkers.php');
        }
    }
}

// Medewerkers ophalen
$employees = db()->query("
    SELECT
        u.*,
        COALESCE(
            (
                SELECT SUM(hours)
                FROM leave_entries l
                WHERE l.user_id = u.id
            ),
            0
        ) AS used_hours
    FROM users u
    ORDER BY role DESC, name
")->fetchAll();

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
        <div class="alert error"><?= e($error) ?></div>
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
                Beginsaldo verlofuren
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
                            value="<?= in_array($field, ['saturday', 'sunday']) ? '0' : '7.75' ?>"
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

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>Medewerker</th>
                        <th>Rol</th>
                        <th>Gebruikt</th>
                        <th>Resterend</th>
                        <th>Instellingen</th>
                    </tr>
                </thead>

                <tbody>

                    <?php foreach ($employees as $emp): ?>

                        <tr>

                            <td>
                                <strong><?= e($emp['name']) ?></strong>
                                <br>
                                <span class="muted small">
                                    <?= e($emp['email']) ?>
                                </span>
                            </td>

                            <td><?= e($emp['role']) ?></td>

                            <td>
                                <?= number_format(
                                    (float)$emp['used_hours'],
                                    2,
                                    ',',
                                    '.'
                                ) ?> u
                            </td>

                            <td>
                                <?= number_format(
                                    (float)$emp['allowance_hours'] -
                                    (float)$emp['used_hours'],
                                    2,
                                    ',',
                                    '.'
                                ) ?> u
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
                                                Beginsaldo

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
                                                            value="<?= e((string)$emp[$field . '_hours']) ?>"
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