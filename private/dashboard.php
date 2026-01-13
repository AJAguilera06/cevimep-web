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
      <span class="dot"></span>
      CEVIMEP
    </div>

    <div class="nav-right">
      <a href="/logout.php">Cerrar sesión</a>
    </div>
  </div>
</div>

<!-- ===== APP ===== -->
<div class="app">

  <!-- SIDEBAR -->
  <aside class="sidebar">
  <div class="title">Menú</div>

  <nav class="menu">
    <a class="active" href="/private/dashboard.php">
      <span class="ico">🏠</span> Panel
    </a>

    <a href="/private/pacientes/index.php">
      <span class="ico">👥</span> Pacientes
    </a>

    <a class="disabled" href="#">
      <span class="ico">📅</span> Citas
    </a>

    <a class="disabled" href="#">
      <span class="ico">🧾</span> Facturación
    </a>

    <a class="disabled" href="#">
      <span class="ico">💵</span> Caja
    </a>

    <a href="/private/inventario/index.php">
      <span class="ico">📦</span> Inventario
    </a>

    <a class="disabled" href="#">
      <span class="ico">⏳</span> Coming Soon
    </a>
  </nav>
</aside>

  <!-- CONTENIDO -->
  <main class="main">

    <div class="hero">
      <h1>Panel interno</h1>
      <p>Hola, <strong>CEVIMEP Moca</strong> · Rol: <strong>branch_admin</strong></p>
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
    © 2026 CEVIMEP. Todos los derechos reservados.
  </div>
</footer>

</body>
</html>
