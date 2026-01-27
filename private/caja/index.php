<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    header("Location: /login.php");
    exit;
}

/**
 * ✅ TU CONEXIÓN REAL (XAMPP + Railway)
 * Ruta: private/config/db.php
 * Desde private/caja => ../config/db.php
 */
require_once __DIR__ . '/../config/db.php';

/**
 * ✅ Librería de caja (funciones)
 */
require_once __DIR__ . '/caja_lib.php';

$user = $_SESSION['user'];

date_default_timezone_set("America/Santo_Domingo");

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$hoy = date("Y-m-d");
$year = date("Y");

$rol        = (string)($user['role'] ?? '');
$sucursalId = (int)($user['branch_id'] ?? 0);
$userId     = (int)($user['id'] ?? 0);

// Nombre sucursal (si tu sesión guarda "branch_name" úsalo; si no, fallback)
$branchName = (string)($user['branch_name'] ?? ($user['full_name'] ?? 'CEVIMEP'));

// Si tienes tabla branches, intento sacar nombre real
try {
    if ($sucursalId > 0) {
        $stB = $pdo->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
        $stB->execute([$sucursalId]);
        $bn = $stB->fetchColumn();
        if ($bn) $branchName = (string)$bn;
    }
} catch (Throwable $e) { /* no romper */ }

// ✅ Abrir/obtener sesión de caja (según tu caja_lib.php)
$sessionId = 0;
if ($sucursalId > 0 && $userId > 0) {
    $sessionId = caja_get_or_open_current_session($pdo, $sucursalId, $userId);
}

$estadoCaja = ($sessionId > 0) ? "Abierta" : "Fuera de horario / sin sesión";
$turno      = "N/D";
$apertura   = "N/D";

try {
    if ($sessionId > 0) {
        $st = $pdo->prepare("SELECT caja_num, opened_at FROM cash_sessions WHERE id=? LIMIT 1");
        $st->execute([$sessionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $turno = "Caja " . ($row['caja_num'] ?? 'N/D');
            $apertura = $row['opened_at'] ?? 'N/D';
        }
    }
} catch (Throwable $e) { /* no romper */ }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Caja | CEVIMEP</title>

    <!-- ✅ MISMO CSS EXACTO DEL DASHBOARD -->
    <link rel="stylesheet" href="/assets/css/styles.css?v=50">
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

    <!-- ✅ SIDEBAR (misma estructura del dashboard) -->
    <aside class="sidebar">
        <div class="menu-title">Menú</div>

        <nav class="menu">
            <a href="/private/dashboard.php">🏠 Panel</a>
            <a href="/private/patients/index.php">👤 Pacientes</a>
            <a href="/private/citas/index.php">📅 Citas</a>
            <a href="/private/facturacion/index.php">🧾 Facturación</a>
            <a class="active" href="/private/caja/index.php">💳 Caja</a>
            <a href="/private/inventario/index.php">📦 Inventario</a>
            <a href="/private/estadistica/index.php">📊 Estadísticas</a>
        </nav>

        <div style="margin-top:auto; padding-top:14px;">
            <div class="menu-title" style="margin-bottom:8px;">Sucursal</div>
            <div style="color:#fff; font-weight:700; opacity:.95;">
                <?= h($branchName) ?>
            </div>
        </div>
    </aside>

    <main class="content">
        <div class="welcome-center">
            <h1>Caja <strong><?= h($branchName) ?></strong></h1>
            <p>
                Fecha: <?= h($hoy) ?>
                <?php if ($rol): ?> • Rol: <?= h($rol) ?><?php endif; ?>
            </p>

            <p style="margin-top:14px; text-align:left; display:inline-block;">
                <strong>Estado de Caja:</strong> <?= h($estadoCaja) ?><br>
                <strong>Sesión activa:</strong> <?= $sessionId > 0 ? '#'.(int)$sessionId : 'N/D' ?><br>
                <strong>Turno:</strong> <?= h($turno) ?><br>
                <strong>Apertura:</strong> <?= h($apertura) ?><br>
            </p>

            <p style="margin-top:18px;">
                <a href="/private/facturacion/index.php" class="btn-pill">Ir a Facturación</a>
                <a href="/private/dashboard.php" class="btn-pill" style="margin-left:10px;">Volver al Panel</a>
            </p>

            <p style="margin-top:14px; font-size:13px; opacity:.85;">
                * Las cajas se abren y cierran automáticamente por horario (sin botones).
            </p>
        </div>
    </main>

</div>

<footer class="footer">
    © <?= h($year) ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>
