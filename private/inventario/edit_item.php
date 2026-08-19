<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

function colExists(PDO $conn, string $table, string $col): bool {
  try {
    $st = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return false;
  }
}

function mysqlDateToDmy(?string $date): string {
  $date = trim((string)$date);
  if ($date === "" || $date === "0000-00-00") return "";
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
    return $m[3] . "/" . $m[2] . "/" . $m[1];
  }
  return $date;
}

function ensureExpirationColumn(PDO $conn): void {
  try {
    if (!colExists($conn, "inventory_items", "expiration_date")) {
      $conn->exec("ALTER TABLE inventory_items ADD COLUMN expiration_date DATE NULL AFTER sale_price");
    }
  } catch (Throwable $e) {
    // Si no hay permiso para ALTER, la página sigue funcionando.
  }
}

ensureExpirationColumn($conn);
$hasExpirationColumn = colExists($conn, "inventory_items", "expiration_date");

$user = $_SESSION["user"] ?? [];
$year = (int)date("Y");
$branch_id = (int)($user["branch_id"] ?? 0);

if ($branch_id <= 0) { die("Sucursal inválida (branch_id)."); }

/* Nombre sucursal */
$branch_name = "";
try {
  $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branch_id]);
  $branch_name = (string)($stB->fetchColumn() ?: "");
} catch (Throwable $e) {}

/* ID item */
$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) { die("ID inválido"); }

