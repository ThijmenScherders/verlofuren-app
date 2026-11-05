<?php
require_once __DIR__ . '/config.php';

$user = require_login();

// Opgenomen verlofuren ophalen
$balanceStmt = db()->prepare(
    'SELECT COALESCE(SUM(hours), 0)
     FROM leave_entries
     WHERE user_id = ?'
);

$balanceStmt->execute([$user['id']]);
$used = (float)$balanceStmt->fetchColumn();

// Tijd-voor-tijdminuten ophalen
$creditStmt = db()->prepare(
    'SELECT COALESCE(SUM(minutes), 0)
     FROM time_credits
     WHERE user_id = ?'
);

$creditStmt->execute([$user['id']]);
$creditMinutes = (int)$creditStmt->fetchColumn();

$creditHours = $creditMinutes / 60;

// Totaal beschikbaar saldo berekenen
$totalAvailable = (float)$user['allowance_hours'] + $creditHours;
$remaining = $totalAvailable - $used;

$hoursPerDay = (float)$user['leave_day_hours'];

if ($hoursPerDay <= 0) {
    $hoursPerDay = 7.75;
}

$availableDays = $totalAvailable / $hoursPerDay;
$usedDays = $used / $hoursPerDay;
$remainingDays = $remaining / $hoursPerDay;

$csrf = csrf_token();

$employees = [];

