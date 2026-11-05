
<?php
require_once __DIR__ . '/config.php';

$user = require_login();

/*
|--------------------------------------------------------------------------
| Jaarlijks verlofrecht en beginsaldo
|--------------------------------------------------------------------------
*/

$currentYear = (int)date('Y');

$annualAllowance = 193.75;

$yearStart = $currentYear . '-01-01';
$nextYearStart = ($currentYear + 1) . '-01-01';

/*
|--------------------------------------------------------------------------
| Brugdag na Hemelvaartsdag berekenen
|--------------------------------------------------------------------------
*/

function get_bridge_day(int $year): string
{
    $easter = new DateTimeImmutable('@' . easter_date($year));

    $easter = $easter->setTimezone(
        new DateTimeZone('Europe/Amsterdam')
    );

    return $easter
        ->modify('+40 days')
        ->format('Y-m-d');
}

/*
|--------------------------------------------------------------------------
| Opgenomen verlofuren van het huidige jaar
|--------------------------------------------------------------------------
*/

$balanceStmt = db()->prepare(
    'SELECT COALESCE(SUM(hours), 0)
     FROM leave_entries
     WHERE user_id = ?
       AND booking_type = ?
       AND start_date >= ?
       AND start_date < ?'
);

$balanceStmt->execute([
    $user['id'],
    'leave',
    $yearStart,
    $nextYearStart
]);

$used = (float)$balanceStmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| Automatische brugdag voor het huidige jaar
|--------------------------------------------------------------------------
*/

$bridgeDate = get_bridge_day($currentYear);

$bridgeHours = (float)$user['leave_day_hours'];

if ($bridgeHours <= 0) {
    $bridgeHours = 7.75;
}

$bridgeCheck = db()->prepare(
    'SELECT COUNT(*)
     FROM leave_entries
     WHERE user_id = ?
       AND booking_type = ?
       AND start_date <= ?
       AND end_date >= ?'
);

$bridgeCheck->execute([
    $user['id'],
    'leave',
    $bridgeDate,
    $bridgeDate
]);

$bridgeAlreadyBooked = (int)$bridgeCheck->fetchColumn() > 0;

if (!$bridgeAlreadyBooked) {
    $used += $bridgeHours;
}

/*
|--------------------------------------------------------------------------
| Tijd-voor-tijdminuten van het huidige jaar
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
| Uitbetaalde verlofuren van het huidige jaar
|--------------------------------------------------------------------------
*/

$payoutStmt = db()->prepare(
    'SELECT COALESCE(SUM(hours), 0)
     FROM leave_payouts
     WHERE user_id = ?
       AND payout_date >= ?
       AND payout_date < ?'
);

$payoutStmt->execute([
    $user['id'],
    $yearStart,
    $nextYearStart
]);

$payoutHours = (float)$payoutStmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| Totaal beschikbaar saldo berekenen
|--------------------------------------------------------------------------
*/

$totalAvailable = $annualAllowance;

$remaining = $annualAllowance
    + $creditHours
    - $used
    - $payoutHours;

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

/*
|--------------------------------------------------------------------------
| Rolnaam bepalen
|--------------------------------------------------------------------------
*/

