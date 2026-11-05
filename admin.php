<?php
require_once __DIR__ . '/config.php';

$user = require_login();
require_admin($user);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

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

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Vul een geldige naam en e-mailadres in.';

    } elseif (strlen($password) < 10) {
        $error = 'Het wachtwoord moet minimaal 10 tekens bevatten.';

    } elseif (
        $allowance === false ||
        $allowance < 0 ||
        $allowance > 999999
    ) {
        $error = 'Vul een geldig verlofsaldo in.';

    } elseif (
        $leaveDayHours === false ||
        $leaveDayHours <= 0 ||
        $leaveDayHours > 24
    ) {
        $error = 'Vul een geldig aantal uren per verlofdag in (0,01 t/m 24).';

    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = db()->prepare(
                "INSERT INTO users
                (
                    name,
                    email,
                    password_hash,
                    role,
                    allowance_hours,
                    leave_day_hours
                )
                VALUES (?, ?, ?, 'employee', ?, ?)"
            );

            $stmt->execute([
                $name,
                $email,
                $hash,
                $allowance,
                $leaveDayHours
            ]);

            $success = 'Medewerker succesvol aangemaakt!';

        } catch (PDOException $e) {

            if ($e->getCode() === '23000') {
                $error = 'Dit e-mailadres is al in gebruik.';
            } else {
                $error = 'Er is iets misgegaan bij het aanmaken.';
            }
        }
    }
}

$employees = db()->query(
    "SELECT
        id,
        name,
        email,
        allowance_hours,
        leave_day_hours,
        created_at
     FROM users
     WHERE role = 'employee'
     ORDER BY name ASC"
)->fetchAll();

?>

<!doctype html>
<html lang="nl">

<head>

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Medewerkers beheren - Verlofuren</title>

    <link rel="stylesheet" href="assets/style.css">

</head>

<body>

<main class="container">

    <h1>Medewerkers beheren</h1>

    <p>Welkom, <?= e($user['name']) ?>.</p>

    <section>

        <h2>Nieuwe medewerker toevoegen</h2>

        <?php if ($error): ?>

            <div class="alert error">
                <?= e($error) ?>
            </div>

        <?php endif; ?>

        <?php if ($success): ?>

            <div class="alert success">
                <?= e($success) ?>
            </div>

        <?php endif; ?>

        <form method="post">

            <input
                type="hidden"
                name="csrf"
                value="<?= e(csrf_token()) ?>"
            >

            <label>Naam</label>

            <input
                type="text"
                name="name"
                maxlength="120"
                required
            >

            <label>E-mailadres</label>

            <input
                type="email"
                name="email"
                maxlength="190"
                required
            >

            <label>Wachtwoord</label>

            <input
                type="password"
                name="password"
                minlength="10"
                required
            >

            <label>Verlofsaldo in uren</label>

            <input
                type="number"
                name="allowance_hours"
                value="193.75"
                min="0"
                max="999999"
                step="0.01"
                required
            >

            <label>Uren per verlofdag</label>

            <input
                type="number"
                name="leave_day_hours"
                value="7.75"
                min="0.01"
                max="24"
                step="0.01"
                required
            >

            <button class="button" type="submit">
                Medewerker aanmaken
            </button>

        </form>

    </section>

    <section>

        <h2>Bestaande medewerkers</h2>

        <?php if (!$employees): ?>

            <p>Er zijn nog geen medewerkers aangemaakt.</p>

        <?php else: ?>

            <table>

                <thead>

                    <tr>
                        <th>Naam</th>
                        <th>E-mailadres</th>
                        <th>Verlofsaldo</th>
                        <th>Uren per verlofdag</th>
                    </tr>

                </thead>

                <tbody>

                    <?php foreach ($employees as $employee): ?>

                        <tr>

                            <td>
                                <?= e($employee['name']) ?>
                            </td>

                            <td>
                                <?= e($employee['email']) ?>
                            </td>

                            <td>
                                <?= number_format(
                                    (float)$employee['allowance_hours'],
                                    2,
                                    ',',
                                    '.'
                                ) ?> uur
                            </td>

                            <td>
                                <?= number_format(
                                    (float)$employee['leave_day_hours'],
                                    2,
                                    ',',
                                    '.'
                                ) ?> uur
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </section>

    <p>
        <a href="index.php">Terug naar dashboard</a>
    </p>

</main>

</body>
</html>