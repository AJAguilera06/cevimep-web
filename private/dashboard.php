<?php
session_start();

if (!isset($_SESSION["user"])) {
  header("Location: ../public/login.php");
  exit;
}

$user = $_SESSION["user"];
$year = date("Y");

// Nombre a mostrar (si no existe en sesión, usa el que pediste)
$displayName = trim($user["name"] ?? "");
if ($displayName === "") $displayName = "Abel Aguilera";

// Rol (por si quieres mostrarlo)
$role = trim($user["role"] ?? "admin");
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Panel</title>

  <link rel="stylesheet" href="../assets/css/styles.css">

  <style>
    html, body{ height:100%; }
    body{
      display:flex;
      flex-direction:column;
      min-height:100vh;
      overflow:hidden; /* sin scroll general */
    }

    /* Tarjeta de cabecera del panel */
    .panel-head{
      display:flex;
      align-items:center;
      gap:14px;
    }
    .avatar{
      width:46px;
      height:46px;
      border-radius:16px;
      display:flex;
      align-items:center;
      justify-content:center;
      font-weight:900;
      color:#fff;
      background:linear-gradient(135deg, var(--primary), var(--primary-2));
      box-shadow: var(--shadow-soft);
      flex:0 0 auto;
    }
    .panel-name{
      display:flex;
      align-items:center;
      gap:10px;
      flex-wrap:wrap;
    }
    .badge{
      display:inline-flex;
      align-items:center;
      gap:6px;
      padding:6px 10px;
      border-radius:999px;
      font-weight:900;
      font-size:12px;
      background:rgba(20,184,166,.12);
      border:1px solid rgba(20,184,166,.35);
      color:var(--primary-2);
    }

    /* Marca item activo del sidebar */
    .menu a.active{
      background:#fff4e6;
      color:#b45309;
      border:1px solid #fed7aa;
    }
  </style>
</head>

<body>

<!-- NAVBAR -->
<header class="navbar">
  <div class="inner">
    <div></div>

    <div class="brand">
      <span class="dot"></span>
      CEVIMEP
    </div>

    <div class="nav-right">
      <a href="../public/logout.php">Salir</a>
    </div>
  </div>
</header>

<!-- APP (SIDEBAR + CONTENIDO) -->
<main class="app">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="title">Menú</div>

<nav class="menu">

  <!-- ACTIVO -->
  <a class="active" href="dashboard.php">
    <span class="ico">🏠</span> Panel
  </a>

  <!-- ÚNICO MÓDULO REAL -->
  <a href="patients/index.php">
    <span class="ico">🧑‍🤝‍🧑</span> Pacientes
  </a>

  <!-- DESHABILITADOS -->
  <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;">
    <span class="ico">📅</span> Citas
  </a>

<a href="facturacion/index.php">
  <span class="ico">🧾</span> Facturación
</a>

 <a href="caja/index.php"><span class="ico">💳</span> Caja</a>

<a href="inventario/index.php">
  <span class="ico">📦</span> Inventario
</a>

  <a href="estadistica/index.php"> 
    <span class="ico">⏳</span> Estadistica
  </a>
</nav>
  </aside>

  <!-- CONTENIDO (SOLO PANEL INTERNO) -->
  <section class="main">

    <div class="card">
      <div class="panel-head">
        <div class="avatar">
          <?php
            // Iniciales
            $parts = preg_split('/\s+/', trim($displayName));
            $ini = "";
            if (count($parts) >= 1 && $parts[0] !== "") $ini .= mb_strtoupper(mb_substr($parts[0], 0, 1));
            if (count($parts) >= 2 && $parts[1] !== "") $ini .= mb_strtoupper(mb_substr($parts[1], 0, 1));
            echo htmlspecialchars($ini ?: "AA");
          ?>
        </div>

        <div>
          <h3 style="margin:0 0 6px; font-size:22px;">Panel interno</h3>

          <div class="panel-name">
            <span class="muted" style="margin:0;">
              Hola, <b><?php echo htmlspecialchars($displayName); ?></b>
            </span>
            <span class="badge">✅ Acceso: <?php echo htmlspecialchars($role); ?></span>
          </div>

          <p class="muted" style="margin:8px 0 0;">
            Desde aquí administrarás los módulos del sistema CEVIMEP.
          </p>
        </div>
      </div>
    </div>

  </section>

</main>

<!-- FOOTER -->
<footer class="footer">
  <div class="inner">
    © <?php echo $year; ?> CEVIMEP. Todos los derechos reservados.
  </div>
</footer>

</body>
</html>
