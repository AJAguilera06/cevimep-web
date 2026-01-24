<?php
declare(strict_types=1);
require_once __DIR__ . "/../_guard.php";

$year = date("Y");
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>CEVIMEP | Inventario</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- MISMO CSS DEL DASHBOARD -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=80">

  <style>
    .inv-wrap{
      max-width: 1100px;
      margin: 0 auto;
      padding: 20px;
    }

    .inv-title h1{
      margin:0;
      font-size: 34px;
      font-weight: 950;
    }
    .inv-title p{
      margin:6px 0 0;
      opacity:.75;
      font-weight: 700;
    }

    .inv-grid{
      margin-top: 26px;
      display:grid;
      grid-template-columns: repeat(auto-fit, minmax(260px,1fr));
      gap:18px;
    }

    .inv-card{
      background:#fff;
      border-radius:18px;
      padding:18px;
      box-shadow:0 12px 30px rgba(0,0,0,.08);
      display:flex;
      align-items:center;
      gap:16px;
      text-decoration:none;
      color:#0b2a4a;
      transition: transform .15s ease, box-shadow .18s ease;
    }
    .inv-card:hover{
      transform: translateY(-3px);
      box-shadow:0 18px 38px rgba(0,0,0,.12);
    }

    .inv-ico{
      width:52px;
      height:52px;
      border-radius:14px;
      background:#eef6ff;
      display:flex;
      align-items:center;
      justify-content:center;
      font-size:26px;
      flex-shrink:0;
    }

    .inv-info h3{
      margin:0;
      font-size:18px;
      font-weight:900;
    }
    .inv-info p{
      margin:6px 0 0;
      font-size:13px;
      opacity:.75;
      font-weight:700;
    }
  </style>
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

<div class="layout">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <h3 class="menu-title">Menú</h3>
    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👤</span> Pacientes</a>
      <a href="/private/citas/index.php"><span class="ico">📅</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💳</span> Caja</a>
      <a class="active" href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadisticas/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <!-- CONTENIDO -->
  <main class="content">
    <div class="inv-wrap">

      <div class="inv-title">
        <h1>Inventario</h1>
        <p>Elige una opción para administrar el inventario por sucursal.</p>
      </div>

      <div class="inv-grid">

        <a class="inv-card" href="/private/inventario/items.php">
          <div class="inv-ico">📋</div>
          <div class="inv-info">
            <h3>Inventario</h3>
            <p>Ver vacunas/productos por sucursal y editar existencias.</p>
          </div>
        </a>

        <a class="inv-card" href="/private/inventario/entrada.php">
          <div class="inv-ico">⬇️</div>
          <div class="inv-info">
            <h3>Entrada</h3>
            <p>Registrar pedidos recibidos (cantidad y sucursal).</p>
          </div>
        </a>

        <a class="inv-card" href="/private/inventario/salida.php">
          <div class="inv-ico">⬆️</div>
          <div class="inv-info">
            <h3>Salida</h3>
            <p>Registrar salida o transferencia entre sucursales.</p>
          </div>
        </a>

        <a class="inv-card" href="/private/inventario/categorias.php">
          <div class="inv-ico">🏷️</div>
          <div class="inv-info">
            <h3>Categorías</h3>
            <p>Vacunas, productos, insumos y clasificaciones.</p>
          </div>
        </a>

      </div>

    </div>
  </main>
</div>

<div class="footer">
  <div class="inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</div>

</body>
</html>