/* Categorías */
$categories = [];
try {
  $qc = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
  $categories = $qc ? $qc->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {}

/* Cargar item SOLO si existe en esta sucursal */
$st = $conn->prepare("
  SELECT i.id, i.name, i.category_id, i.sale_price, i.min_stock,
         " . ($hasExpirationColumn ? "i.expiration_date" : "NULL AS expiration_date") . "
  FROM inventory_items i
  INNER JOIN inventory_stock s
    ON s.item_id = i.id AND s.branch_id = ?
  WHERE i.id = ? AND (i.is_active = 1 OR i.is_active IS NULL)
  LIMIT 1
");
$st->execute([$branch_id, $id]);
$item = $st->fetch(PDO::FETCH_ASSOC);

if (!$item) { die("Producto no encontrado en esta sucursal."); }

$flash_error = "";

/* Guardar cambios: solo fecha de vencimiento */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $expiration_date = trim((string)($_POST["expiration_date"] ?? ""));

  if ($expiration_date !== "") {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $expiration_date, $m)) {
      $flash_error = "Fecha de vencimiento inválida. Usa el formato DD/MM/AAAA.";
    } else {
      $yearDate = (int)$m[1];
      $monthDate = (int)$m[2];
      $dayDate = (int)$m[3];

      if (!checkdate($monthDate, $dayDate, $yearDate)) {
        $flash_error = "La fecha de vencimiento no existe. Revisa el día, mes y año.";
      }
    }
  }

  if ($flash_error === "") {
    $up = $conn->prepare("
      UPDATE inventory_items
      SET expiration_date = ?
      WHERE id = ? AND (is_active = 1 OR is_active IS NULL)
    ");

    $ok = $up->execute([
      $expiration_date !== "" ? $expiration_date : null,
      $id
    ]);

    if ($ok) {
      $_SESSION["flash_success"] = "✅ Fecha de vencimiento actualizada correctamente";
      header("Location: /private/inventario/items.php");
      exit;
    } else {
      $flash_error = "No se pudo guardar. Intenta de nuevo.";
    }
  }

  $item["expiration_date"] = $expiration_date !== "" ? $expiration_date : null;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Editar producto</title>

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

    /* Contenedor centrado */
    .pageWrap{
      width:100%;
      max-width: 920px;
      margin: 0 auto;
    }

    .hero{
      text-align:center;
      padding: 14px 10px;
      border-radius: 18px;
      background: radial-gradient(900px 240px at 50% 0%, rgba(20,184,166,.16), rgba(255,255,255,0));
      margin-bottom: 14px;
    }
    .hero h1{
      margin: 2px 0 6px;
      font-family:'Manrope';
      font-size: 34px;
      letter-spacing: .2px;
      font-weight: 900;
      color:#fff;
    }
    .hero p{
      margin:0;
      color: #9FB6CC;
      font-weight: 800;
    }

    .card{
      border-radius: 18px;
      background:rgba(255,255,255,.07);
      backdrop-filter:blur(14px);
      -webkit-backdrop-filter:blur(14px);
      border: 1px solid rgba(255,255,255,.12);
      padding: 18px;
    }

    .cardHead{
      display:flex;
      justify-content:space-between;
      align-items:flex-start;
      gap:12px;
      flex-wrap:wrap;
      margin-bottom: 10px;
    }
    .cardHead h3{
      margin:0 0 6px;
      font-size:18px;
      color:#fff;
      font-weight: 900;
    }
    .muted{ margin:0; color:#9FB6CC; font-weight:700; }

    /* Form */
    .formGrid{
      display:grid;
      grid-template-columns: 1.2fr 1fr;
      gap: 14px;
      margin-top: 14px;
    }
    .field{ display:flex; flex-direction:column; gap:8px; }
    .field label{ font-weight:900; color:#9FB6CC; font-size:13px; }
    .field input, .field select{
      height:42px;
      padding:0 12px;
      border-radius:14px;
      border:1px solid rgba(255,255,255,.16);
      outline:none;
      font-weight:800;
      background:rgba(255,255,255,.06);
      color:#fff;
    }
    .field input:focus, .field select:focus{
      border-color:var(--teal);
      box-shadow:0 0 0 3px rgba(20,184,166,.20);
    }
    .field select option{ background:var(--navy-deep); color:#fff; }
    .field input[readonly], .field select[disabled]{
      background:rgba(255,255,255,.03) !important;
      color:#9FB6CC !important;
      cursor:not-allowed !important;
    }
    .spanAll{ grid-column: 1 / -1; }

    /* Botones */
    .btnx{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      height:38px;
      padding: 0 14px;
      border-radius: 999px;
      font-weight: 900;
      border: 1px solid rgba(255,255,255,.16);
      background: rgba(255,255,255,.08);
      color:#fff;
      text-decoration:none;
      cursor:pointer;
      transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease, background .15s ease;
      user-select:none;
    }
    .btnx:hover{ transform: translateY(-1px); background:rgba(255,255,255,.14); }
    .btnPrimary{
      background: var(--teal);
      border-color: var(--teal);
      color:#04211d;
    }
    .btnPrimary:hover{ background: var(--teal-dark); }
    .btnGhost{
      background: transparent;
    }

    .actions{
      display:flex;
      gap:10px;
      justify-content:flex-end;
      flex-wrap:wrap;
      margin-top: 16px;
      padding-top: 14px;
      border-top: 1px dashed rgba(255,255,255,.14);
    }

    .err{
      background:rgba(220,38,38,.14);
      border:1px solid rgba(220,38,38,.35);
      color:#f87171;
      border-radius:14px;
      padding:10px 12px;
      font-size:13px;
      margin: 10px 0 14px;
      font-weight: 800;
    }

    @media(max-width:980px){
      .layout{ flex-direction:column; }
      .sidebar{ width:100%; }
      .content{ padding:24px 18px 40px; }
    }
    @media (max-width: 820px){
      .formGrid{ grid-template-columns:1fr; }
      .hero h1{ font-size: 28px; }
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
    <div class="pageWrap">

      <section class="hero">
        <h1>Inventario</h1>
        <p>Editar producto — Sucursal: <strong><?= h($branch_name ?: "—") ?></strong></p>
      </section>

      <?php if ($flash_error): ?>
        <div class="err"><?= h($flash_error) ?></div>
      <?php endif; ?>

      <div class="card">
        <div class="cardHead">
          <div>
            <h3>Editar producto</h3>
            <p class="muted">Solo puedes actualizar la fecha de vencimiento del producto seleccionado.</p>
          </div>
          <a class="btnx btnGhost" href="/private/inventario/items.php">← Volver</a>
        </div>

        <form method="post">
          <div class="formGrid">

            <div class="field spanAll">
              <label>Nombre</label>
              <input value="<?= h($item["name"] ?? "") ?>" readonly>
            </div>

            <div class="field">
              <label>Categoría</label>
              <select disabled>
                <option value="0">— Sin categoría —</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int)$c["id"] ?>" <?= ((int)($item["category_id"] ?? 0) === (int)$c["id"]) ? "selected" : "" ?>>
                    <?= h($c["name"]) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label>Min Stock</label>
              <input type="number" value="3" readonly>
            </div>

            <div class="field">
              <label>Precio venta</label>
              <input
                type="number"
                step="0.01"
                value="<?= h($item["sale_price"] ?? 0) ?>"
                readonly
              >
            </div>

            <div class="field">
              <label>Fecha de vencimiento</label>
              <input
                id="expiration_date_view"
                type="text"
                placeholder="DD/MM/AAAA"
                maxlength="10"
                inputmode="numeric"
                value="<?= h(mysqlDateToDmy($item["expiration_date"] ?? "")) ?>"
              >
              <input
                id="expiration_date"
                type="hidden"
                name="expiration_date"
                value="<?= h($item["expiration_date"] ?? "") ?>"
              >
            </div>

          </div>

          <div class="actions">
            <a class="btnx" href="/private/inventario/items.php">Cancelar</a>
            <button class="btnx btnPrimary" type="submit">Guardar cambios</button>
          </div>
        </form>
      </div>

    </div>
  </main>
</div>

<footer class="footer">© <?= $year ?> CEVIMEP — Todos los derechos reservados.</footer>


<script>
(function () {
  const view = document.getElementById('expiration_date_view');
  const hidden = document.getElementById('expiration_date');

  if (!view || !hidden) return;

  function formatExpirationDate() {
    let v = view.value.replace(/\D/g, '').slice(0, 8);

    if (v.length >= 5) {
      view.value = v.slice(0, 2) + '/' + v.slice(2, 4) + '/' + v.slice(4);
    } else if (v.length >= 3) {
      view.value = v.slice(0, 2) + '/' + v.slice(2);
    } else {
      view.value = v;
    }

    const parts = view.value.split('/');
    if (parts.length === 3 && parts[0].length === 2 && parts[1].length === 2 && parts[2].length === 4) {
      hidden.value = parts[2] + '-' + parts[1] + '-' + parts[0];
    } else {
      hidden.value = '';
    }
  }

  view.addEventListener('input', formatExpirationDate);

  const form = view.closest('form');
  if (form) {
    form.addEventListener('submit', formatExpirationDate);
  }
})();
</script>

</body>
</html>