<?php
session_start();

if (!isset($_SESSION['user'])) {
    header('Location: ../public/login.php');
    exit;
}

$user = $_SESSION['user'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>CEVIMEP | Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- ✅ RUTA CORRECTA -->
    <link rel="stylesheet" href="../assets/css/styles.css?v=1">
</head>
<body>

<header class="topbar">
    <div class="inner">
        <div class="brand">
            <span class="dot"></span>
            <span class="brand-name">CEVIMEP</span>
        </div>
        <div class="top-actions">
            <span class="pill pill-soft">
                <?php echo htmlspecialchars($user['full_name']); ?>
            </span>
            <a class="pill" href="logout.php">Cerrar sesión</a>
        </div>
    </div>
</header>

<main>
    <div class="page-wrap">
        <div class="page-card">
            <h2 class="page-title">Panel interno</h2>
            <p class="page-sub">
                Hola, <strong><?php echo htmlspecialchars($user['full_name']); ?></strong><br>
                Rol: <strong><?php echo htmlspecialchars($user['role']); ?></strong>
            </p>

            <div class="actions-row">
                <a class="btn-primary" href="#">Dashboard</a>
                <a class="btn-outline" href="logout.php">Cerrar sesión</a>
            </div>
        </div>
    </div>
</main>

<footer class="footer">
    © <?php echo date('Y'); ?> CEVIMEP. Todos los derechos reservados.
</footer>

</body>
</html>
