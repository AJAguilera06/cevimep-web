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
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Facturación</title>

  <!-- ✅ MISMO CSS QUE DASHBOARD (ruta absoluta) -->
  <link rel="stylesheet" href="/public/assets/css/styles.css?v=11">






  <style>
    /* ✅ Asegura layout estable como los otros módulos */
    html,body{height:100%;}
    body{margin:0; overflow:hidden !important;}

    /* ✅ Pequeños refinamientos visuales (sin romper tu CSS global) */
    .page-card{padding:18px;}
    .header-row{display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; justify-content:space-between;}
    .subtitle{margin:4px 0 0; font-weight:700; opacity:.8;}
    .search-wrap{display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:14px;}
    .search-label{font-weight:900; color:#052a7a;}
    .search-input{
      width:min(520px, 100%);
      padding:10px 12px;
      border-radius:14px;
      border:1px solid rgba(2,21,44,.14);
      outline:none;
      background:#fff;
    }
    .search-input:focus{border-color:#93c5fd; box-shadow:0 0 0 4px rgba(59,130,246,.15);}

    .status-pill{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:8px 14px;
      border-radius:999px;
      font-weight:900;
      font-size:12px;
      text-decoration:none;
      border:1px solid transparent;
      min-width:120px;
      transition:transform .05s ease, box-shadow .15s ease, opacity .15s ease;
    }
    .status-pill:hover{transform:translateY(-1px); box-shadow:0 6px 18px rgba(2,6,23,.10);}
    .status-ok{background:#e8fff1; color:#067647; border-color:#abefc6;}
    .status-no{background:#fff0f0; color:#b42318; border-color:#fecdca;}

    .name-link{
      font-weight:900;
      color:#052a7a;
      text-decoration:none;
    }
    .name-link:hover{text-decoration:underline;}

    /* alinear columna estado a la derecha como en tu captura */
    th.state-col, td.state-col{text-align:right;}

    /* hace que la fila sea más “clicable” sin cambiar tu tabla base */
    tr.data-row:hover{background:rgba(2,21,44,.03);}
  </style>
</head>

<body>

<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right">
      <a class="
/assets/css/styles.css


-pill" href="/logout.php">Salir</a>
    </div>
  </div>
</header>

<!-- ✅ MISMA ESTRUCTURA QUE DASHBOARD -->
<div class="layout">

  <aside class="sidebar">
    <div class="menu-title">Menú</div>

    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👥</span> Pacientes</a>
      <a href="javascript:void(0)" style="opacity:.45; cursor:not-allowed;">
        <span class="ico">🗓️</span> Citas
      </a>

      <!-- ✅ ACTIVO -->
      <a class="active" href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>

      <a href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadistica/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">

    <div class="card page-card">
      <div class="header-row">
        <div>
          <h2 style="margin:0;">Facturación</h2>
          <div class="subtitle">
            Sucursal actual: <strong><?= htmlspecialchars($branch_name) ?></strong>
          </div>
        </div>
      </div>

      <div class="search-wrap">
        <div class="search-label">Buscar paciente:</div>
        <input type="text" id="searchInput" class="search-input" placeholder="Escribe el nombre..." />
      </div>

      <div style="height:12px;"></div>

      <table class="table">
        <thead>
          <tr>
            <th>Nombre</th>
            <th class="state-col" style="width:220px;">Estado (por sucursal)</th>
          </tr>
        </thead>

        <tbody id="patientsTable">
          <?php if (empty($patients)): ?>
            <tr><td colspan="2" class="muted">No hay pacientes registrados.</td></tr>
          <?php else: ?>
            <?php foreach ($patients as $p): ?>
              <?php $pid = (int)$p["id"]; ?>
              <?php $hasInv = ((int)$p["invoices_count"] > 0); ?>
              <tr class="data-row">
                <td>
                  <!-- ✅ nombre clicable -->
                  <a class="name-link" href="paciente.php?patient_id=<?= $pid ?>">
                    <?= htmlspecialchars($p["full_name"]) ?>
                  </a>
                </td>

                <td class="state-col">
                  <!-- ✅ “Sin factura” ahora FUNCIONA: te lleva a paciente.php -->
                  <a class="status-pill <?= $hasInv ? "status-ok" : "status-no" ?>"
                     href="paciente.php?patient_id=<?= $pid ?>">
                    <?= $hasInv ? "Con factura" : "Sin factura" ?>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

    </div>

  </main>
</div>

<footer class="footer">
  <div class="footer-inner">© <?= (int)$year ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

<script>
(function(){
  const input = document.getElementById("searchInput");
  const rows = () => document.querySelectorAll("#patientsTable tr.data-row");

  input.addEventListener("input", function () {
    const filter = this.value.toLowerCase().trim();
    rows().forEach(tr => {
      tr.style.display = tr.textContent.toLowerCase().includes(filter) ? "" : "none";
    });
  });
})();
</script>

</body>
</html>