if ($user['role'] === 'admin') {
    $employees = db()->query(
        'SELECT id, name, email, allowance_hours
         FROM users
         ORDER BY name'
    )->fetchAll();
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">

    <title>Verlofuren</title>

    <link rel="stylesheet" href="assets/style.css">

    <link
        href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css"
        rel="stylesheet"
    >

    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/locales-all.global.min.js"></script>
</head>

<body>

<header class="topbar">
    <a class="brand" href="index.php">
        <span class="brand-mark small-mark">V</span>
        Verlofuren
    </a>

    <nav>
        <span class="user-greeting">
            <?= e($user['name']) ?>
            <?= $user['role'] === 'admin' ? ' · Beheerder' : '' ?>
        </span>

        <?php if ($user['role'] === 'admin'): ?>
            <a href="medewerkers.php">Medewerkers</a>
        <?php endif; ?>

        <a href="export.php">Export CSV</a>
        <a href="admin.php">Admin</a>
        <a href="logout.php">Uitloggen</a>
    </nav>
</header>

<main class="container">

    <div class="page-heading">
        <div>
            <p class="eyebrow">WERKPLANNING</p>
            <h1>Verlofkalender</h1>
            <p class="muted">
                Plan verlof en houd je tijd-voor-tijdsaldo bij.
            </p>
        </div>

        <button class="button" id="addLeave">
            Boeking toevoegen
        </button>
    </div>

    <section class="stats">

        <article class="stat-card">
            <span>Beschikbaar verlof</span>

            <strong>
                <?= number_format($totalAvailable, 2, ',', '.') ?>
                <small>uur</small>
            </strong>

            <p>
                <?= number_format($availableDays, 2, ',', '.') ?> dagen
            </p>
        </article>

        <article class="stat-card">
            <span>Ingepland / opgenomen</span>

            <strong>
                <?= number_format($used, 2, ',', '.') ?>
                <small>uur</small>
            </strong>

            <p>
                <?= number_format($usedDays, 2, ',', '.') ?> dagen
            </p>
        </article>

        <article class="stat-card">
            <span>Resterend saldo</span>

            <strong class="<?= $remaining < 0 ? 'negative' : '' ?>">
                <?= number_format($remaining, 2, ',', '.') ?>
                <small>uur</small>
            </strong>

            <p>
                <?= number_format($remainingDays, 2, ',', '.') ?> dagen
            </p>
        </article>

    </section>

    <div class="legend">
        <span>
            <i class="dot leave-dot"></i>
            Verlof
        </span>

        <span>
            <i class="dot" style="background:#22c55e"></i>
            Tijd voor tijd
        </span>

        <span>
            <i class="dot holiday-dot"></i>
            Feestdag
        </span>

        <span class="muted">
            Klik op een boeking om deze te bekijken of aan te passen.
        </span>
    </div>

    <section class="calendar-card">
        <div id="calendar"></div>
    </section>

    <p class="footnote">
        Automatische berekening gebruikt je werkrooster.
        Een volledige werkdag is 7,75 uur, van 08:45 tot 16:30.
        Feestdagen worden niet van de automatische berekening afgetrokken.
    </p>

</main>

<dialog id="leaveDialog">

    <form id="leaveForm">

        <div class="modal-heading">
            <div>
                <p class="eyebrow">BOEKING</p>
                <h2 id="dialogTitle">Boeking toevoegen</h2>
            </div>

            <button
                type="button"
                class="icon-button"
                id="closeDialog"
                aria-label="Sluiten"
            >
                ×
            </button>
        </div>

        <input type="hidden" id="leaveId">

        <!-- Type boeking -->
        <label>
            Type boeking

            <select id="entryType" required>
                <option value="leave">Verlof</option>
                <option value="time_credit">Tijd voor tijd</option>
            </select>
        </label>

        <!-- Medewerker -->
        <label>
            Medewerker

            <?php if ($user['role'] === 'admin'): ?>

                <select id="employeeId" required>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= (int)$emp['id'] ?>">
                            <?= e($emp['name']) ?>
                            (<?= e($emp['email']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

            <?php else: ?>

                <input
                    type="text"
                    value="<?= e($user['name']) ?>"
                    disabled
                >

            <?php endif; ?>
        </label>

        <!-- Verlofvelden -->
        <div id="leaveFields">

            <div class="form-grid">

                <label>
                    Begindatum
                    <input type="date" id="startDate" required>
                </label>

                <label>
                    Einddatum
                    <input type="date" id="endDate" required>
                </label>

            </div>

            <div class="form-grid">

                <label>
                    Begintijd
                    <input
                        type="time"
                        id="startTime"
                        value="08:45"
                        required
                    >
                </label>

                <label>
                    Eindtijd
                    <input
                        type="time"
                        id="endTime"
                        value="16:30"
                        required
                    >
                </label>

            </div>

            <p class="muted">
                Normale werktijden: 08:45 tot 16:30 (7,75 uur).
                Je kunt de tijden per verlofboeking aanpassen.
            </p>

            <label>
                Berekening

                <select id="calculationMode">
                    <option value="automatic">
                        Automatisch volgens werkrooster
                    </option>

                    <option value="manual">
                        Handmatig aantal uren
                    </option>
                </select>
            </label>

            <label id="hoursWrap" class="hidden">
                Aantal uren

                <input
                    type="number"
                    id="hours"
                    min="0"
                    max="1000"
                    step="0.01"
                    value="1"
                >
            </label>

        </div>

        <!-- Tijd-voor-tijdvelden -->
        <div id="creditFields" class="hidden">

            <label>
                Datum waarop je extra hebt gewerkt

                <input type="date" id="creditDate">
            </label>

            <label>
                Aantal minuten

                <input
                    type="number"
                    id="creditMinutes"
                    min="15"
                    step="1"
                    placeholder="Bijvoorbeeld 45"
                >
            </label>

            <p class="muted">
                Vul het aantal extra gewerkte minuten in.
                Je kunt minimaal 15 minuten registreren.
            </p>

        </div>

        <!-- Opmerking -->
        <label>
            Opmerking (optioneel)

            <input
                type="text"
                id="note"
                maxlength="255"
                placeholder="Bijv. vakantie of extra gewerkt"
            >
        </label>

        <p id="formError" class="form-error"></p>

        <div class="modal-actions">

            <button
                type="button"
                class="button secondary hidden"
                id="deleteLeave"
            >
                Verwijderen
            </button>

            <span class="spacer"></span>

            <button
                type="button"
                class="button secondary"
                id="cancelDialog"
            >
                Annuleren
            </button>

            <button type="submit" class="button">
                Opslaan
            </button>

        </div>

    </form>

</dialog>

<script>
window.APP_CONFIG = {
    csrf: <?= json_encode($csrf) ?>,
    isAdmin: <?= json_encode($user['role'] === 'admin') ?>,
    currentUserId: <?= json_encode((int)$user['id']) ?>
};
</script>

<script src="assets/app.js"></script>

</body>
</html>