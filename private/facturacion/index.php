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
if ($branch_name === "") $branch_name = ($branch_id>0) ? "Sede #".$branch_id : "CEVIMEP";

$patients = [];
if ($branch_id > 0) {
  $st = $conn->prepare("
    SELECT
      p.id,
      TRIM(CONCAT(p.first_name,' ',p.last_name)) AS full_name,
      (SELECT COUNT(*) FROM invoices i WHERE i.branch_id = ? AND i.patient_id = p.id) AS invoice_count
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
    .btnSmall{padding:8px 12px;border-radius:999px;font-weight:900;border:1px solid rgba(2,21,44,.12);background:#eef6ff;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
    .pill{padding:6px 10px;border-radius:999px;font-weight:900;font-size:12px;display:inline-block;border:1px solid rgba(2,21,44,.12);background:#fff}
    .pill.ok{background:rgba(16,185,129,.10);border-color:rgba(16,185,129,.25);color:#065f46}
    .pill.no{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.20);color:#b91c1c}
    .searchBox{margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .searchBox input{width:min(520px,100%);padding:10px 12px;border:1px solid var(--border);border-radius:14px;background:#fff;font-weight:700;outline:none}
    .searchBox input:focus{border-color:rgba(20,184,166,.45);box-shadow:0 0 0 3px rgba(20,184,166,.12)}
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
  <!-- ✅ SIDEBAR EXACTA COMO TU PANEL -->
  <aside class="sidebar">
    <div class="title">Menú</div>
    <nav class="menu">
      <a class="active" href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👥</span> Pacientes</a>

      <a href="javascript:void(0)" style="opacity:.45; cursor:not-allowed;">
  <span class="ico">🗓️</span> Citas
</a>

      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>

      <a href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>

      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>

      <a href="/private/estadistica/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <section class="main">
    <div class="card">
      <h2 style="margin:0 0 6px;">Facturación</h2>
      <p class="muted" style="margin:0;">Sucursal actual: <strong><?= htmlspecialchars($branch_name) ?></strong></p>

      <?php if ($branch_id <= 0): ?>
        <div class="card" style="margin-top:12px;border-color:rgba(239,68,68,.35); background:rgba(239,68,68,.08);">
          <p style="margin:0;font-weight:900;color:#b91c1c;">⚠️ Este usuario no tiene sede asignada (branch_id). No puede facturar.</p>
        </div>
      <?php endif; ?>

      <!-- ✅ Buscador -->
      <div class="searchBox">
        <div style="font-weight:900;color:var(--primary-2);">Buscar paciente:</div>
        <input type="text" id="patientSearch" placeholder="Escribe el nombre...">
      </div>

      <table class="table" style="margin-top:12px;">
        <thead>
          <tr>
            <th>Nombre</th>
            <th style="width:260px;">Estado (por sucursal)</th>
          </tr>
        </thead>
        <tbody id="patientsTbody">
          <?php if (empty($patients)): ?>
            <tr><td colspan="2" class="muted">No hay pacientes registrados en esta sucursal.</td></tr>
          <?php else: ?>
            <?php foreach ($patients as $p): ?>
              <tr class="patientRow" data-name="<?= htmlspecialchars(mb_strtolower($p["full_name"], 'UTF-8')) ?>">
                <td><?= htmlspecialchars($p["full_name"]) ?></td>
                <td>
                  <a class="btnSmall" href="paciente.php?patient_id=<?= (int)$p["id"] ?>">
                    <?php if ((int)$p["invoice_count"] > 0): ?>
                      <span class="pill ok">Tiene factura (<?= (int)$p["invoice_count"] ?>)</span>
                    <?php else: ?>
                      <span class="pill no">Sin factura</span>
                    <?php endif; ?>
                  </a>
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
(function(){
  const input = document.getElementById('patientSearch');
  if (!input) return;

  input.addEventListener('input', () => {
    const q = (input.value || "").toLowerCase().trim();
    document.querySelectorAll('.patientRow').forEach(tr => {
      const name = (tr.getAttribute('data-name') || "");
      tr.style.display = (q === "" || name.includes(q)) ? "" : "none";
    });
  });
})();
</script>

</body>
</html>
