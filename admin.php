
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
            ELSE 1
        END,
        lr.created_at DESC
");

$requests = $stmt->fetchAll();

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

?>

<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Aanvragen beheren - Verlofuren</title>

    <link rel="stylesheet" href="assets/style.css">
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
        Hier kun je verlof- en afspraakaanvragen van medewerkers bekijken
        en goedkeuren of afwijzen.
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

    <section>

        <h2>Ingekomen aanvragen</h2>

        <?php if (!$requests): ?>

            <p>Er zijn nog geen aanvragen binnengekomen.</p>

        <?php else: ?>

            <div style="overflow-x: auto;">

                <table>

                    <thead>
                        <tr>
                            <th>Medewerker</th>
                            <th>Type</th>
                            <th>Datum</th>
                            <th>Tijd</th>
                            <th>Opmerking</th>
                            <th>Status</th>
                            <th>Acties</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($requests as $request): ?>

                        <tr>

                            <td>
                                <?= e($request['employee_name'] ?? 'Onbekend') ?>
                            </td>

                            <td>
                                <?= e(request_type_label($request['booking_type'])) ?>
                            </td>

                            <td>
                                <?= e($request['start_date']) ?>

                                <?php if ($request['end_date'] !== $request['start_date']): ?>
                                    t/m <?= e($request['end_date']) ?>
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
                                <?= !empty($request['note'])
                                    ? e($request['note'])
                                    : '-' ?>
                            </td>

                            <td>
                                <?= e(request_status_label($request['status'])) ?>
                            </td>

                            <td>

                                <?php if ($request['status'] === 'pending'): ?>

                                    <form method="post" style="display:inline;">

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
                                            class="button"
                                            type="submit"
                                            name="action"
                                            value="approve"
                                        >
                                            Goedkeuren
                                        </button>

                                    </form>

                                    <form method="post" style="display:inline;">

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
                                            class="button"
                                            type="submit"
                                            name="action"
                                            value="reject"
                                        >
                                            Afwijzen
                                        </button>

                                    </form>

                                <?php else: ?>

                                    -

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

    <p>
    <a href="index.php" class="button">
        Terug naar dashboard
    </a>
</p>
</main>

</body>
</html>