$roleLabel = $user['role'] === 'admin'
    ? 'Beheerder'
    : 'Medewerker';
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
            <span class="user-name">
                <?= e($user['name']) ?>
            </span>

            <span class="user-role">
                <?= ($user['role'] === 'admin') ? 'Beheerder' : 'Medewerker' ?>
            </span>

            <?php if (!empty($user['work_number'])): ?>
                <span class="user-work-number">
                    Werknummer: <?= e($user['work_number']) ?>
                </span>
            <?php endif; ?>
        </span>

        <?php if ($user['role'] === 'admin'): ?>
            <a href="medewerkers.php">Medewerkers</a>
            <a href="admin.php">Admin</a>
        <?php endif; ?>

        <a href="export.php">Export CSV</a>
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

        <div class="page-actions">

            <button class="button secondary" id="addPayout">
                Verlofuren uitbetalen
            </button>

            <button class="button" id="addLeave">
                Boeking toevoegen
            </button>

        </div>
    </div>

    <section class="stats">

        <article class="stat-card available-card">
            <div class="stat-card-top">
                <span class="stat-icon">◷</span>
                <span class="stat-label">Beschikbaar verlof</span>
            </div>

            <strong class="stat-value">
                <span id="availableHours">
                    <?= number_format($annualAllowance, 2, ',', '.') ?>
                </span>
                <small>uur</small>
            </strong>

            <p class="stat-meta">
                <span id="availableDays">
                    <?= number_format($availableDays, 2, ',', '.') ?>
                </span>
                dagen beschikbaar
            </p>
        </article>

        <article class="stat-card used-card">
            <div class="stat-card-top">
                <span class="stat-icon">▣</span>
                <span class="stat-label">Ingepland / opgenomen</span>
            </div>

            <strong class="stat-value">
                <span id="usedHours">
                    <?= number_format($used, 2, ',', '.') ?>
                </span>
                <small>uur</small>
            </strong>

            <p class="stat-meta">
                <span id="usedDays">
                    <?= number_format($usedDays, 2, ',', '.') ?>
                </span>
                dagen ingepland
            </p>
        </article>

        <article class="stat-card remaining-card">
            <div class="stat-card-top">
                <span class="stat-icon">✓</span>
                <span class="stat-label">Resterend saldo</span>
            </div>

            <strong
                id="remainingHoursWrapper"
                class="stat-value <?= $remaining < 0 ? 'negative' : '' ?>"
            >
                <span id="remainingHours">
                    <?= number_format($remaining, 2, ',', '.') ?>
                </span>
                <small>uur</small>
            </strong>

            <p class="stat-meta">
                <span id="remainingDays">
                    <?= number_format($remainingDays, 2, ',', '.') ?>
                </span>
                dagen over
            </p>
        </article>

    </section>

    <div class="legend">

        <span>
            <i class="dot leave-dot"></i>
            Verlof
        </span>

        <span>
            <i class="dot sick-dot"></i>
            Ziek
        </span>

        <span>
            <i class="dot" style="background-color: #2563eb;"></i>
            Dokter
        </span>

        <span>
            <i class="dot" style="background-color: #0f766e;"></i>
            Tandarts
        </span>

        <span>
            <i class="dot time-credit-dot"></i>
            Tijd voor tijd
        </span>

        <span>
            <i class="dot holiday-dot"></i>
            Feestdag
        </span>

        <span>
            <i class="dot bridge-day-dot"></i>
            Verplichte vrije dag
        </span>

        <span class="muted">
            Klik op een boeking om deze te bekijken of aan te passen.
        </span>

    </div>

    <section class="calendar-card">
        <div id="calendar"></div>
    </section>

    <!-- Uitbetalingsgeschiedenis -->

    <section class="calendar-card payout-card">

        <div class="section-heading">
            <div>
                <h2>Uitbetaalde verlofuren</h2>
                <p class="muted">
                    Overzicht van de geregistreerde uitbetalingen.
                </p>
            </div>
        </div>

        <div id="payoutHistory">
            <p class="muted">
                Uitbetalingen worden geladen...
            </p>
        </div>

    </section>

    <p class="footnote">
        Je jaarlijkse verlofrecht bedraagt
        <?= number_format($annualAllowance, 2, ',', '.') ?> uur.
        Het verlofsaldo en tijd-voor-tijdsaldo worden per kalenderjaar berekend.
        Een volledige werkdag is 7,75 uur, van 08:45 tot 16:30.
        Feestdagen worden niet van de automatische berekening afgetrokken.
        De vrijdag na Hemelvaartsdag wordt automatisch als verplichte vrije dag meegerekend.
        Ziek, doktersafspraken en tandartsafspraken kosten geen verlofuren.
    </p>

</main>

<!-- Formulier voor verlofuren uitbetalen -->

<dialog id="payoutDialog">

    <form id="payoutForm">

        <div class="modal-heading">
            <div>
                <p class="eyebrow">UITBETALING</p>
                <h2>Verlofuren uitbetalen</h2>
            </div>

            <button
                type="button"
                class="icon-button"
                id="closePayoutDialog"
                aria-label="Sluiten"
            >
                ×
            </button>
        </div>

        <?php if ($user['role'] === 'admin'): ?>

            <label>
                Medewerker

                <select id="payoutEmployeeId" required>

                    <?php foreach ($employees as $emp): ?>

                        <option value="<?= (int)$emp['id'] ?>">
                            <?= e($emp['name']) ?>
                            (<?= e($emp['email']) ?>)
                        </option>

                    <?php endforeach; ?>

                </select>
            </label>

        <?php endif; ?>

        <label>
            Datum van uitbetaling

            <input
                type="date"
                id="payoutDate"
                required
            >
        </label>

        <label>
            Aantal uit te betalen uren

            <input
                type="number"
                id="payoutHours"
                min="0.01"
                max="1000"
                step="0.01"
                placeholder="Bijvoorbeeld 8"
                required
            >
        </label>

        <label>
            Opmerking (optioneel)

            <input
                type="text"
                id="payoutNote"
                maxlength="255"
                placeholder="Bijvoorbeeld uitbetaling vakantiedagen"
            >
        </label>

        <p id="payoutError" class="form-error"></p>

        <div class="modal-actions">

            <span class="spacer"></span>

            <button
                type="button"
                class="button secondary"
                id="cancelPayoutDialog"
            >
                Annuleren
            </button>

            <button type="submit" class="button">
                Uitbetaling opslaan
            </button>

        </div>

    </form>

</dialog>

<!-- Bestaand formulier voor boekingen -->

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

        <label>
            Type boeking

            <select id="entryType" required>
                <option value="leave">Verlof</option>
                <option value="sick">Ziek</option>
                <option value="doctor">Dokter</option>
                <option value="dentist">Tandarts</option>
                <option value="time_credit">Tijd voor tijd</option>
            </select>
        </label>

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
                Bij ziek, dokter en tandarts worden geen verlofuren berekend.
            </p>

            <label id="calculationWrap">
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
