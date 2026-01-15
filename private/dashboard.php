<?php
declare(strict_types=1);

/**
 * CEVIMEP - Dashboard
 * - Mantiene layout/estilos consistentes con los demás módulos
 * - Botón: "Salir"
 * - Footer centrado
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

$year = (int)date('Y');

// Ajusta estos keys si tu sesión usa otros nombres
$fullName = $_SESSION['user']['full_name'] ?? ($_SESSION['user']['name'] ?? 'Usuario');
$role     = $_SESSION['user']['role'] ?? 'user';
$branch   = $_SESSION['user']['branch_name'] ?? ($_SESSION['user']['branch'] ?? '');

// Texto bonito para el saludo
$branchLabel = $branch ? $branch : 'Sucursal';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Panel</title>

  <!-- IMPORTANTE: mismo CSS y misma versión que los demás módulos -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=6" />
</head>

<body>

<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right">
      <a class="btn-pill" href="/logout.php">Salir</a>
    </div>
  </div>
</header>

<div class="layout">

  <aside class="sidebar">
    <div class="menu-title">Menú</div>

    <nav class="menu">
      <a class="active" href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👥</span> Pacientes</a>
      <a href="/private/citas/index.php"><span class="ico">🗓️</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadistica/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">

    <section class="hero">
      <h1>Panel interno</h1>
      <p>Hola, <?= htmlspecialchars("CEVIMEP {$branchLabel}") ?> · Rol: <?= htmlspecialchars((string)$role) ?></p>
    </section>

    <section class="grid-top">
      <div class="card">
        <h3>Estado del sistema</h3>
        <p class="muted">Sistema operativo correctamente</p>
      </div>

      <div class="card">
        <h3>Sucursal</h3>
        <p class="muted"><?= htmlspecialchars($branch ?: '—') ?></p>
      </div>

      <div class="card">
        <h3>Usuario</h3>
        <p class="muted"><?= htmlspecialchars($fullName) ?></p>
      </div>
    </section>

  </main>
</div>

<footer class="footer">
  <div class="footer-inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

</body>
</html>
