
<?php
require_once __DIR__ . '/config.php';

$user = require_login();

if ($user['role'] !== 'admin') {
    http_response_code(403);
    exit('Je hebt geen toegang tot deze pagina.');
}

require_admin($user);

$error = '';
$success = '';

/*
|--------------------------------------------------------------------------
| Aanvraag goedkeuren of afwijzen
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $requestId = filter_input(
        INPUT_POST,
        'request_id',
        FILTER_VALIDATE_INT
    );

    $action = $_POST['action'] ?? '';

    if (!$requestId || !in_array($action, ['approve', 'reject'], true)) {
        $error = 'Ongeldige aanvraag.';
    } else {
        $pdo = db();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT *
                 FROM leave_requests
                 WHERE id = ?
                 FOR UPDATE'
            );

            $stmt->execute([$requestId]);
            $request = $stmt->fetch();

            if (!$request) {
                throw new RuntimeException('Aanvraag niet gevonden.');
            }

            if ($request['status'] !== 'pending') {
                throw new RuntimeException(
                    'Deze aanvraag is al beoordeeld.'
                );
            }

            $employeeId = (int)$request['user_id'];

            if ($action === 'approve') {

                /*
                |--------------------------------------------------------------------------
                | Definitieve boeking aanmaken
                |--------------------------------------------------------------------------
                */

                $insert = $pdo->prepare(
                    'INSERT INTO leave_entries (
                        user_id,
                        start_date,
                        end_date,
                        start_time,
                        end_time,
                        hours,
                        calculation_mode,
                        booking_type,
                        note
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                $insert->execute([
                    $employeeId,
                    $request['start_date'],
                    $request['end_date'],
                    $request['start_time'],
                    $request['end_time'],
                    $request['hours'],
                    $request['calculation_mode'],
                    $request['booking_type'],
                    $request['note']
                ]);

                $entryId = (int)$pdo->lastInsertId();

                $update = $pdo->prepare(
                    "UPDATE leave_requests
                     SET status = 'approved',
                         entry_id = ?,
                         reviewed_at = NOW(),
                         reviewed_by = ?
                     WHERE id = ?"
                );

                $update->execute([
                    $entryId,
                    $user['id'],
                    $requestId
                ]);

                $title = 'Aanvraag goedgekeurd';
                $message = 'Je aanvraag voor ' .
                    $request['start_date'] .
                    ' is goedgekeurd.';

            } else {

                /*
                |--------------------------------------------------------------------------
                | Aanvraag afwijzen
                |--------------------------------------------------------------------------
                */

                $update = $pdo->prepare(
                    "UPDATE leave_requests
                     SET status = 'rejected',
                         reviewed_at = NOW(),
                         reviewed_by = ?
                     WHERE id = ?"
                );

                $update->execute([
                    $user['id'],
                    $requestId
                ]);

                $title = 'Aanvraag afgewezen';
                $message = 'Je aanvraag voor ' .
                    $request['start_date'] .
                    ' is afgewezen.';
            }

            /*
            |--------------------------------------------------------------------------
            | Melding voor de medewerker aanmaken
            |--------------------------------------------------------------------------
            */

            $notification = $pdo->prepare(
                'INSERT INTO notifications (
                    user_id,
                    type,
                    title,
                    message,
                    notification_key
                ) VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    message = VALUES(message),
                    read_at = NULL'
            );

            $notification->execute([
                $employeeId,
                'request',
                $title,
                $message,
                'request-' . $requestId
            ]);

            $pdo->commit();

            $success = $action === 'approve'
                ? 'De aanvraag is goedgekeurd.'
                : 'De aanvraag is afgewezen.';

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Aanvragen ophalen
|--------------------------------------------------------------------------
*/

