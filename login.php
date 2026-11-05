<?php
require_once __DIR__ . '/config.php';

if (current_user()) {
    redirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');

    $stmt = db()->prepare('SELECT id,password_hash FROM users WHERE email=?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if ($row && password_verify($password, $row['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$row['id'];

        if (!empty($_POST['remember'])) {
            $token = bin2hex(random_bytes(32));

            db()->prepare(
                'UPDATE users SET remember_token_hash=?, remember_expires=DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id=?'
            )->execute([hash('sha256', $token), $row['id']]);

            setcookie(REMEMBER_COOKIE, $token, [
                'expires' => time() + 60 * 60 * 24 * 30,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
            ]);
        }

        redirect('index.php');
    }

    $error = 'E-mailadres of wachtwoord klopt niet.';
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Inloggen - Verlofuren</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">

<main class="auth-card">

    <div class="brand-mark">V</div>

    <h1>Verlofuren</h1>
    <p class="muted">Log in om je verlof te plannen.</p>

    <?= flash_html() ?>

    <?= $error ? '<div class="alert error">' . e($error) . '</div>' : '' ?>

    <form method="post">

        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <label>
            E-mailadres
            <input type="email" name="email" required autocomplete="username">
        </label>

        <label>
            Wachtwoord
            <input type="password" name="password" required autocomplete="current-password">
        </label>

        <label class="check">
            <input type="checkbox" name="remember" value="1">
            Ingelogd blijven (30 dagen)
        </label>

        <button class="button" type="submit">Inloggen</button>

    </form>

<a href="registratie.php" class="button" style="display: block; text-align: center; text-decoration: none; margin-top: 10px;">
    Account aanmaken
</a>
</main>

</body>
</html>