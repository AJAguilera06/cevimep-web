<?php
declare(strict_types=1);

/* ===============================
   Sesión (sin duplicados)
   =============================== */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* ===============================
   Protección de acceso
   =============================== */
if (!isset($_SESSION["user"])) {
    header("Location: /login.php");
    exit;
}

$user = $_SESSION["user"];
$rol = $user["role"] ?? "";
$sucursal_id = (int)($user["branch_id"] ?? 0);
$nombre = $user["full_name"] ?? "Usuario";
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Panel interno</title>

  <!-- CSS ABSOLUTO (clave para Railway + /private) -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=30">
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
      <a class="active" href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👤</span> Pacientes</a>
      <a href="/private/citas/index.php"><span class="ico">📅</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💳</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadisticas/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <!-- CONTENIDO -->
  <main class="content">

    <section class="hero">
      <h1>Panel interno</h1>
      <p>
        Hola, <?= htmlspecialchars($nombre) ?>
        • Rol: <?= htmlspecialchars($rol) ?>
        • Sucursal ID: <?= $sucursal_id ?>
      </p>
    </section>

    <section class="grid-top">

      <div class="card">
        <h3>Estado del sistema</h3>
        <p class="muted">Sistema operativo correctamente.</p>
      </div>

      <div class="card">
        <h3>Sucursal</h3>
        <p class="muted">
          <?= $sucursal_id > 0 ? "ID: {$sucursal_id}" : "No asignada" ?>
        </p>
      </div>

      <div class="card">
        <h3>Usuario</h3>
        <p class="muted"><?= htmlspecialchars($nombre) ?></p>
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
