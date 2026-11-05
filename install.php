<?php
require_once __DIR__ . '/config.php';
$count = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if ($count > 0) {
        $error = 'Er bestaat al een account. Verwijder install.php van de server.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
            $error = 'Vul een naam, geldig e-mailadres en wachtwoord van minimaal 10 tekens in.';
        } else {
            $stmt = db()->prepare("INSERT INTO users (name,email,password_hash,role,allowance_hours) VALUES (?,?,?,'admin',200)");
            $stmt->execute([$name,$email,password_hash($password, PASSWORD_DEFAULT)]);
            set_flash('Beheerder aangemaakt. Je kunt nu inloggen.');
            redirect('login.php');
        }
    }
}
?><!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Installatie - Verlofuren</title><link rel="stylesheet" href="assets/style.css"></head><body class="auth-page"><main class="auth-card"><h1>Eerste beheerder aanmaken</h1><?php if($count>0): ?><div class="alert error">Er bestaat al een account. Verwijder install.php van de server.</div><?php else: ?><p>Dit formulier maakt de eerste beheerder aan.</p><?php endif; ?><?= $error ? '<div class="alert error">'.e($error).'</div>' : '' ?><?php if($count===0): ?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label>Naam<input name="name" required maxlength="120"></label><label>E-mailadres<input type="email" name="email" required maxlength="190"></label><label>Wachtwoord (minimaal 10 tekens)<input type="password" name="password" required minlength="10"></label><button class="button" type="submit">Beheerder aanmaken</button></form><?php endif; ?></main></body></html>
