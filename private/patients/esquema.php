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

function formatDateDMY($date)
{
    if (!$date) {
        return '';
    }

    try {
        return (new DateTime((string)$date))->format('d/m/Y');
    } catch (Throwable $e) {
        return '';
    }
}

function formatDateTimeDMY($date)
{
    if (!$date) {
        return '';
    }

    try {
        return (new DateTime((string)$date))->format('d/m/Y H:i:s');
    } catch (Throwable $e) {
        return '';
    }
}

function dateToDB($date)
{
    $date = trim((string)$date);

    if ($date === '') {
        return '';
    }

    $d = DateTime::createFromFormat('d/m/Y', $date);
    if ($d instanceof DateTime) {
        return $d->format('Y-m-d');
    }

    $d = DateTime::createFromFormat('Y-m-d', $date);
    if ($d instanceof DateTime) {
        return $d->format('Y-m-d');
    }

    return '';
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
   LISTADO DE VACUNAS
   =============================== */
$availableVaccines = [
    'Varivax',
    'Varilrix',
    'Avaxim 80',
    'Hep A Genérica',
    'Havrix',
    'SRP genérica',
    'Priorix',
    'Infanrix',
    'Hexaxim',
    'Pentaxim',
    'Boostrix',
    'Adacel',
    'Tetraxim',
    'Imovax Polio',
    'Rotateq',
    'Shingrix',
    'Inmunoglobulina Anti-D Rhophylac 300',
    'Inmunoglobulina Anti-D Rhoclone',
    'Influvac',
    'Vaxigrip',
    'Gardasil',
    'Tetanogamma',
    'DT genérica',
    'Prevenar 13',
    'Prevenar 20',
    'Synflorix',
    'Vaxneuvance 15',
    'Pneumovax 23',
    'Engerix B',
    'Hep B genérica adulto',
    'Hep B genérica pediátrica',
    'Abrysvo',
    'Menquadfi',
    'Menactra',
    'Meningococo BC'
];

/* ===============================
   GUARDAR VACUNAS
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vaccineNames = $_POST['vaccine_names'] ?? [];

    if (!is_array($vaccineNames)) {
        $vaccineNames = [];
    }

    $vaccineNames = array_values(array_unique(array_filter(array_map(function($v) {
        return trim((string)$v);
    }, $vaccineNames))));

    $applicationDateRaw = trim((string)($_POST['application_date'] ?? ''));
    $applicationDate = dateToDB($applicationDateRaw);
    $comments = trim((string)($_POST['comments'] ?? ''));

    if (empty($vaccineNames)) {
        $error = 'Debes seleccionar al menos una vacuna.';
    } elseif ($applicationDateRaw === '' || $applicationDate === '') {
        $error = 'La fecha de aplicación es obligatoria y debe tener formato DD/MM/AAAA.';
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

            foreach ($vaccineNames as $vaccineName) {
                $ins->execute([
                    'patient_id'       => $id,
                    'vaccine_name'     => $vaccineName,
                    'application_date' => $applicationDate,
                    'comment'          => ($hasComment ? ($comments !== '' ? $comments : null) : null)
                ]);
            }

            header('Location: /private/patients/esquema.php?id=' . $id . '&saved=' . count($vaccineNames));
            exit;
        } catch (Throwable $e) {
            $error = 'No se pudieron guardar las vacunas: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['saved']) && (int)$_GET['saved'] > 0) {
    $savedCount = (int)$_GET['saved'];
    $success = $savedCount === 1
        ? 'Vacuna registrada correctamente.'
        : $savedCount . ' vacunas registradas correctamente.';
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

$today = date('d/m/Y');
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
        .grid-top{display:grid;grid-template-columns:1fr;gap:16px;align-items:start;max-width:520px;margin:0 auto;}
        .card{background:#fff;border-radius:16px;box-shadow:0 10px 28px rgba(0,0,0,.08);padding:16px;}
        .card h3{margin:0 0 12px;font-size:20px;font-weight:900;color:#0b2f6b;text-align:center;}
        .kv-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
        .kv{background:#f7f9fc;border:1px solid rgba(0,0,0,.06);border-radius:12px;padding:12px;}
        .kv .k{font-weight:800;opacity:.8;margin-bottom:4px;}
        .kv .v{font-weight:700;}
        .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
        .form-group{display:flex;flex-direction:column;gap:6px;}
        .form-group label{font-weight:800;color:#173b7a;}
        .form-group input,.form-group select,.form-group textarea{
            width:100%;
            border:1px solid rgba(0,0,0,.12);
            border-radius:12px;
            padding:9px 11px;
            font:inherit;
            outline:none;
            background:#fff;
        }
        .vaccine-box{
            border:1px solid rgba(0,0,0,.12);
            border-radius:12px;
            padding:8px;
            max-height:145px;
            overflow-y:auto;
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:6px;
            background:#fff;
        }
        .vaccine-option{
            display:flex;
            align-items:center;
            gap:6px;
            font-size:13px;
            font-weight:700;
            color:#18223a;
            padding:5px 6px;
            border-radius:8px;
            background:#f8fafc;
        }
        .vaccine-option input{
            width:auto;
            margin:0;
        }
        .mini-actions{
            display:flex;
            justify-content:center;
            gap:8px;
            margin-top:6px;
            flex-wrap:wrap;
        }
        .mini-btn{
            border:1px solid #dbeafe;
            background:#fff;
            color:#0b3b9a;
            border-radius:999px;
            padding:5px 10px;
            font-weight:900;
            cursor:pointer;
            font-size:12px;
        }
        .form-group textarea{min-height:66px;max-height:105px;resize:vertical;}
        .register-card{height:auto;max-width:520px;margin:0 auto;width:100%;}
        .history-card{margin-top:22px;max-width:1000px;margin-left:auto;margin-right:auto;}
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
            text-align:center;
            font-size:14px;
        }
        .table td{
            padding:10px 10px;
            border-bottom:1px solid #eef1f6;
            background:#fff;
            vertical-align:middle;
            text-align:center;
        }
        .table td:nth-child(3){
            max-width:360px;
            white-space:normal;
            word-break:break-word;
            text-align:center;
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
            </div>

            <div class="grid-top">
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
                            <div class="form-group full">
                                <label>Vacunas</label>
                                <div class="vaccine-box" id="vaccine_box">
                                    <?php $selectedVaccines = $_POST['vaccine_names'] ?? []; ?>
                                    <?php foreach ($availableVaccines as $vac): ?>
                                        <label class="vaccine-option">
                                            <input
                                                type="checkbox"
                                                name="vaccine_names[]"
                                                value="<?= h($vac) ?>"
                                                <?= in_array($vac, (array)$selectedVaccines, true) ? 'checked' : '' ?>
                                            >
                                            <span><?= h($vac) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mini-actions">
                                    <button type="button" class="mini-btn" onclick="marcarVacunas(true)">Seleccionar todas</button>
                                    <button type="button" class="mini-btn" onclick="marcarVacunas(false)">Limpiar</button>
                                </div>
                            </div>

                            <div class="form-group full">
                                <label for="application_date">Fecha de aplicación</label>
                                <input
                                    type="text"
                                    id="application_date"
                                    name="application_date"
                                    placeholder="DD/MM/AAAA"
                                    maxlength="10"
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
                                        <td><?= h(formatDateDMY($item['application_date'] ?? '')) ?></td>
                                        <td><?= h($item['comments'] ?? '') ?></td>
                                        <td><?= h(formatDateTimeDMY($item['created_at'] ?? '')) ?></td>
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

<script>
(function(){
    const input = document.getElementById('application_date');
    if (!input) return;

    input.addEventListener('input', function(){
        let v = this.value.replace(/\D/g, '').slice(0, 8);
        if (v.length >= 5) {
            this.value = v.slice(0,2) + '/' + v.slice(2,4) + '/' + v.slice(4);
        } else if (v.length >= 3) {
            this.value = v.slice(0,2) + '/' + v.slice(2);
        } else {
            this.value = v;
        }
    });
})();

function marcarVacunas(valor){
    document.querySelectorAll('input[name="vaccine_names[]"]').forEach(function(chk){
        chk.checked = valor;
    });
}
</script>

</body>
</html>