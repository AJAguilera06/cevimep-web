<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    header("Location: /login.php");
    exit;
}

$user = $_SESSION['user'];

/**
 * ✅ IMPORTANTE:
 * En tu proyecto, muchas veces $pdo lo crea /public/index.php (front controller).
 * Aquí NO vamos a exigir una ruta fija que puede no existir en Railway.
 */
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $candidates = [
        __DIR__ . '/../../config/database.php',
        __DIR__ . '/../config/database.php',
        __DIR__ . '/../../private/config/database.php',
        __DIR__ . '/../_bootstrap.php',
        __DIR__ . '/../../_bootstrap.php',
        __DIR__ . '/../bootstrap.php',
        __DIR__ . '/../../bootstrap.php',
        __DIR__ . '/../../private/bootstrap.php',
        __DIR__ . '/../../public/bootstrap.php',
    ];

    foreach ($candidates as $path) {
        if (file_exists($path)) {
            require_once $path;
            break;
        }
    }
}

// Si aún no existe $pdo, no reventamos: mostramos error entendible
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    ?>
    <!doctype html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <title>Error | CEVIMEP</title>
        <link rel="stylesheet" href="/assets/css/styles.css?v=50">
    </head>
    <body>
    <header class="navbar">
        <div class="inner">
            <div class="brand"><span class="dot"></span><span>CEVIMEP</span></div>
            <div class="nav-right"><a href="/logout.php" class="btn-pill">Salir</a></div>
        </div>
    </header>

    <div class="layout">
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

        <main class="content">
            <div class="welcome-center">
                <h1>Error de conexión</h1>
                <p>No se encontró la conexión <strong>$pdo</strong> en este módulo.</p>
                <p>Verifica que <strong>/public/index.php</strong> esté cargando la conexión antes de requerir <strong>/private/caja/index.php</strong>.</p>
            </div>
        </main>
    </div>

    <footer class="footer">© <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.</footer>
    </body>
    </html>
    <?php
    exit;
}

require_once __DIR__ . '/caja_lib.php';

date_default_timezone_set("America/Santo_Domingo");

$nombreSucursal = $user['full_name'] ?? 'CEVIMEP';
$rol           = $user['role'] ?? '';
$sucursalId    = (int)($user['branch_id'] ?? 0);
$userId        = (int)($user['id'] ?? 0);

$hoy = date("Y-m-d");

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
} catch (Throwable $e) {
    // no romper
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

        <!-- ✅ MISMO ORDEN DEL DASHBOARD -->
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

    <main class="content">
        <div class="welcome-center">
            <h1>Caja <strong><?= h($nombreSucursal) ?></strong></h1>
            <p>
                Rol: <?= h($rol) ?>
                <?php if ($sucursalId): ?> • Sucursal ID: <?= (int)$sucursalId ?><?php endif; ?>
                • Fecha: <?= h($hoy) ?>
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
