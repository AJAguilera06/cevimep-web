<?php
require_once __DIR__ . '/../_guard.php';
$conn = $pdo;

$user = $_SESSION['user'];
$branch_id = (int)$user['branch_id'];
$branch_name = $user['branch_name'] ?? 'Sucursal';
$year = date('Y');

/* === Aquí va TODA tu lógica ACTUAL de entrada === */
/* NO la cambio: categorías, productos, carrito, guardar, imprimir, etc. */
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>CEVIMEP | Entrada de Inventario</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="stylesheet" href="/assets/css/styles.css?v=30">

<style>
.pageWrap{
  max-width: 980px;
  margin: 0 auto;
  padding: 20px;
}

.hero{
  text-align:center;
  margin-bottom: 20px;
}
.hero h1{
  font-size: 34px;
  margin: 0;
  color:#0b2a4a;
}
.hero p{
  margin: 6px 0 0;
  font-weight: 800;
  color: rgba(11,42,74,.75);
}

.card{
  background:#fff;
  border-radius:18px;
  padding:20px;
  box-shadow:0 18px 40px rgba(0,0,0,.08);
  border:1px solid rgba(0,0,0,.08);
  margin-bottom: 22px;
}

.sectionTitle{
  font-size:18px;
  font-weight:900;
  color:#0b2a4a;
  margin-bottom: 14px;
}

.formGrid{
  display:grid;
  grid-template-columns: repeat(2, 1fr);
  gap:14px;
}

.field{
  display:flex;
  flex-direction:column;
  gap:6px;
}
.field label{
  font-weight:900;
  font-size:13px;
  color:#0b2a4a;
}
.field input,
.field select{
  height:42px;
  padding:0 12px;
  border-radius:14px;
  border:1px solid rgba(0,0,0,.15);
  font-weight:800;
}
.field input:focus,
.field select:focus{
  outline:none;
  border-color:#7fb2ff;
  box-shadow:0 0 0 3px rgba(127,178,255,.25);
}

.spanAll{ grid-column: 1 / -1; }

.actions{
  display:flex;
  justify-content:flex-end;
  gap:10px;
  margin-top:16px;
}

.btn{
  height:38px;
  padding:0 16px;
  border-radius:999px;
  font-weight:900;
  border:1px solid rgba(0,0,0,.15);
  cursor:pointer;
  background:#fff;
}
.btnPrimary{
  background:linear-gradient(180deg,#0f4f8a,#0b2a4a);
  color:#fff;
}
.btn:hover{
  transform:translateY(-1px);
  box-shadow:0 8px 18px rgba(0,0,0,.12);
}

.tableWrap{
  overflow-x:auto;
}
table{
  width:100%;
  border-collapse:collapse;
}
th,td{
  padding:10px;
  text-align:center;
}
th{
  background:#0b2a4a;
  color:#fff;
}
td{
  border-bottom:1px solid #eee;
}

.center{
  text-align:center;
}

@media(max-width:800px){
  .formGrid{ grid-template-columns:1fr; }
  .hero h1{ font-size:28px; }
}
</style>
</head>

<body>

<!-- TOPBAR -->
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
    <a href="/private/dashboard.php">🏠 Panel</a>
    <a href="/private/patients/index.php">👤 Pacientes</a>
    <a href="/private/citas/index.php">📅 Citas</a>
    <a href="/private/facturacion/index.php">🧾 Facturación</a>
    <a href="/private/caja/index.php">💳 Caja</a>
    <a class="active" href="/private/inventario/index.php">📦 Inventario</a>
    <a href="/private/estadisticas/index.php">📊 Estadísticas</a>
  </nav>
</aside>

<main class="content">
<div class="pageWrap">

  <!-- HERO -->
  <div class="hero">
    <h1>Entrada</h1>
    <p>Registra entrada de inventario — <strong><?= $branch_name ?></strong></p>
  </div>

  <!-- FORMULARIO -->
  <div class="card">
    <div class="sectionTitle">Datos de entrada</div>

    <form method="post">
      <div class="formGrid">

        <div class="field">
          <label>Categoría</label>
          <!-- tu select actual -->
          <?= $select_categoria ?? '' ?>
        </div>

        <div class="field">
          <label>Producto</label>
          <?= $select_producto ?? '' ?>
        </div>

        <div class="field">
          <label>Cantidad</label>
          <input type="number" name="cantidad" min="1" value="1" required>
        </div>

        <div class="field">
          <label>Suplidor</label>
          <input type="text" name="suplidor" placeholder="Escribe el suplidor (opcional)">
        </div>

        <div class="field spanAll">
          <label>Hecha por</label>
          <input type="text" value="<?= htmlspecialchars($branch_name) ?>" readonly>
        </div>

      </div>

      <div class="actions">
        <button class="btn btnPrimary" name="add_item">Añadir</button>
      </div>
    </form>
  </div>

  <!-- DETALLE -->
  <div class="card">
    <div class="sectionTitle">Detalle</div>

    <div class="tableWrap">
      <?= $tabla_detalle ?? '<p class="center">No hay productos agregados.</p>' ?>
    </div>

    <div class="actions">
      <form method="post">
        <button class="btn" name="clear">Vaciar</button>
        <button class="btn btnPrimary" name="save_print">Guardar e imprimir</button>
      </form>
    </div>
  </div>

  <!-- HISTORIAL -->
  <div class="card">
    <div class="sectionTitle">Historial de Entradas</div>
    <p class="center">Últimos 50 registros (sede actual)</p>

    <div class="center">
      <a class="btn" href="/private/inventario/historial_entradas.php">Ver historial</a>
    </div>
  </div>

</div>
</main>
</div>

<div class="footer">
  <div class="inner">
    © <?= $year ?> CEVIMEP. Todos los derechos reservados.
  </div>
</div>

</body>
</html>
