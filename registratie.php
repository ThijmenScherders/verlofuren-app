<?php
require_once __DIR__ . '/config.php';

if (current_user()) {
    redirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Vul alle velden in.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Vul een geldig e-mailadres in.';
    } elseif (strlen($password) < 8) {
        $error = 'Je wachtwoord moet minimaal 8 tekens bevatten.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'De wachtwoorden komen niet overeen.';
    } else {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $error = 'Er bestaat al een account met dit e-mailadres.';
        } else {
            try {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = db()->prepare(
                    'INSERT INTO users
                    (name, email, password_hash, role, allowance_hours, leave_day_hours)
                    VALUES (?, ?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $name,
                    $email,
                    $passwordHash,
                    'employee',
                    193.75,
                    7.75
                ]);

                set_flash('Je account is aangemaakt. Je kunt nu inloggen.');

                redirect('login.php');

            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $error = 'Dit e-mailadres is al in gebruik.';
                } else {
                    throw $exception;
                }
            }
        }
    }
}
?>

<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Account aanmaken - Verlofuren</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">

<main class="auth-card">

    <div class="brand-mark">V</div>

    <h1>Account aanmaken</h1>
    <p class="muted">Maak een account aan om je verlof te registreren.</p>

    <?= $error ? '<div class="alert error">' . e($error) . '</div>' : '' ?>

    <form method="post">

        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <label>
            Naam
            <input
                type="text"
                name="name"
                required
                maxlength="100"
                autocomplete="name"
                value="<?= e($_POST['name'] ?? '') ?>"
            >
        </label>

        <label>
            E-mailadres
            <input
                type="email"
                name="email"
                required
                autocomplete="email"
                value="<?= e($_POST['email'] ?? '') ?>"
            >
        </label>

        <label>
            Wachtwoord
            <input
                type="password"
                name="password"
                required
                minlength="8"
                autocomplete="new-password"
            >
        </label>

        <label>
            Wachtwoord bevestigen
            <input
                type="password"
                name="password_confirm"
                required
                minlength="8"
                autocomplete="new-password"
            >
        </label>

        <button class="button" type="submit">
            Account aanmaken
        </button>

    </form>
    

<a href="login.php" class="button" style="display: block; text-align: center; text-decoration: none; margin-top: 10px;">
    Terug naar inloggen
</a>



</main>

</body>
</html>