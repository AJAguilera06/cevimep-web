<?php
session_start();
if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }
$year = date("Y");
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Inventario</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <style>
    html,body{height:100%;}
    body{display:flex;flex-direction:column;min-height:100vh;overflow:hidden;}
    .app{flex:1;display:flex;min-height:0;}
    .main{flex:1;overflow:auto;}
    .grid{
      display:grid;
      grid-template-columns: repeat(2, minmax(260px, 420px));
      gap:18px;
      justify-content:center;
      margin-top:26px;
    }
    .box{
      background:#fff;
      border:1px solid #e6eef7;
      border-radius:18px;
      padding:16px 16px;
      text-decoration:none;
      color:inherit;
      box-shadow:0 8px 30px rgba(15,42,80,.08);
      display:flex;
      gap:12px;
      align-items:flex-start;
    }
    .icoBox{
      width:42px;height:42px;border-radius:14px;
      display:flex;align-items:center;justify-content:center;
      background:#eef6ff;border:1px solid rgba(2,21,44,.10);
      font-size:20px;
      flex:0 0 auto;
    }
    .box .title{font-weight:900;color:#0b2a4a;margin:0 0 4px;}
    .box .desc{margin:0;color:#5b6b7b;font-weight:700;font-size:13px;}
  </style>
</head>

<body>

<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right"><a href="../../public/logout.php">Salir</a></div>
  </div>
</header>

<main class="app">

  <!-- SIDEBAR (Inventario ACTIVO SOLO AQUÍ) -->
  <aside class="sidebar">
    <div class="title">Menú</div>

    <nav class="menu">
      <a href="../dashboard.php">
        <span class="ico">🏠</span> Panel
      </a>

      <a href="../patients/index.php">
        <span class="ico">🧑‍🤝‍🧑</span> Pacientes
      </a>

      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;">
        <span class="ico">📅</span> Citas
      </a>

      <a href="../facturacion/index.php">
        <span class="ico">🧾</span> Facturación
      </a>

     <a href="../caja/index.php"><span class="ico">💳</span> Caja</a>

      <a class="active" href="index.php">
        <span class="ico">📦</span> Inventario
      </a>

      <a href="../estadistica/index.php">
        <span class="ico">⏳</span> Estadística
      </a>
    </nav>
  </aside>

  <section class="main">
    <div class="card" style="margin:22px;">
      <h2 style="margin:0 0 6px;">Inventario</h2>
      <p class="muted" style="margin:0;">Elige una opción para administrar por sucursal.</p>
    </div>

    <div class="grid">
      <a class="box" href="items.php">
        <div class="icoBox">📋</div>
        <div>
          <div class="title">Inventario</div>
          <p class="desc">Ver vacunas/productos por sucursal + agregar/editar/eliminar.</p>
        </div>
      </a>

      <a class="box" href="entrada.php">
        <div class="icoBox">📥</div>
        <div>
          <div class="title">Entrada</div>
          <p class="desc">Registrar pedido que llega (sucursal + qué llega + cantidad).</p>
        </div>
      </a>

      <a class="box" href="salida.php">
        <div class="icoBox">📤</div>
        <div>
          <div class="title">Salida</div>
          <p class="desc">Registrar salida/transferencia (origen → destino).</p>
        </div>
      </a>

      <a class="box" href="categorias.php">
        <div class="icoBox">🏷️</div>
        <div>
          <div class="title">Categorías</div>
          <p class="desc">Vacunas, Productos, Insumos, etc.</p>
        </div>
      </a>
    </div>
  </section>

</main>

<footer class="footer">
  <div class="inner">© <?php echo $year; ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

</body>
</html>
