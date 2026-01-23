<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/_guard.php';

$userName   = $_SESSION['user_name'] ?? 'CEVIMEP';
$branchName = $_SESSION['branch_name'] ?? 'Sucursal';
$branchId   = $_SESSION['branch_id'] ?? '';
$role       = $_SESSION['role'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard | CEVIMEP</title>
    <link rel="stylesheet" href="/assets/css/styles.css">
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

<!-- LAYOUT -->
<div class="layout">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="box">
            <h3>Menú</h3>
            <ul class="menu">
                <li class="active"><a href="/private/dashboard.php">🏠 Panel</a></li>
                <li><a href="/private/patients/index.php">👤 Pacientes</a></li>
                <li><a href="/private/appointments/index.php">📅 Citas</a></li>
                <li><a href="/private/facturacion/index.php">🧾 Facturación</a></li>
                <li><a href="/private/caja/index.php">💳 Caja</a></li>
                <li><a href="/private/inventario/index.php">📦 Inventario</a></li>
                <li><a href="/private/estadistica/index.php">📊 Estadísticas</a></li>
            </ul>
        </div>
    </aside>

    <!-- CONTENT -->
    <main class="content">

        <h1 style="text-align:center;">
            Bienvenido <strong><?php echo htmlspecialchars($branchName); ?></strong>
        </h1>

        <p class="muted" style="text-align:center;">
            Rol: <?php echo htmlspecialchars($role); ?>
            <?php if ($branchId): ?> • Sucursal ID: <?php echo $branchId; ?><?php endif; ?>
        </p>

        <div class="card">
            <h3>Estado del sistema</h3>
            <p>Sistema operativo correctamente.</p>
        </div>

        <div class="card">
            <h3>Sucursal</h3>
            <p><?php echo htmlspecialchars($branchName); ?><?php if ($branchId): ?> (ID: <?php echo $branchId; ?>)<?php endif; ?></p>
        </div>

        <div class="card">
            <h3>Usuario</h3>
            <p><?php echo htmlspecialchars($userName); ?></p>
        </div>

    </main>
</div>

<!-- FOOTER -->
<footer class="footer">
    <div class="inner">
        © 2026 CEVIMEP — Todos los derechos reservados.
    </div>
</footer>

</body>
</html>
