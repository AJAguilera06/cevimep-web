<?php
// private/patients/esquema.php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

if (!isset($_SESSION['user'])) {
    header("Location: /login.php");
    exit;
}

$user = $_SESSION['user'];
$nombreSucursal = $user['full_name'] ?? 'CEVIMEP';
$rol = $user['role'] ?? '';
$sucursalId = $user['branch_id'] ?? '';

/**
 * Carga de conexión DB (mysqli $conn o PDO $pdo)
 * En Railway te estaba fallando porque la ruta correcta suele ser ../../config/database.php
 */
$conn = null; $pdo = null;
$possible = [
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../config/db.php',
];

$dbLoaded = false;
foreach ($possible as $p) {
    if (file_exists($p)) { require_once $p; $dbLoaded = true; break; }
}

// Detecta variables comunes
if (isset($GLOBALS['conn']) && $GLOBALS['conn']) $conn = $GLOBALS['conn'];
if (isset($GLOBALS['pdo']) && $GLOBALS['pdo']) $pdo = $GLOBALS['pdo'];
if (isset($conn) && $conn) $conn = $conn; // no-op for clarity
if (isset($pdo) && $pdo) $pdo = $pdo;

$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

$errors = [];
$success = "";

/** Helpers */
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Crea tabla si no existe (si el usuario DB tiene permisos) */
function ensure_table($conn, $pdo, &$errors) {
    $sql = "CREATE TABLE IF NOT EXISTS patient_vaccines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        vaccine_name VARCHAR(255) NOT NULL,
        vaccine_date DATE NOT NULL,
        comment TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_patient (patient_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    try {
        if ($pdo) {
            $pdo->exec($sql);
        } elseif ($conn) {
            $conn->query($sql);
        } else {
            $errors[] = "No se detectó conexión a base de datos (config/database.php).";
        }
    } catch (Throwable $t) {
        // No lo hagas fatal, solo avisa.
        $errors[] = "No se pudo verificar/crear la tabla patient_vaccines. Ejecuta el SQL manualmente en Railway si hace falta.";
    }
}

ensure_table($conn, $pdo, $errors);

/** Insert */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $vaccine_name = trim($_POST['vaccine_name'] ?? '');
    $vaccine_date = trim($_POST['vaccine_date'] ?? '');
    $comment      = trim($_POST['comment'] ?? '');

    if ($patient_id <= 0) $errors[] = "Falta patient_id en la URL (ej: esquema.php?patient_id=123).";
    if ($vaccine_name === '') $errors[] = "La vacuna es obligatoria.";
    if ($vaccine_date === '') $errors[] = "La fecha de vacuna es obligatoria.";

    if (!$errors) {
        try {
            if ($pdo) {
                $st = $pdo->prepare("INSERT INTO patient_vaccines (patient_id, vaccine_name, vaccine_date, comment) VALUES (?,?,?,?)");
                $st->execute([$patient_id, $vaccine_name, $vaccine_date, ($comment !== '' ? $comment : null)]);
            } elseif ($conn) {
                $st = $conn->prepare("INSERT INTO patient_vaccines (patient_id, vaccine_name, vaccine_date, comment) VALUES (?,?,?,?)");
                $null = null;
                $cmt = ($comment !== '' ? $comment : $null);
                $st->bind_param("isss", $patient_id, $vaccine_name, $vaccine_date, $cmt);
                $st->execute();
            } else {
                $errors[] = "No se detectó conexión a base de datos.";
            }

            if (!$errors) {
                $success = "Vacuna registrada correctamente.";
            }
        } catch (Throwable $t) {
            $errors[] = "Error al guardar: " . $t->getMessage();
        }
    }
}

