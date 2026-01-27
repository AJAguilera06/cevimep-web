<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    header("Location: /login.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/caja_lib.php';

$user = $_SESSION['user'];

$nombreSucursal = $user['full_name'] ?? 'CEVIMEP';
$rol = $user['role'] ?? '';
$sucursalId = (int)($user['branch_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);

date_default_timezone_set("America/Santo_Domingo");
$hoy = date("Y-m-d");

// ✅ Abrir/obtener sesión de caja (según horario)
$sessionId = 0;
if ($sucursalId > 0 && $userId > 0) {
    $sessionId = caja_get_or_open_current_session($pdo, $sucursalId, $userId);
}

// Info de sesión actual (si existe tabla cash_sessions)
$estadoCaja = ($sessionId > 0) ? "Abierta" : "Fuera de horario / sin sesión";
$turno = "N/D";
$apertura = "N/D";

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
} catch (Throwable $e) {
    // no romper la página
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
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

    <!-- SIDEBAR (MISMO ORDEN DEL DASHBOARD) -->
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
    </aside>

    <!-- CONTENIDO -->
    <main class="content">

        <div class="welcome-center">
            <h1>Caja <strong><?= h($nombreSucursal) ?></strong></h1>
            <p>
                Fecha: <?= h($hoy) ?>
                <?php if ($sucursalId): ?> • Sucursal ID: <?= (int)$sucursalId ?><?php endif; ?>
                <?php if ($rol): ?> • Rol: <?= h($rol) ?><?php endif; ?>
            </p>

            <p style="margin-top:14px;">
                <strong>Estado de Caja:</strong> <?= h($estadoCaja) ?><br>
                <strong>Sesión activa:</strong> <?= $sessionId > 0 ? '#'.(int)$sessionId : 'N/D' ?><br>
                <strong>Turno:</strong> <?= h($turno) ?><br>
                <strong>Apertura:</strong> <?= h($apertura) ?><br>
            </p>

            <p style="margin-top:18px;">
                <a href="/private/facturacion/index.php" class="btn-pill">Ir a Facturación</a>
                <a href="/private/dashboard.php" class="btn-pill" style="margin-left:10px;">Volver al Panel</a>
            </p>
        </div>

    </main>
</div>

<footer class="footer">
    © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>
