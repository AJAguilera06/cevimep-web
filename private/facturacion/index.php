<?php
session_start();
if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }

require_once __DIR__ . "/../../config/db.php";
$conn = $pdo;

$user = $_SESSION["user"];
$year = date("Y");
$branch_id = (int)($user["branch_id"] ?? 0);

$branch_name = "";
if ($branch_id > 0) {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $branch_name = (string)($stB->fetchColumn() ?? "");
}
if ($branch_name === "") $branch_name = "CEVIMEP";

/* Pacientes de la sucursal */
$patients = [];
if ($branch_id > 0) {
  $st = $conn->prepare("
    SELECT 
      p.id,
      TRIM(CONCAT(p.first_name,' ',p.last_name)) AS full_name,
      (
        SELECT COUNT(*)
        FROM invoices i
        WHERE i.patient_id = p.id AND i.branch_id = ?
      ) AS invoices_count
    FROM patients p
    WHERE p.branch_id = ?
    ORDER BY full_name ASC
  ");
  $st->execute([$branch_id, $branch_id]);
  $patients = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Facturación</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">

  <style>
    /* ✅ LAYOUT ESTABLE (MISMO QUE CAJA / INVENTARIO) */
    html,body{height:100%;}
    body{margin:0;display:flex;flex-direction:column;min-height:100vh;overflow:hidden !important;}
    .app{flex:1;display:flex;min-height:0;}
    .main{flex:1;min-width:0;overflow:auto;padding:22px;}

    .btnSmall{
      padding:8px 12px;
      border-radius:999px;
      font-weight:900;
      border:1px solid rgba(2,21,44,.12);
      background:#eef6ff;
      cursor:pointer;
      text-decoration:none;
      display:inline-flex;
      align-items:center;
      justify-content:center
    }
    .pill{
      padding:6px 12px;
      border-radius:999px;
      font-weight:900;
      font-size:12px;
      display:inline-flex;
      align-items:center;
      justify-content:center
    }
    .pill.ok{background:#e8fff1;color:#067647;border:1px solid #abefc6}
    .pill.no{background:#fff0f0;color:#b42318;border:1px solid #fecdca}
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

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="title">Menú</div>
    <nav class="menu">
      <a href="../dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="../patients/index.php"><span class="ico">🧑‍🤝‍🧑</span> Pacientes</a>

      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;">
        <span class="ico">📅</span> Citas
      </a>

      <!-- ✅ ACTIVO CORRECTO -->
      <a class="active" href="index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="../caja/index.php"><span class="ico">💳</span> Caja</a>
      <a href="../inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="../estadistica/reporte_diario.php"><span class="ico">📊</span> Estadística</a>
    </nav>
  </aside>

  <!-- CONTENIDO -->
  <section class="main">
    <div class="card">

      <h2 style="margin:0;">Facturación</h2>
      <p class="muted" style="margin:4px 0 12px;">
        Sucursal actual: <strong><?= htmlspecialchars($branch_name) ?></strong>
      </p>

      <div style="max-width:420px;margin-bottom:12px;">
        <input type="text" id="searchInput" placeholder="Buscar paciente..." class="input">
      </div>

      <table class="table">
        <thead>
          <tr>
            <th>Nombre</th>
            <th style="width:200px;">Estado (por sucursal)</th>
          </tr>
        </thead>
        <tbody id="patientsTable">
          <?php if (empty($patients)): ?>
            <tr><td colspan="2" class="muted">No hay pacientes registrados.</td></tr>
          <?php else: ?>
            <?php foreach ($patients as $p): ?>
              <tr>
                <td><?= htmlspecialchars($p["full_name"]) ?></td>
                <td>
                  <?php if ((int)$p["invoices_count"] > 0): ?>
                    <a class="pill ok" href="paciente.php?patient_id=<?= (int)$p["id"] ?>">Con factura</a>
                  <?php else: ?>
                    <span class="pill no">Sin factura</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

    </div>
  </section>
</main>

<footer class="footer">
  <div class="inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

<script>
document.getElementById("searchInput").addEventListener("keyup", function () {
  const filter = this.value.toLowerCase();
  document.querySelectorAll("#patientsTable tr").forEach(tr => {
    tr.style.display = tr.textContent.toLowerCase().includes(filter) ? "" : "none";
  });
});
</script>

</body>
</html>