$stmt = db()->query("
    SELECT
        lr.*,
        u.name AS employee_name
    FROM leave_requests lr
    LEFT JOIN users u ON u.id = lr.user_id
    ORDER BY
        CASE
            WHEN lr.status = 'pending' THEN 0
            WHEN lr.status = 'approved' THEN 1
            ELSE 2
        END,
        lr.created_at DESC
");

$requests = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Aantallen per status berekenen
|--------------------------------------------------------------------------
*/

$totalPending = 0;
$totalApproved = 0;
$totalRejected = 0;

foreach ($requests as $request) {
    if ($request['status'] === 'pending') {
        $totalPending++;
    } elseif ($request['status'] === 'approved') {
        $totalApproved++;
    } elseif ($request['status'] === 'rejected') {
        $totalRejected++;
    }
}

function request_type_label($type)
{
    return match ($type) {
        'leave' => 'Verlof',
        'doctor' => 'Dokter',
        'dentist' => 'Tandarts',
        'specialist' => 'Specialist',
        default => ucfirst($type)
    };
}

function request_status_label($status)
{
    return match ($status) {
        'pending' => 'In afwachting',
        'approved' => 'Goedgekeurd',
        'rejected' => 'Afgewezen',
        default => ucfirst($status)
    };
}

function request_date_label($date)
{
    if (empty($date)) {
        return '-';
    }

    $timestamp = strtotime($date);

    return $timestamp
        ? date('d-m-Y', $timestamp)
        : $date;
}

?>

<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Aanvragen beheren - Verlofuren</title>

    <link rel="stylesheet" href="assets/style.css">

    <style>
        /* Overzichtskaarten */

        .request-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin: 25px 0;
        }

        .request-summary-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        }

        .request-summary-card .summary-label {
            display: block;
            color: #64748b;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .request-summary-card .summary-number {
            display: block;
            font-size: 30px;
            font-weight: 800;
            color: #1e293b;
        }

        .request-summary-card.pending {
            border-left: 4px solid #f59e0b;
        }

        .request-summary-card.approved {
            border-left: 4px solid #16a34a;
        }

        .request-summary-card.rejected {
            border-left: 4px solid #dc2626;
        }

        /* Tabel en filters */

        .requests-section {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 22px;
            margin-top: 25px;
        }

        .requests-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin: 20px 0;
        }

        .request-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .request-filter {
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #475569;
            border-radius: 8px;
            padding: 9px 14px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
        }

        .request-filter:hover {
            background: #f8fafc;
        }

        .request-filter.active {
            background: #4f46e5;
            border-color: #4f46e5;
            color: #ffffff;
        }

        .request-search {
            width: 260px;
            max-width: 100%;
            padding: 10px 13px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
        }

        .requests-table-wrapper {
            overflow-x: auto;
            width: 100%;
        }

        .requests-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
        }

        .requests-table th {
            background: #f8fafc;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 14px 12px;
            text-align: left;
            white-space: nowrap;
        }

        .requests-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #e2e8f0;
            color: #334155;
            font-size: 14px;
            vertical-align: middle;
        }

        .requests-table tbody tr:hover {
            background: #f8fafc;
        }

        .requests-table tbody tr:last-child td {
            border-bottom: none;
        }

        .employee-name {
            font-weight: 700;
            color: #1e293b;
        }

        /* Statuslabels */

        .request-status {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .request-status.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .request-status.approved {
            background: #dcfce7;
            color: #166534;
        }

        .request-status.rejected {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Actieknoppen */

        .request-actions {
            display: flex;
            gap: 7px;
            align-items: center;
            white-space: nowrap;
        }

        .request-action-button {
            border: none;
            border-radius: 7px;
            padding: 8px 11px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .request-action-button.approve {
            background: #dcfce7;
            color: #166534;
        }

        .request-action-button.approve:hover {
            background: #bbf7d0;
        }

        .request-action-button.reject {
            background: #fee2e2;
            color: #991b1b;
        }

        .request-action-button.reject:hover {
            background: #fecaca;
        }

        .request-empty {
            text-align: center;
            padding: 35px 15px;
            color: #64748b;
        }

        .request-count {
            color: #64748b;
            font-size: 13px;
            margin-top: 12px;
        }

        @media (max-width: 850px) {
            .request-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 550px) {
            .request-summary {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .request-summary-card {
                padding: 14px;
            }

            .request-summary-card .summary-number {
                font-size: 24px;
            }

            .requests-section {
                padding: 15px;
            }

            .request-search {
                width: 100%;
            }

            .requests-toolbar {
                align-items: stretch;
            }
        }
    </style>
</head>

<body>

<header class="topbar">
    <a class="brand" href="index.php">
        <span class="brand-mark small-mark">V</span>
        Verlofuren
    </a>

    <nav>
        <span class="user-greeting">
            <span class="user-name"><?= e($user['name']) ?></span>
            <span class="user-role">Beheerder</span>

            <?php if (!empty($user['work_number'])): ?>
                <span class="user-work-number">
                    Werknummer: <?= e($user['work_number']) ?>
                </span>
            <?php endif; ?>
        </span>

        <a href="medewerkers.php">Medewerkers</a>
        <a href="admin.php">Aanvragen</a>
        <a href="export.php">Export CSV</a>
        <a href="logout.php">Uitloggen</a>
    </nav>
</header>

<main class="container">

    <h1>Aanvragen beheren</h1>

    <p>
        Bekijk hier alle verlof- en afspraakaanvragen van medewerkers.
        Je kunt aanvragen goedkeuren of afwijzen.
    </p>

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

    <!-- Overzichtskaarten -->

    <div class="request-summary">

        <div class="request-summary-card">
            <span class="summary-label">Alle aanvragen</span>
            <span class="summary-number"><?= count($requests) ?></span>
        </div>

        <div class="request-summary-card pending">
            <span class="summary-label">In afwachting</span>
            <span class="summary-number"><?= $totalPending ?></span>
        </div>

        <div class="request-summary-card approved">
            <span class="summary-label">Goedgekeurd</span>
            <span class="summary-number"><?= $totalApproved ?></span>
        </div>

        <div class="request-summary-card rejected">
            <span class="summary-label">Afgewezen</span>
            <span class="summary-number"><?= $totalRejected ?></span>
        </div>

    </div>

    <!-- Aanvragenoverzicht -->

    <section class="requests-section">

        <h2>Alle aanvragen</h2>

        <div class="requests-toolbar">

            <div class="request-filters">

                <button
                    type="button"
                    class="request-filter active"
                    data-filter="all"
                >
                    Alles
                </button>

                <button
                    type="button"
                    class="request-filter"
                    data-filter="pending"
                >
                    In afwachting
                </button>

                <button
                    type="button"
                    class="request-filter"
                    data-filter="approved"
                >
                    Goedgekeurd
                </button>

                <button
                    type="button"
                    class="request-filter"
                    data-filter="rejected"
                >
                    Afgewezen
                </button>

            </div>

            <input
                type="search"
                id="requestSearch"
                class="request-search"
                placeholder="Zoek medewerker, type of datum..."
            >

        </div>

        <?php if (!$requests): ?>

            <div class="request-empty">
                Er zijn nog geen aanvragen binnengekomen.
            </div>

        <?php else: ?>

            <div class="requests-table-wrapper">

                <table class="requests-table">

                    <thead>
                        <tr>
                            <th>Medewerker</th>
                            <th>Type</th>
                            <th>Periode</th>
                            <th>Tijd</th>
                            <th>Uren</th>
                            <th>Opmerking</th>
                            <th>Ingediend</th>
                            <th>Status</th>
                            <th>Acties</th>
                        </tr>
                    </thead>

                    <tbody id="requestsTableBody">

                    <?php foreach ($requests as $request): ?>

                        <tr
                            data-status="<?= e($request['status']) ?>"
                        >

                            <td>
                                <span class="employee-name">
                                    <?= e($request['employee_name'] ?? 'Onbekend') ?>
                                </span>
                            </td>

                            <td>
                                <?= e(request_type_label($request['booking_type'])) ?>
                            </td>

                            <td>
                                <?= e(request_date_label($request['start_date'])) ?>

                                <?php if ($request['end_date'] !== $request['start_date']): ?>
                                    <br>
                                    <span style="color:#64748b;">
                                        t/m <?= e(request_date_label($request['end_date'])) ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (!empty($request['start_time']) && !empty($request['end_time'])): ?>
                                    <?= e(substr($request['start_time'], 0, 5)) ?>
                                    -
                                    <?= e(substr($request['end_time'], 0, 5)) ?>
                                <?php else: ?>
                                    Hele dag
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= number_format((float)($request['hours'] ?? 0), 2, ',', '.') ?>
                                uur
                            </td>

                            <td>
                                <?= !empty($request['note'])
                                    ? e($request['note'])
                                    : '-' ?>
                            </td>

                            <td>
                                <?= e(request_date_label($request['created_at'] ?? '')) ?>
                            </td>

                            <td>
                                <span class="request-status <?= e($request['status']) ?>">
                                    <?= e(request_status_label($request['status'])) ?>
                                </span>
                            </td>

                            <td>

                                <?php if ($request['status'] === 'pending'): ?>

                                    <div class="request-actions">

                                        <form method="post">

                                            <input
                                                type="hidden"
                                                name="csrf"
                                                value="<?= e(csrf_token()) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="request_id"
                                                value="<?= (int)$request['id'] ?>"
                                            >

                                            <button
                                                class="request-action-button approve"
                                                type="submit"
                                                name="action"
                                                value="approve"
                                                onclick="return confirm('Weet je zeker dat je deze aanvraag wilt goedkeuren?');"
                                            >
                                                Goedkeuren
                                            </button>

                                        </form>

                                        <form method="post">

                                            <input
                                                type="hidden"
                                                name="csrf"
                                                value="<?= e(csrf_token()) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="request_id"
                                                value="<?= (int)$request['id'] ?>"
                                            >

                                            <button
                                                class="request-action-button reject"
                                                type="submit"
                                                name="action"
                                                value="reject"
                                                onclick="return confirm('Weet je zeker dat je deze aanvraag wilt afwijzen?');"
                                            >
                                                Afwijzen
                                            </button>

                                        </form>

                                    </div>

                                <?php else: ?>

                                    <span style="color:#94a3b8;">Beoordeeld</span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

            <p class="request-count" id="requestCount">
                <?= count($requests) ?> aanvragen weergegeven
            </p>

            <div class="request-empty" id="noSearchResults" style="display:none;">
                Geen aanvragen gevonden.
            </div>

        <?php endif; ?>

    </section>

    <p style="margin-top:25px;">
        <a href="index.php" class="button">
            Terug naar dashboard
        </a>
    </p>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('requestSearch');
    const tableBody = document.getElementById('requestsTableBody');
    const filterButtons = document.querySelectorAll('.request-filter');
    const countElement = document.getElementById('requestCount');
    const noResults = document.getElementById('noSearchResults');

    if (!tableBody) {
        return;
    }

    let activeFilter = 'all';

    function filterRequests() {

        const searchValue = searchInput
            ? searchInput.value.toLowerCase().trim()
            : '';

        const rows = tableBody.querySelectorAll('tr');

        let visibleCount = 0;

        rows.forEach(function (row) {

            const status = row.dataset.status;
            const rowText = row.textContent.toLowerCase();

            const matchesStatus =
                activeFilter === 'all' || status === activeFilter;

            const matchesSearch =
                rowText.includes(searchValue);

            const visible = matchesStatus && matchesSearch;

            row.style.display = visible ? '' : 'none';

            if (visible) {
                visibleCount++;
            }

        });

        if (countElement) {
            countElement.textContent =
                visibleCount + ' aanvragen weergegeven';
        }

        if (noResults) {
            noResults.style.display =
                visibleCount === 0 ? 'block' : 'none';
        }
    }

    filterButtons.forEach(function (button) {

        button.addEventListener('click', function () {

            activeFilter = button.dataset.filter;

            filterButtons.forEach(function (filterButton) {
                filterButton.classList.remove('active');
            });

            button.classList.add('active');

            filterRequests();

        });

    });

    if (searchInput) {
        searchInput.addEventListener('input', filterRequests);
    }

    filterRequests();

});
</script>

</body>
</html>
