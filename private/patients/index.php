<?php
declare(strict_types=1);

/**
 * Pacientes – CEVIMEP
 * Usa bootstrap único para sesión + DB
 */
require_once __DIR__ . "/../_bootstrap.php";

/* ===============================
   Variables disponibles
   =============================== */
// $pdo  -> conexión PDO
// $user -> usuario en sesión
// $user['branch_id'], $user['role'], etc.

$branch_id = (int)($user['branch_id'] ?? 0);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pacientes | CEVIMEP</title>

  <!-- CSS absoluto -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=50">
</head>

<body>

<!-- NAVBAR -->
<div class="navbar">
  <div class="inner">
    <div class="brand">
      <span class="dot"></span>
      <strong>CEVIMEP</strong>
    </div>

    <div class="nav-right">
      <a class="btn-pill" href="/logout.php">Salir</a>
    </div>
  </div>
</div>

<!-- LAYOUT -->
<div class="layout">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <h3 class="menu-title">Menú</h3>

    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a class="active" href="/private/patients/index.php"><span class="ico">👤</span> Pacientes</a>
      <a href="/private/citas/index.php"><span class="ico">📅</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💳</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadisticas/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <!-- CONTENT -->
  <main class="content">

    <section class="hero">
      <h1>Pacientes</h1>
      <p>Gestión de pacientes de la sucursal</p>
    </section>

    <section class="card" style="margin-top:16px;">
      <h3>Listado de pacientes</h3>
      <p class="muted">Sucursal ID: <?= $branch_id ?></p>

      <!-- Aquí luego puedes poner la tabla real -->
      <div style="margin-top:12px;">
        <em>Próximo paso: cargar pacientes desde la base de datos.</em>
      </div>
    </section>

  </main>
</div>

<!-- FOOTER -->
<div class="footer">
  <div class="inner">
    © <?= (int)date("Y") ?> CEVIMEP. Todos los derechos reservados.
  </div>
</div>

</body>
</html>
