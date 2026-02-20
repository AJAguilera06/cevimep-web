<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['user'])) {
    header("Location: /login.php");
    exit;
}

$user = $_SESSION['user'];
$nombreSucursal = $user['full_name'] ?? 'CEVIMEP';
$rol = $user['role'] ?? '';
$sucursalId = $user['branch_id'] ?? '';

/**
 * Intentar cargar conexión a BD si existe en el proyecto
 * - Si ya tienes $conn (mysqli) o $pdo (PDO) definidos en otro include, esto no estorba.
 */
@require_once __DIR__ . '/../config/db.php';
@require_once __DIR__ . '/../config/database.php';
@require_once __DIR__ . '/../db.php';

$patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : (isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : 0);

$errors = [];
$success = null;

/**
 * Crear tabla automáticamente si no existe (evita el error en Railway).
 * Ajusta nombres de campos si ya tienes una tabla creada.
 */
function ensure_table_exists_mysqli(mysqli $conn): void {
    $sql = "
        CREATE TABLE IF NOT EXISTS patient_vaccines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            vaccine_name VARCHAR(255) NOT NULL,
            vaccine_date DATE NOT NULL,
            comment TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    @$conn->query($sql);
}

function ensure_table_exists_pdo(PDO $pdo): void {
    $sql = "
        CREATE TABLE IF NOT EXISTS patient_vaccines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            vaccine_name VARCHAR(255) NOT NULL,
            vaccine_date DATE NOT NULL,
            comment TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    try { $pdo->exec($sql); } catch (Throwable $e) { /* si no hay permisos, ignorar */ }
}

$hasMysqli = isset($conn) && $conn instanceof mysqli;
$hasPdo = isset($pdo) && $pdo instanceof PDO;

if ($hasMysqli) ensure_table_exists_mysqli($conn);
if ($hasPdo) ensure_table_exists_pdo($pdo);

/** Guardar registro */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_vaccine') {
    $vaccine = trim($_POST['vaccine'] ?? '');
    $vaccineDate = trim($_POST['vaccine_date'] ?? '');
    $comment = trim($_POST['comment'] ?? '');

    if ($patientId <= 0) $errors[] = "Falta el paciente (patient_id).";
    if ($vaccine === '') $errors[] = "La vacuna es obligatoria.";
    if ($vaccineDate === '') $errors[] = "La fecha de vacuna es obligatoria.";

    if (!$errors) {
        try {
            if ($hasMysqli) {
                $stmt = $conn->prepare("INSERT INTO patient_vaccines (patient_id, vaccine_name, vaccine_date, comment) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isss", $patientId, $vaccine, $vaccineDate, $comment);
                $stmt->execute();
                $stmt->close();
                $success = "Vacuna registrada correctamente.";
            } elseif ($hasPdo) {
                $stmt = $pdo->prepare("INSERT INTO patient_vaccines (patient_id, vaccine_name, vaccine_date, comment) VALUES (:pid, :v, :d, :c)");
                $stmt->execute([':pid'=>$patientId, ':v'=>$vaccine, ':d'=>$vaccineDate, ':c'=>$comment]);
                $success = "Vacuna registrada correctamente.";
            } else {
                $errors[] = "No se encontró conexión a base de datos (\$conn o \$pdo).";
            }
        } catch (Throwable $e) {
            $errors[] = "No se pudo guardar: " . $e->getMessage();
        }
    }
}

