<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['user'])) {
    header('Location: /login.php');
    exit;
}

$user = $_SESSION['user'];
$id = (int)($_GET['id'] ?? 0);
$isAdmin = (($user['role'] ?? '') === 'admin');
$userBranchId = (int)($user['branch_id'] ?? 0);

if ($id <= 0) {
    header('Location: /private/patients/index.php');
    exit;
}

$db_candidates = [
    __DIR__ . '/../config/db.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../db.php',
    __DIR__ . '/../../db.php',
];

$loaded = false;
foreach ($db_candidates as $p) {
    if (is_file($p)) {
        require_once $p;
        $loaded = true;
        break;
    }
}

if (!$loaded || !isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Error crítico: no se pudo cargar la conexión a la base de datos.';
    exit;
}

function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function ageFrom($birthDate)
{
    if (!$birthDate) {
        return '';
    }

    try {
        $dob = new DateTime((string)$birthDate);
        $today = new DateTime();
        $diff = $today->diff($dob);
        return (string)$diff->y;
    } catch (Throwable $e) {
        return '';
    }
}

/* ===============================
   CARGAR PACIENTE
   =============================== */
if ($isAdmin) {
    $stmt = $pdo->prepare("
        SELECT p.*, b.name AS branch_name
        FROM patients p
        LEFT JOIN branches b ON b.id = p.branch_id
        WHERE p.id = :id
    ");
    $stmt->execute(['id' => $id]);
} else {
    if ($userBranchId <= 0) {
        header('Location: /logout.php');
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT p.*, b.name AS branch_name
        FROM patients p
        LEFT JOIN branches b ON b.id = p.branch_id
        WHERE p.id = :id AND p.branch_id = :bid
    ");
    $stmt->execute([
        'id'  => $id,
        'bid' => $userBranchId
    ]);
}

$p = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$p) {
    http_response_code(404);
    echo 'Paciente no encontrado.';
    exit;
}

$fullName = trim((string)($p['first_name'] ?? '') . ' ' . (string)($p['last_name'] ?? ''));


$success = '';
$error = '';

/* ===============================
   VALIDAR ESTRUCTURA REAL DE TABLA
   =============================== */
$pvColumns = [];

try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM patient_vaccines");
    $cols = $colStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cols as $col) {
        if (!empty($col['Field'])) {
            $pvColumns[] = $col['Field'];
        }
    }
} catch (Throwable $e) {
    $error = 'No se pudo leer la estructura de la tabla patient_vaccines.';
}

$hasPatientId       = in_array('patient_id', $pvColumns, true);
$hasVaccineName     = in_array('vaccine_name', $pvColumns, true);
$hasApplicationDate = in_array('application_date', $pvColumns, true);
$hasComment         = in_array('comment', $pvColumns, true);
$hasCreatedAt       = in_array('created_at', $pvColumns, true);

/* ===============================
   GUARDAR VACUNA
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vaccineName = trim((string)($_POST['vaccine_name'] ?? ''));
    $applicationDate = trim((string)($_POST['application_date'] ?? ''));
    $comments = trim((string)($_POST['comments'] ?? ''));

    if ($vaccineName === '') {
        $error = 'Debes escribir una vacuna.';
    } elseif ($applicationDate === '') {
        $error = 'La fecha de aplicación es obligatoria.';
    } elseif (!$hasPatientId || !$hasVaccineName || !$hasApplicationDate) {
        $error = 'La tabla patient_vaccines no tiene la estructura mínima requerida.';
    } else {
        try {
            $sqlInsert = "
                INSERT INTO patient_vaccines (
                    patient_id,
                    vaccine_name,
                    application_date,
                    comment
                ) VALUES (
                    :patient_id,
                    :vaccine_name,
                    :application_date,
                    :comment
                )
            ";

            $ins = $pdo->prepare($sqlInsert);
            $ins->execute([
                'patient_id'       => $id,
                'vaccine_name'     => $vaccineName,
                'application_date' => $applicationDate,
                'comment'          => ($hasComment ? ($comments !== '' ? $comments : null) : null)
            ]);

            header('Location: /private/patients/esquema.php?id=' . $id . '&saved=1');
            exit;
        } catch (Throwable $e) {
            $error = 'No se pudo guardar la vacuna: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] == '1') {
    $success = 'Vacuna registrada correctamente.';
}

/* ===============================
   HISTORIAL DE VACUNAS
   =============================== */
$vaccineHistory = [];