/** Fetch list */
$vaccines = [];
try {
    if ($patient_id > 0) {
        if ($pdo) {
            $st = $pdo->prepare("SELECT id, vaccine_name, vaccine_date, comment, created_at
                                 FROM patient_vaccines WHERE patient_id = ?
                                 ORDER BY vaccine_date DESC, id DESC");
            $st->execute([$patient_id]);
            $vaccines = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } elseif ($conn) {
            $st = $conn->prepare("SELECT id, vaccine_name, vaccine_date, comment, created_at
                                  FROM patient_vaccines WHERE patient_id = ?
                                  ORDER BY vaccine_date DESC, id DESC");
            $st->bind_param("i", $patient_id);
            $st->execute();
            $res = $st->get_result();
            if ($res) $vaccines = $res->fetch_all(MYSQLI_ASSOC) ?: [];
        }
    }
} catch (Throwable $t) {
    $errors[] = "No se pudo cargar el listado de vacunas. Detalle: " . $t->getMessage();
}

$showForm = isset($_GET['new']) && $_GET['new'] === '1';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Esquema de Vacuna | CEVIMEP</title>
    <link rel="stylesheet" href="/assets/css/styles.css?v=50">
</head>
<body>

<!-- TOPBAR (igual a dashboard.php) -->
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

    <!-- SIDEBAR (igual a dashboard.php) -->
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

        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <div>
                <h1 style="margin:0;">Esquema de Vacuna</h1>
                <p style="margin:6px 0 0; opacity:.85;">
                    Paciente ID: <strong><?= (int)$patient_id ?></strong>
                </p>
            </div>

            <div style="display:flex; gap:10px; align-items:center;">
                <a class="btn-pill" href="/private/patients/index.php" style="text-decoration:none;">Volver</a>
                <a class="btn-pill" href="?patient_id=<?= (int)$patient_id ?>&new=1" style="text-decoration:none;">Registrar nueva vacuna</a>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="card" style="margin-top:16px; border:1px solid rgba(46, 204, 113,.35);">
                <div class="card-body">
                    ✅ <?= e($success) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="card" style="margin-top:16px; border:1px solid rgba(255, 99, 99,.35);">
                <div class="card-body">
                    <strong>Revisa lo siguiente:</strong>
                    <ul style="margin:10px 0 0 18px;">
                        <?php foreach ($errors as $er): ?>
                            <li><?= e($er) ?></li>
                        <?php endforeach; ?>
                    </ul>

                    <div style="margin-top:12px; opacity:.9;">
                        <div style="font-weight:600; margin-bottom:6px;">SQL para crear la tabla (si Railway no te deja crearla desde PHP):</div>
                        <pre style="white-space:pre-wrap; background:rgba(255,255,255,.04); padding:12px; border-radius:10px; overflow:auto;">CREATE TABLE patient_vaccines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  vaccine_name VARCHAR(255) NOT NULL,
  vaccine_date DATE NOT NULL,
  comment TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_patient (patient_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</pre>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($showForm): ?>
            <div class="card" style="margin-top:16px;">
                <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
                    <strong>Registrar vacuna</strong>
                    <a href="?patient_id=<?= (int)$patient_id ?>" class="btn-pill" style="text-decoration:none;">Cerrar</a>
                </div>

                <div class="card-body">
                    <form method="POST" action="?patient_id=<?= (int)$patient_id ?>">
                        <input type="hidden" name="action" value="create">

                        <div class="grid" style="display:grid; grid-template-columns: 1fr 220px; gap:12px;">
                            <div>
                                <label style="display:block; margin-bottom:6px;">Vacuna</label>
                                <input name="vaccine_name" type="text" required
                                       value="<?= e($_POST['vaccine_name'] ?? '') ?>"
                                       style="width:100%; padding:10px; border-radius:12px; border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.04); color:#fff;">
                            </div>

                            <div>
                                <label style="display:block; margin-bottom:6px;">Fecha de vacuna</label>
                                <input name="vaccine_date" type="date" required
                                       value="<?= e($_POST['vaccine_date'] ?? '') ?>"
                                       style="width:100%; padding:10px; border-radius:12px; border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.04); color:#fff;">
                            </div>
                        </div>

                        <div style="margin-top:12px;">
                            <label style="display:block; margin-bottom:6px;">Comentario</label>
                            <textarea name="comment" rows="3"
                                      style="width:100%; padding:10px; border-radius:12px; border:1px solid rgba(255,255,255,.12); background:rgba(255,255,255,.04); color:#fff; resize:vertical;"><?= e($_POST['comment'] ?? '') ?></textarea>
                        </div>

                        <div style="margin-top:14px; display:flex; gap:10px;">
                            <button type="submit" class="btn-pill">Guardar</button>
                            <a href="?patient_id=<?= (int)$patient_id ?>" class="btn-pill" style="text-decoration:none;">Cancelar</a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-top:16px;">
            <div class="card-header" style="display:flex; align-items:center; justify-content:space-between;">
                <strong>Vacunas registradas</strong>
                <span style="opacity:.8; font-size:.95em;">Mostrando registros guardados.</span>
            </div>

            <div class="card-body">
                <?php if (!$patient_id): ?>
                    <div style="opacity:.9;">Abre esta pantalla con un paciente: <code>esquema.php?patient_id=123</code></div>
                <?php elseif (!$vaccines): ?>
                    <div style="opacity:.9;">No hay vacunas registradas.</div>
                <?php else: ?>
                    <div style="overflow:auto;">
                        <table style="width:100%; border-collapse:collapse; min-width:720px;">
                            <thead>
                                <tr style="text-align:left; opacity:.9;">
                                    <th style="padding:10px; border-bottom:1px solid rgba(255,255,255,.10);">Vacuna</th>
                                    <th style="padding:10px; border-bottom:1px solid rgba(255,255,255,.10); width:160px;">Fecha</th>
                                    <th style="padding:10px; border-bottom:1px solid rgba(255,255,255,.10);">Comentario</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vaccines as $v): ?>
                                    <tr>
                                        <td style="padding:10px; border-bottom:1px solid rgba(255,255,255,.06);"><?= e($v['vaccine_name']) ?></td>
                                        <td style="padding:10px; border-bottom:1px solid rgba(255,255,255,.06);"><?= e($v['vaccine_date']) ?></td>
                                        <td style="padding:10px; border-bottom:1px solid rgba(255,255,255,.06);"><?= e($v['comment'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<footer class="footer">
    © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>
