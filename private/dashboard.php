<?php
declare(strict_types=1);

/**
 * CEVIMEP - Dashboard (Railway OK)
 */
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

if (empty($_SESSION['user'])) {
  header('Location: /login.php');
  exit;
}

$userName = $_SESSION['user']['full_name'] ?? 'Usuario';
$role = $_SESSION['user']['role'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel interno | CEVIMEP</title>

    <!-- CSS GLOBAL (ruta correcta para Railway) -->
    <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body>

<!-- ===== NAVBAR ===== -->
<div class="navbar">
  <div class="inner">
    <div></div>

    <div class="brand">
      <span class="dot"></span> CEVIMEP
    </div>

    <div class="nav-right">
      <a class="btn" href="/logout.php">Cerrar sesión</a>
    </div>
  </div>
</div>

<div class="layout">

  <!-- ===== SIDEBAR ===== -->
  <aside class="sidebar">
    <h3 class="menu-title">Menú</h3>

    <nav class="menu">
      <a class="active" href="/private/dashboard.php">
        <span class="ico">🏠</span> Panel
      </a>

      <a href="/private/patients/index.php">
        <span class="ico">👥</span> Pacientes
      </a>

      <a href="/private/citas/index.php">
        <span class="ico">📅</span> Citas
      </a>

      <a href="/private/facturacion/index.php">
        <span class="ico">🧾</span> Facturación
      </a>

      <a href="/private/caja/index.php">
        <span class="ico">💵</span> Caja
      </a>

      <a href="/private/inventario/index.php">
        <span class="ico">📦</span> Inventario
      </a>

      <a href="/private/estadistica/index.php">
        <span class="ico">📊</span> Estadísticas
      </a>
    </nav>
  </aside>

  <!-- ===== CONTENT ===== -->
  <main class="content">

    <div class="hero">
      <h1>Panel interno</h1>
      <p>Hola, <strong><?= htmlspecialchars((string)$userName) ?></strong> · Rol: <strong><?= htmlspecialchars((string)$role) ?></strong></p>
    </div>

    <div class="grid-top">
      <div class="card">
        <h3>Estado del sistema</h3>
        <p class="muted">Sistema operativo correctamente</p>
      </div>

      <div class="card">
        <h3>Sucursal</h3>
        <p class="muted">Moca</p>
      </div>

      <div class="card">
        <h3>Usuario</h3>
        <p class="muted">Administrador</p>
      </div>
    </div>

  </main>

</div>

<!-- ===== FOOTER ===== -->
<footer class="footer">
  <div class="inner">
    © <?= date('Y') ?> CEVIMEP. Todos los derechos reservados.
  </div>
</footer>

</body>
</html>