/** Listado */
$vaccines = [];
if ($patientId > 0) {
    try {
        if ($hasMysqli) {
            $stmt = $conn->prepare("SELECT id, vaccine_name, vaccine_date, comment, created_at FROM patient_vaccines WHERE patient_id = ? ORDER BY vaccine_date DESC, id DESC");
            $stmt->bind_param("i", $patientId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $vaccines[] = $row;
            $stmt->close();
        } elseif ($hasPdo) {
            $stmt = $pdo->prepare("SELECT id, vaccine_name, vaccine_date, comment, created_at FROM patient_vaccines WHERE patient_id = :pid ORDER BY vaccine_date DESC, id DESC");
            $stmt->execute([':pid'=>$patientId]);
            $vaccines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $errors[] = "No se pudo cargar el listado de vacunas. Detalle: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Esquema de Vacuna | CEVIMEP</title>
    <link rel="stylesheet" href="/assets/css/styles.css?v=50">
    <style>
        /* Ajustes mínimos, sin romper tu styles.css */
        .page-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
        .card{background: rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.08); border-radius:14px; padding:16px}
        .muted{opacity:.8}
        .alert{border-radius:12px;padding:12px 14px;margin:12px 0}
        .alert.err{border:1px solid rgba(255,77,77,.35); background: rgba(255,77,77,.08)}
        .alert.ok{border:1px solid rgba(80,200,120,.35); background: rgba(80,200,120,.08)}
        .table{width:100%;border-collapse:collapse}
        .table th,.table td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,0.08);text-align:left;vertical-align:top}
        .table th{opacity:.85;font-weight:600}
        .btn-primary{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px;border-radius:999px;border:0;background:#2b74ff;color:#fff;text-decoration:none;cursor:pointer}
        .btn-primary:hover{filter:brightness(1.05)}
        .form-grid{display:grid;grid-template-columns:1fr 220px;gap:12px}
        .field{display:flex;flex-direction:column;gap:6px}
        .field input,.field textarea{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.18);color:#fff;outline:none}
        .field textarea{min-height:90px;resize:vertical}
        .actions{display:flex;gap:10px;justify-content:flex-end;margin-top:10px}
        .btn-ghost{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px;border-radius:999px;border:1px solid rgba(255,255,255,.18);background:transparent;color:#fff;text-decoration:none;cursor:pointer}
        .btn-ghost:hover{background:rgba(255,255,255,.06)}
        .hidden{display:none}
        @media(max-width: 820px){
            .form-grid{grid-template-columns:1fr}
        }
    </style>
</head>
<body>

<!-- TOPBAR -->
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

    <!-- SIDEBAR -->
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

    <!-- CONTENIDO -->
    <main class="content">

        <div class="page-head">
            <div>
                <h1 style="margin:0">Esquema de Vacuna</h1>
                <div class="muted" style="margin-top:6px">
                    <?php if ($patientId > 0): ?>
                        Paciente ID: <strong><?= (int)$patientId ?></strong>
                    <?php else: ?>
                        <strong class="muted">Tip:</strong> abre esta pantalla con <code>?patient_id=ID</code> desde el perfil del paciente.
                    <?php endif; ?>
                </div>
            </div>

            <button class="btn-primary" type="button" id="btnToggleForm">Registrar nueva vacuna</button>
        </div>

        <?php if ($errors): ?>
            <div class="alert err">
                <strong>Revisa lo siguiente:</strong>
                <ul style="margin:8px 0 0 18px">
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert ok">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <div class="card <?= ($patientId > 0 ? '' : 'hidden') ?>" id="formCard">
            <h3 style="margin-top:0">Registrar vacuna</h3>

            <form method="POST">
                <input type="hidden" name="action" value="add_vaccine">
                <input type="hidden" name="patient_id" value="<?= (int)$patientId ?>">

                <div class="form-grid">
                    <div class="field">
                        <label>Vacuna</label>
                        <input type="text" name="vaccine" placeholder="Ej: Influenza, HPV, Neumococo..." required>
                    </div>

                    <div class="field">
                        <label>Fecha de vacuna</label>
                        <input type="date" name="vaccine_date" required>
                    </div>
                </div>

                <div class="field" style="margin-top:12px">
                    <label>Comentario</label>
                    <textarea name="comment" placeholder="Observaciones (opcional)"></textarea>
                </div>

                <div class="actions">
                    <button type="button" class="btn-ghost" id="btnCancel">Cancelar</button>
                    <button type="submit" class="btn-primary">Guardar</button>
                </div>
            </form>
        </div>

        <div class="card" style="margin-top:14px">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
                <h3 style="margin:0">Vacunas registradas</h3>
                <div class="muted">Mostrando registros guardados.</div>
            </div>

            <div style="margin-top:10px;overflow:auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="min-width:180px">Vacuna</th>
                            <th style="min-width:140px">Fecha</th>
                            <th>Comentario</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($patientId <= 0): ?>
                            <tr><td colspan="3" class="muted">Debes abrir esta pantalla con <code>?patient_id=ID</code> para ver/registrar vacunas.</td></tr>
                        <?php elseif (!$vaccines): ?>
                            <tr><td colspan="3" class="muted">No hay vacunas registradas.</td></tr>
                        <?php else: ?>
                            <?php foreach ($vaccines as $v): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($v['vaccine_name'] ?? '') ?></strong></td>
                                    <td><?= htmlspecialchars($v['vaccine_date'] ?? '') ?></td>
                                    <td><?= nl2br(htmlspecialchars($v['comment'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<footer class="footer">
    © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

<script>
(function(){
    const formCard = document.getElementById('formCard');
    const btnToggle = document.getElementById('btnToggleForm');
    const btnCancel = document.getElementById('btnCancel');

    if(btnToggle && formCard){
        btnToggle.addEventListener('click', ()=> {
            formCard.classList.toggle('hidden');
            if(!formCard.classList.contains('hidden')){
                const firstInput = formCard.querySelector('input[name="vaccine"]');
                if(firstInput) firstInput.focus();
            }
        });
    }
    if(btnCancel && formCard){
        btnCancel.addEventListener('click', ()=> {
            formCard.classList.add('hidden');
        });
    }
})();
</script>

</body>
</html>
