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

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <style>
    :root{
        --navy-deep:#071B32;
        --teal:#14B8A6;
        --teal-dark:#0D9488;
    }
    *{ box-sizing:border-box; }
    body{
        margin:0;
        font-family:'Inter',sans-serif;
        background:var(--navy-deep);
        color:#fff;
        min-height:100vh;
        display:flex;
        flex-direction:column;
    }
    .navbar{ background:rgba(255,255,255,.04); border-bottom:1px solid rgba(255,255,255,.08); }
    .navbar .inner{ display:grid; grid-template-columns:1fr auto 1fr; align-items:center; padding:16px 28px; }
    .navbar .brand{ grid-column:2; justify-self:center; }
    .navbar .nav-right{ grid-column:3; justify-self:end; }
    .brand{ display:flex; align-items:center; gap:10px; font-family:'Manrope'; font-weight:800; font-size:15px; letter-spacing:.04em; }
    .brand .dot{ width:9px;height:9px;border-radius:50%;background:var(--teal); box-shadow:0 0 0 4px rgba(20,184,166,.18); }
    .btn-pill{ background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.16); color:#fff; padding:9px 16px; border-radius:999px; text-decoration:none; font-weight:700; font-size:13px; }
    .btn-pill:hover{ background:rgba(255,255,255,.14); }

    .layout{ display:flex; width:100%; position:relative; flex:1; }
    .auth-bg{ position:fixed; inset:0; width:100%; height:100%; z-index:0; opacity:.55; pointer-events:none; }

    .sidebar{ position:relative; z-index:1; width:230px; flex-shrink:0; padding:24px 16px; }
    .menu-title{ font-weight:700; font-size:12px; letter-spacing:.08em; text-transform:uppercase; color:#7D95AC; margin:0 0 14px 6px; }
    .menu{ display:flex; flex-direction:column; gap:2px; }
    .menu a{ display:block; padding:10px 12px; border-radius:10px; text-decoration:none; font-size:14px; font-weight:600; color:#B9CBDD; }
    .menu a:hover{ background:rgba(255,255,255,.06); }
    .menu a.active{ background:rgba(20,184,166,.18); color:var(--teal); }

    .content{ position:relative; z-index:1; flex:1; width:100%; padding:40px 40px 60px; }
    .footer{ position:relative; z-index:1; text-align:center; padding:20px; font-size:12px; color:#5C7896; }

    .inv-wrap{ max-width: 1100px; margin: 0 auto; }

    .inv-title h1{
      margin:0;
      font-family:'Manrope';
      font-size: 38px;
      line-height:1.1;
      font-weight: 900;
    }
    .inv-title p{
      margin:6px 0 0;
      color:#9FB6CC;
      font-weight: 800;
    }

    .inv-grid{
      margin-top: 26px;
      display:grid;
      grid-template-columns: repeat(auto-fit, minmax(260px,1fr));
      gap:18px;
    }

    .inv-card{
      background:rgba(255,255,255,.07);
      backdrop-filter:blur(14px);
      -webkit-backdrop-filter:blur(14px);
      border:1px solid rgba(255,255,255,.12);
      border-radius:18px;
      padding:18px;
      display:flex;
      align-items:center;
      gap:16px;
      text-decoration:none;
      color:#fff;
      transition: transform .15s ease, box-shadow .18s ease, border-color .15s ease;
    }
    .inv-card:hover{
      transform: translateY(-3px);
      box-shadow:0 18px 38px rgba(0,0,0,.25);
      border-color: rgba(20,184,166,.4);
    }

    .inv-ico{
      width:52px;
      height:52px;
      border-radius:14px;
      background:rgba(20,184,166,.14);
      border:1px solid rgba(20,184,166,.3);
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
      color:#fff;
    }
    .inv-info p{
      margin:6px 0 0;
      font-size:13px;
      color:#9FB6CC;
      font-weight:700;
    }

    @media(max-width:980px){
      .layout{ flex-direction:column; }
      .sidebar{ width:100%; }
      .content{ padding:24px 18px 40px; }
      .inv-title h1{ font-size:31px; }
    }
  </style>
</head>

<body>

<svg class="auth-bg" viewBox="0 0 1400 800" preserveAspectRatio="xMidYMid slice" xmlns="http://www.w3.org/2000/svg">
    <circle cx="130" cy="80" r="240" fill="#0D3A5C" opacity=".5"/>
    <circle cx="1280" cy="650" r="280" fill="#0F4A63" opacity=".45"/>
    <g fill="none" stroke="#1D5C7A" stroke-width="1" opacity=".7">
        <line x1="60" y1="60" x2="180" y2="130"/><line x1="180" y1="130" x2="140" y2="250"/>
        <line x1="1220" y1="560" x2="1300" y2="620"/><line x1="1300" y1="620" x2="1250" y2="700"/>
    </g>
    <g fill="#14B8A6" opacity=".85">
        <circle cx="60" cy="60" r="3"/><circle cx="180" cy="130" r="4"/><circle cx="140" cy="250" r="3"/>
        <circle cx="1220" cy="560" r="3"/><circle cx="1300" cy="620" r="4"/><circle cx="1250" cy="700" r="3"/>
    </g>
</svg>

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
      <a href="#" onclick="return false;" style="opacity:.5;cursor:not-allowed;">📅 Citas (Próximamente)</a>
      <a href="/private/facturacion/index.php">🧾 Facturación</a>
      <a href="/private/caja/index.php">💳 Caja</a>
      <a class="active" href="/private/inventario/index.php">📦 Inventario</a>
      <a href="/private/estadistica/index.php">📊 Estadísticas</a>
    </nav>
  </aside>

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

<footer class="footer">© <?= $year ?> CEVIMEP — Todos los derechos reservados.</footer>

</body>
</html>