if ($hasPatientId && $hasVaccineName && $hasApplicationDate) {
    try {
        $commentSelect = $hasComment ? 'comment' : 'NULL';
        $createdAtSelect = $hasCreatedAt ? 'created_at' : 'NULL';

        $sqlHistory = "
            SELECT
                id,
                vaccine_name,
                application_date,
                {$commentSelect} AS comments,
                {$createdAtSelect} AS created_at
            FROM patient_vaccines
            WHERE patient_id = :patient_id
            ORDER BY application_date DESC, id DESC
        ";

        $historyStmt = $pdo->prepare($sqlHistory);
        $historyStmt->execute(['patient_id' => $id]);
        $vaccineHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        if ($error === '') {
            $error = 'No se pudo cargar el historial de vacunas: ' . $e->getMessage();
        }
    }
} elseif ($error === '') {
    $error = 'La tabla patient_vaccines no tiene una estructura válida para mostrar el historial.';
}

$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CEVIMEP | Esquema de vacunación</title>

    <link rel="stylesheet" href="/assets/css/styles.css?v=60">
    <link rel="stylesheet" href="/assets/css/paciente.css?v=2">

    <style>
        .patients-wrap{max-width:1200px;margin:0 auto;padding:18px 18px 28px;}
        .patients-header{text-align:center;margin-top:4px;margin-bottom:12px;}
        .patients-header h1{margin:0;font-size:34px;font-weight:900;}
        .patients-header p{margin:8px 0 0;opacity:.78;font-weight:600;}
        .patients-actions{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin:14px 0 20px;}
        .grid-top{display:grid;grid-template-columns:1fr 1fr;gap:22px;align-items:start;}
        .card{background:#fff;border-radius:16px;box-shadow:0 10px 28px rgba(0,0,0,.08);padding:20px;}
        .card h3{margin:0 0 16px;font-size:22px;font-weight:900;color:#0b2f6b;}
        .kv-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
        .kv{background:#f7f9fc;border:1px solid rgba(0,0,0,.06);border-radius:12px;padding:12px;}
        .kv .k{font-weight:800;opacity:.8;margin-bottom:4px;}
        .kv .v{font-weight:700;}
        .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;}
        .form-group{display:flex;flex-direction:column;gap:6px;}
        .form-group label{font-weight:800;color:#173b7a;}
        .form-group input,.form-group select,.form-group textarea{
            width:100%;
            border:1px solid rgba(0,0,0,.12);
            border-radius:12px;
            padding:12px 14px;
            font:inherit;
            outline:none;
            background:#fff;
        }
        .form-group textarea{min-height:92px;max-height:140px;resize:vertical;}
        .register-card{height:auto;}
        .history-card{margin-top:22px;}
        .history-card .table-wrap{max-height:260px;overflow:auto;}
        .history-card .table thead th{position:sticky;top:0;z-index:2;}
        .history-card .table td:nth-child(3){max-width:420px;white-space:normal;word-break:break-word;}
        .history-card .table td{vertical-align:top;}
        .full{grid-column:1 / -1;}
        .alert{margin-bottom:14px;padding:12px 14px;border-radius:12px;font-weight:700;word-break:break-word;}
        .alert-success{background:#e9f9ef;color:#166534;border:1px solid #b7ebc6;}
        .alert-error{background:#fff0f0;color:#991b1b;border:1px solid #f5b5b5;}

        /* Evita que el historial siga creciendo hacia abajo cada vez que agregas vacunas */
        .history-card{
            margin-top:18px;
            max-height:260px;
            overflow:hidden;
            padding-bottom:14px;
        }
        .history-card h3{
            margin-bottom:12px;
        }
        .table-wrap{
            max-height:175px;
            overflow-y:auto;
            overflow-x:auto;
            border-radius:10px;
        }
        .table{width:100%;border-collapse:collapse;}
        .table th{
            position:sticky;
            top:0;
            z-index:2;
            background:#0f4fa8;
            color:#fff;
            padding:10px 10px;
            text-align:left;
            font-size:14px;
        }
        .table td{
            padding:10px 10px;
            border-bottom:1px solid #eef1f6;
            background:#fff;
            vertical-align:top;
        }
        .table td:nth-child(3){
            max-width:360px;
            white-space:normal;
            word-break:break-word;
        }
        .empty-state{text-align:center;padding:24px 10px;font-weight:700;opacity:.75;}

        @media (max-width:980px){
            .grid-top,.form-grid,.kv-grid{grid-template-columns:1fr;}
            .full{grid-column:auto;}
        }
    </style>
</head>
<body>

<header class="navbar">
    <div class="inner">
        <div class="brand">
            <span class="dot"></span>
            <span>CEVIMEP</span>
        </div>
        <div class="nav-right">
            <a href="/logout.php" class="btn-pill">Salir</a>
        </div>
    </div>
</header>

<div class="layout">
    <aside class="sidebar">
        <div class="menu-title">Menú</div>
        <nav class="menu">
            <a href="/private/dashboard.php">🏠 Panel</a>
            <a class="active" href="/private/patients/index.php">👤 Pacientes</a>
            <a href="/private/citas/index.php">📅 Citas</a>
            <a href="/private/facturacion/index.php">🧾 Facturación</a>
            <a href="/private/caja/index.php">💳 Caja</a>
            <a href="/private/inventario/index.php">📦 Inventario</a>
            <a href="/private/estadistica/index.php">📊 Estadísticas</a>
        </nav>
    </aside>

    <main class="content">
        <div class="patients-wrap">

            <div class="patients-header">
                <h1>Esquema de vacunación</h1>
                <p><?= h($fullName) ?></p>
            </div>

            <div class="patients-actions">
                <a class="btn" href="/private/patients/index.php">← Volver</a>
                <a class="btn btn-primary" href="/private/patients/edit.php?id=<?= (int)$id ?>">Editar paciente</a>
            </div>

            <div class="grid-top">
                <div class="card">
                    <h3>Datos del paciente</h3>
                    <div class="kv-grid">
                        <div class="kv"><div class="k">No. Libro</div><div class="v"><?= h($p['no_libro'] ?? '') ?></div></div>
                        <div class="kv"><div class="k">Sucursal</div><div class="v"><?= h($p['branch_name'] ?? '') ?></div></div>
                        <div class="kv"><div class="k">Nombre</div><div class="v"><?= h($fullName) ?></div></div>
                        <div class="kv"><div class="k">Cédula</div><div class="v"><?= h($p['cedula'] ?? '') ?></div></div>
                        <div class="kv"><div class="k">Teléfono</div><div class="v"><?= h($p['phone'] ?? '') ?></div></div>
                        <div class="kv"><div class="k">Correo</div><div class="v"><?= h($p['email'] ?? '') ?></div></div>
                        <div class="kv"><div class="k">Edad</div><div class="v"><?= h(ageFrom($p['birth_date'] ?? null)) ?></div></div>
                        <div class="kv"><div class="k">Tipo de sangre</div><div class="v"><?= h($p['blood_type'] ?? '') ?></div></div>
                    </div>
                </div>

                <div class="card register-card">
                    <h3>Registrar vacuna</h3>

                    <?php if ($success): ?>
                        <div class="alert alert-success"><?= h($success) ?></div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-error"><?= h($error) ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="vaccine_name">Vacuna</label>
                                <input
                                    type="text"
                                    name="vaccine_name"
                                    id="vaccine_name"
                                    placeholder="Escriba la vacuna"
                                    autocomplete="off"
                                    value="<?= h($_POST['vaccine_name'] ?? '') ?>"
                                    required
                                >
                            </div>

                            <div class="form-group">
                                <label for="application_date">Fecha de aplicación</label>
                                <input
                                    type="date"
                                    id="application_date"
                                    name="application_date"
                                    value="<?= h($_POST['application_date'] ?? $today) ?>"
                                    required
                                >
                            </div>

                            <div class="form-group full">
                                <label for="comments">Comentario / esquema</label>
                                <textarea
                                    id="comments"
                                    name="comments"
                                    placeholder="Ej.: Primera dosis aplicada sin eventos adversos. Próxima dosis en 2 meses."
                                ><?= h($_POST['comments'] ?? '') ?></textarea>
                            </div>

                            <div class="form-group full">
                                <button class="btn btn-primary" type="submit">Guardar vacuna</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card history-card">
                <h3>Historial de vacunas</h3>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Vacuna</th>
                                <th>Fecha</th>
                                <th>Comentario</th>
                                <th>Registrado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($vaccineHistory)): ?>
                                <tr>
                                    <td colspan="4" class="empty-state">Este paciente aún no tiene vacunas registradas.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($vaccineHistory as $item): ?>
                                    <tr>
                                        <td><?= h($item['vaccine_name'] ?? '') ?></td>
                                        <td><?= h($item['application_date'] ?? '') ?></td>
                                        <td><?= h($item['comments'] ?? '') ?></td>
                                        <td><?= h($item['created_at'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>
</div>

<footer class="footer">
    © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>