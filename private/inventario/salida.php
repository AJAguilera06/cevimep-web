<?php
declare(strict_types=1);
require_once __DIR__ . "/../_guard.php";
require_once __DIR__ . "/../csrf.php";

$conn = $pdo;

$user = $_SESSION["user"] ?? [];
$branch_id = (int)($user["branch_id"] ?? 0);
$nombre = (string)($user["full_name"] ?? "Usuario");
$user_id = (int)($user["id"] ?? 0);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }

function table_columns(PDO $conn, string $table): array {
  $st = $conn->prepare("SHOW COLUMNS FROM `$table`");
  $st->execute();
  $cols = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $cols[] = $r["Field"];
  return $cols;
}
function pick_col(array $cols, array $candidates): ?string {
  $lower = array_map("strtolower", $cols);
  foreach ($candidates as $c) {
    $i = array_search(strtolower($c), $lower, true);
    if ($i !== false) return $cols[$i];
  }
  return null;
}

function extract_order_code(?string $text): ?string {
  if (!$text) return null;

  if (preg_match('/ORD-\d+/i', $text, $m)) {
    return strtoupper(trim($m[0]));
  }

  return null;
}
function extract_order_num(?string $text): ?int {
  $code = extract_order_code($text);
  if (!$code) return null;
  if (preg_match('/ORD-(\d{1,10})/i', $code, $m)) return (int)$m[1];
  return null;
}
function next_order_for_branch(PDO $conn, int $branch_id, string $mov_branch, ?string $mov_note): int {
  if (!$mov_note) return 1;

  $sql = "SELECT `$mov_note` AS note FROM inventory_movements WHERE `$mov_branch`=? ORDER BY id DESC LIMIT 1500";
  $st = $conn->prepare($sql);
  $st->execute([$branch_id]);

  $max = 0;
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $n = extract_order_num($r["note"] ?? null);
    if ($n !== null && $n > $max) $max = $n;
  }
  return $max + 1;
}

/* =========================
   Sucursal actual
========================= */
$branch_name = "CEVIMEP";
if ($branch_id > 0) {
  try {
    $stB = $conn->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
    $stB->execute([$branch_id]);
    $bn = $stB->fetchColumn();
    if ($bn) $branch_name = (string)$bn;
  } catch (Throwable $e) {}
}

if (!isset($_SESSION["salida_cart"]) || !is_array($_SESSION["salida_cart"])) {
  $_SESSION["salida_cart"] = [];
}

$errors = [];

/* =========================
   Columnas dinámicas (compat)
========================= */
$movCols   = table_columns($conn, "inventory_movements");
$stockCols = table_columns($conn, "inventory_stock");

$mov_branch = pick_col($movCols,   ["branch_id","sucursal_id"]);
$mov_item   = pick_col($movCols,   ["item_id","product_id","inventory_item_id"]);
$mov_qty    = pick_col($movCols,   ["quantity","qty","cantidad"]);
$mov_type   = pick_col($movCols,   ["movement_type","type","mov_type","direction"]);
$mov_sup    = pick_col($movCols,   ["supplier","suplidor","provider"]); // (salida normalmente no)
$mov_user   = pick_col($movCols,   ["created_by","user_id","made_by"]);
$mov_date   = pick_col($movCols,   ["created_at","date","fecha","created_on"]);
$mov_note   = pick_col($movCols,   ["note","notes","detalle","description"]);

$stk_branch = pick_col($stockCols, ["branch_id","sucursal_id"]);
$stk_item   = pick_col($stockCols, ["item_id","product_id","inventory_item_id"]);
$stk_qty    = pick_col($stockCols, ["quantity","qty","stock","existencia"]);

if (!$mov_branch || !$mov_item || !$mov_qty) $errors[] = "inventory_movements sin columnas base (branch/item/qty).";
if (!$stk_branch || !$stk_item || !$stk_qty) $errors[] = "inventory_stock sin columnas base (branch/item/qty).";

/* =========================
   Categorías
========================= */
$categories = [];
try {
  $stC = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
  $categories = $stC ? $stC->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {}

/* =========================
   Productos (solo sucursal actual, con stock)
========================= */
$products = [];
try {
  // Nota: mostramos TODOS aunque estén en 0 si quieres; por defecto, solo stock > 0
  $stP = $conn->prepare("
    SELECT i.id, i.name, i.category_id, s.`$stk_qty` AS stock_qty
    FROM inventory_stock s
    INNER JOIN inventory_items i ON i.id = s.`$stk_item`
    WHERE s.`$stk_branch` = ?
      AND (i.is_active = 1 OR i.is_active IS NULL)
    ORDER BY i.name ASC
  ");
  $stP->execute([$branch_id]);
  $products = $stP->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$prodMap = [];
foreach ($products as $p) {
  $prodMap[(int)$p["id"]] = [
    "name" => (string)$p["name"],
    "cat"  => (int)($p["category_id"] ?? 0),
    "stock"=> (float)($p["stock_qty"] ?? 0),
  ];
}

/* =========================
   ACTIONS
========================= */
$action = $_POST["action"] ?? "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  csrf_validate_post();


  /* Añadir */
  if ($action === "add") {
    $item_id = (int)($_POST["item_id"] ?? 0);
    $qty = (int)($_POST["qty"] ?? 0);

    if ($item_id <= 0) $errors[] = "Selecciona un producto.";
    if ($qty <= 0) $errors[] = "La cantidad debe ser mayor que 0.";

    if (!$errors) {
      if (!isset($prodMap[$item_id])) {
        $errors[] = "Ese producto no pertenece a esta sucursal.";
      } else {
        $stock = (float)$prodMap[$item_id]["stock"];
        $inCart = (int)($_SESSION["salida_cart"][$item_id]["qty"] ?? 0);
        $newTotal = $inCart + $qty;
        if ($newTotal > $stock) {
          $errors[] = "Stock insuficiente para {$prodMap[$item_id]['name']}. Disponible: {$stock}. En carrito: {$inCart}.";
        } else {
          if (!isset($_SESSION["salida_cart"][$item_id])) {
            $_SESSION["salida_cart"][$item_id] = [
              "item_id" => $item_id,
              "name" => $prodMap[$item_id]["name"],
              "qty" => $qty
            ];
          } else {
            $_SESSION["salida_cart"][$item_id]["qty"] += $qty;
          }
        }
      }
    }
  }

  /* Vaciar */
  if ($action === "clear") {
    $_SESSION["salida_cart"] = [];
    header("Location: salida.php");
    exit;
  }

  /* Quitar */
  if ($action === "remove") {
    $rid = (int)($_POST["remove_id"] ?? 0);
    if ($rid > 0 && isset($_SESSION["salida_cart"][$rid])) {
      unset($_SESSION["salida_cart"][$rid]);
    }
    header("Location: salida.php");
    exit;
  }

  /* Guardar + imprimir automático */
  if ($action === "save_print") {

    $fecha = trim((string)($_POST["fecha"] ?? date("Y-m-d")));
    $destino = trim((string)($_POST["destino"] ?? ""));   // Ej: Consultorio, Brigada...
    $nota = trim((string)($_POST["nota"] ?? ""));         // Observación

    if (empty($_SESSION["salida_cart"])) $errors[] = "No hay productos agregados.";
    if ($fecha === "") $errors[] = "La fecha es obligatoria.";
    if ($destino === "") $errors[] = "El destino es obligatorio.";

    // Validar stock actual antes de guardar
    if (!$errors) {
      // refrescar stocks por seguridad (por si cambió)
      $stS = $conn->prepare("
        SELECT s.`$stk_qty` AS stock_qty
        FROM inventory_stock s
        WHERE s.`$stk_branch`=? AND s.`$stk_item`=?
        LIMIT 1
      ");

      foreach ($_SESSION["salida_cart"] as $line) {
        $iid = (int)$line["item_id"];
        $q = (int)$line["qty"];

        $stS->execute([$branch_id, $iid]);
        $stk = (float)($stS->fetchColumn() ?? 0);

        $name = $_SESSION["salida_cart"][$iid]["name"] ?? "producto";
        if ($q > $stk) $errors[] = "Stock insuficiente para {$name}. Disponible: {$stk}.";
      }
    }

    if (!$errors && $mov_branch && $mov_item && $mov_qty && $stk_branch && $stk_item && $stk_qty) {

      // # ORDEN secuencial por sucursal (mismo patrón que entrada)
      $order_num  = next_order_for_branch($conn, $branch_id, $mov_branch, $mov_note);
      $order_code = "ORD-" . str_pad((string)$order_num, 6, "0", STR_PAD_LEFT);

      // ID interno
      $receipt_id = "SAL-" . date("Ymd-His") . "-" . $branch_id;

      // datetime final
      $dt = date("Y-m-d H:i:s");
      if (preg_match("/^\\d{4}-\\d{2}-\\d{2}$/", $fecha)) {
        $dt = $fecha . " " . date("H:i:s");
      }

      try {
        $conn->beginTransaction();

        // INSERT dinámico
        $baseCols = [$mov_branch, $mov_item, $mov_qty];
        $extraCols = [];
        $extraVals = [];

        if ($mov_type) { $extraCols[] = $mov_type; $extraVals[] = "OUT"; }
        if ($mov_user) { $extraCols[] = $mov_user; $extraVals[] = $user_id; }
        if ($mov_date) { $extraCols[] = $mov_date; $extraVals[] = $dt; }

        // NOTE con # Orden + destino + nota
        $noteTxt = "";
        if ($mov_note) {
          $extraCols[] = $mov_note;
          $noteTxt = $order_code . " | " . $receipt_id . " | Destino: " . $destino;
          if ($nota !== "") $noteTxt .= " | Nota: " . $nota;
          $extraVals[] = $noteTxt;
        }

        $insCols = array_merge($baseCols, $extraCols);
        $ph = implode(",", array_fill(0, count($insCols), "?"));
        $sqlIns = "INSERT INTO inventory_movements (`" . implode("`,`", $insCols) . "`) VALUES ($ph)";
        $stIns = $conn->prepare($sqlIns);

        // Stock resta
        $stUpd = $conn->prepare("
          UPDATE inventory_stock
          SET `$stk_qty` = `$stk_qty` - ?
          WHERE `$stk_branch`=? AND `$stk_item`=?
        ");

        // Para acuse
        $print_lines = [];

        foreach ($_SESSION["salida_cart"] as $row) {
          $iid = (int)$row["item_id"];
          $q = (int)$row["qty"];

          $vals = array_merge([$branch_id, $iid, $q], $extraVals);
          $stIns->execute($vals);

          $stUpd->execute([$q, $branch_id, $iid]);

          $print_lines[] = [
            "categoria" => "", // la llenamos luego si quieres
            "producto"  => (string)$row["name"],
            "cantidad"  => $q
          ];
        }

        $conn->commit();

        // Guardar payload para imprimir automático (oculto)
        $_SESSION["salida_last_print"] = [
          "order_code" => $order_code,
          "receipt_id" => $receipt_id,
          "fecha"      => $fecha,
          "destino"    => $branch_name,  // “Área de destino” en tu ejemplo es la sucursal actual
          "uso_destino"=> $destino,      // “Destino” (consultorio, brigada…)
          "nota"       => $nota,
          "branch"     => $branch_name,
          "usuario"    => $nombre,
          "lines"      => $print_lines,
        ];

        $_SESSION["salida_cart"] = [];

        header("Location: salida.php?autoprint=1");
        exit;

      } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $errors[] = "Error al guardar: " . $e->getMessage();
      }
    }
  }
}

/* =========================
   Historial (últimos 50 OUT)
   CORREGIDO: usa las columnas reales:
   branch_id, item_id, movement_type, qty, note, created_at
========================= */
$history = [];
try {
  $sql = "
    SELECT
      m.created_at AS mov_date,
      m.note AS mov_note,
      COALESCE(i.name, CONCAT('Producto #', m.item_id)) AS item_name,
      m.qty AS qty
    FROM inventory_movements m
    LEFT JOIN inventory_items i ON i.id = m.item_id
    WHERE m.branch_id = ?
      AND UPPER(TRIM(m.movement_type)) = 'OUT'
    ORDER BY m.id DESC
    LIMIT 300
  ";

  $stH = $conn->prepare($sql);
  $stH->execute([$branch_id]);
  $history = $stH->fetchAll(PDO::FETCH_ASSOC) ?: [];

} catch (Throwable $e) {
  $errors[] = "Error cargando historial de salidas: " . $e->getMessage();
}

$historyGrouped = [];

foreach ($history as $r) {
  $code = extract_order_code($r["mov_note"] ?? null);
  if (!$code) {
    $code = "SIN-ORDEN-" . (string)($r["mov_date"] ?? "");
  }

  if (!isset($historyGrouped[$code])) {
    $historyGrouped[$code] = [
      "order_code" => $code,
      "date" => (string)($r["mov_date"] ?? ""),
      "note" => (string)($r["mov_note"] ?? ""),
      "items" => []
    ];
  }

  $historyGrouped[$code]["items"][] = [
    "name" => (string)($r["item_name"] ?? ""),
    "qty" => (int)($r["qty"] ?? 0)
  ];
}

$historyGrouped = array_slice($historyGrouped, 0, 50, true);

$cart = $_SESSION["salida_cart"];

/* Mapa categorías para acuse (si quieres mostrar) */
$catMap = [];
foreach ($categories as $c) $catMap[(int)$c["id"]] = (string)$c["name"];
$prodCatMap = [];
foreach ($products as $p) $prodCatMap[(int)$p["id"]] = (int)($p["category_id"] ?? 0);

$autoPrint = (isset($_GET["autoprint"]) && (int)$_GET["autoprint"] === 1);
$printData = $_SESSION["salida_last_print"] ?? null;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Salida | CEVIMEP</title>

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

    .content{ position:relative; z-index:1; flex:1; width:100%; padding:26px 22px 60px; overflow:auto; }
    .footer{ position:relative; z-index:1; text-align:center; padding:20px; font-size:12px; color:#5C7896; }

    .inv-wrap{ max-width: 1120px; margin: 0 auto; width:100%; }

    .inv-head{ text-align:center; margin-bottom:14px; }
    .inv-head h1{ margin:0; font-family:'Manrope'; font-size:40px; font-weight:900; letter-spacing:.3px; }
    .inv-head .sub{ margin-top:6px; font-weight:800; color:#9FB6CC; }
    .inv-head .branch{ margin-top:4px; font-weight:900; color:var(--teal); }

    .inv-card{
      background:rgba(255,255,255,.07);
      backdrop-filter:blur(14px);
      -webkit-backdrop-filter:blur(14px);
      border:1px solid rgba(255,255,255,.12);
      border-radius:18px;
      padding:16px;
      margin-top:14px;
    }

    .section-head{ display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:10px; }
    .section-head h3{ margin:0; font-size:18px; font-weight:900; color:#fff; }
    .muted{ margin:6px 0 0; color:#9FB6CC; font-weight:800; font-size:13px; }

    .table-wrap{ overflow:auto; border-radius:14px; border:1px solid rgba(255,255,255,.1); }
    table{ width:100%; border-collapse:collapse; background:transparent; }
    thead th{ background:rgba(20,184,166,.12); color:var(--teal); padding:12px; font-size:13px; font-weight:950; }
    tbody td{ padding:12px; border-top:1px solid rgba(255,255,255,.08); font-weight:800; font-size:13px; color:#E7EEF6; }
    tbody tr:hover{ background:rgba(20,184,166,.06); }

    /* Inputs / labels */
    label{ display:block; font-size:13px; font-weight:900; margin-bottom:6px; color:#9FB6CC; }
    select, input{
      width:100%;
      padding:10px 12px;
      border-radius:12px;
      border:1px solid rgba(255,255,255,.16);
      outline:none;
      font-weight:800;
      background:rgba(255,255,255,.06);
      color:#fff;
    }
    select option{ background:var(--navy-deep); color:#fff; }
    select:focus, input:focus{
      border-color:var(--teal);
      box-shadow:0 0 0 3px rgba(20,184,166,.18);
    }
    input[readonly]{ background:rgba(255,255,255,.03); color:#9FB6CC; }
    input::placeholder{ color:#7D95AC; }

    /* Botones */
    .btn-action{
      display:inline-flex; align-items:center; justify-content:center; gap:8px;
      padding:11px 16px; border-radius:999px; font-weight:900;
      border:1px solid rgba(20,184,166,.55);
      background:rgba(20,184,166,.18);
      color:#fff;
      cursor:pointer;
      transition: transform .12s ease, filter .12s ease, box-shadow .12s ease, background .15s ease;
      user-select:none; white-space:nowrap;
    }
    .btn-action:hover{ transform:translateY(-1px); box-shadow:0 10px 18px rgba(20,184,166,.22); background:rgba(20,184,166,.28); }
    .btn-action:active{ transform:translateY(0) scale(.99); }
    .btn-action[disabled]{ opacity:.55; cursor:not-allowed; transform:none; box-shadow:none; }
    .btn-primary{ background:var(--teal); border-color:var(--teal); color:#04211d; }
    .btn-primary:hover{ background:var(--teal-dark); }
    .btn-ghost{ border:1px solid rgba(255,255,255,.16); background:rgba(255,255,255,.06); }
    .btn-ghost:hover{ background:rgba(255,255,255,.12); }

    .hint-small{ font-size:12px; color:#9FB6CC; margin-top:6px; }

    /* Entrada/Salida: card de formulario */
    .entry-card-title{ font-size:18px; font-weight:900; margin-bottom:2px; color:#fff; }
    .entry-card-sub{ color:#9FB6CC; font-size:13px; margin-bottom:14px; }

    .entry-grid{
      display:grid;
      grid-template-columns: 1.2fr 1.2fr;
      gap:14px 18px;
      align-items:end;
    }
    .entry-grid .full{ grid-column:1 / -1; }

    .row-3{
      display:grid;
      grid-template-columns: 260px 1fr 180px 160px;
      gap:12px;
      align-items:end;
    }
    .entry-actions{ display:flex; justify-content:flex-end; }
    .save-row{ display:flex; justify-content:flex-end; margin-top:12px; }

    /* Historial colapsable */
    .hist-body{ display:none; }
    .hist-body.show{ display:block; }

    /* Acuse NO visible en pantalla (ticket de impresión, se mantiene blanco/negro) */
    .acuse-hidden { display:none; }

    .receipt { padding:18px 24px; font-family:Arial, Helvetica, sans-serif; color:#111; background:#fff; }
    .receipt .logo { text-align:center; margin-top:8px; }
    .receipt .logo img { width:160px; height:auto; display:inline-block; }
    .receipt .title { text-align:center; margin:10px 0 14px; font-weight:800; font-size:18px; }
    .receipt .meta { display:flex; justify-content:space-between; gap:18px; font-size:12px; margin-bottom:10px; }
    .receipt .meta .col { flex:1; }
    .receipt .meta .row { margin:3px 0; }
    .receipt table { width:100%; border-collapse:collapse; font-size:12px; margin-top:10px; }
    .receipt th, .receipt td { border:1px solid #cfcfcf; padding:6px 8px; text-align:left; color:#111; }
    .receipt th { font-weight:800; background:#f3f3f3; text-align:center; }
    .receipt td:last-child, .receipt th:last-child { text-align:center; width:90px; }
    .receipt .footer-note { text-align:center; margin-top:18px; font-size:11px; color:#333; }

    @media print {
      *{ -webkit-print-color-adjust:exact!important; print-color-adjust:exact!important; }
      .navbar, .sidebar, .footer, .inv-head, .inv-card { display:none !important; }
      .acuse-hidden { display:block !important; }
      body, html { background:#fff !important; }
      .layout { display:block !important; }
      .content { padding:0 !important; margin:0 !important; overflow:visible !important; }
    }

    @media(max-width:1100px){
      .row-3{ grid-template-columns: 1fr 1fr; }
    }
    @media(max-width:980px){
      .layout{ flex-direction:column; }
      .sidebar{ width:100%; }
      .content{ padding:20px 14px 40px; }
      .inv-head h1{ font-size:clamp(28px,6vw,40px); }
      .entry-grid{ grid-template-columns:1fr; }
      .row-3{ grid-template-columns:1fr; }
      .btn-action{ width:100%; }
    }
  </style>
</head>
<body>

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
      <a href="#" onclick="return false;" style="opacity:.5;cursor:not-allowed;">
    📅 Citas (Próximamente)
</a>
      <a href="/private/facturacion/index.php">🧾 Facturación</a>
      <a href="/private/caja/index.php">💳 Caja</a>
      <a class="active" href="/private/inventario/index.php">📦 Inventario</a>
      <a href="/private/estadistica/index.php">📊 Estadísticas</a>
    </nav>
  </aside>

  <main class="content inv-root inv-entrada">
    <div class="inv-wrap">

      <div class="inv-head">
        <h1>Salida</h1>
        <div class="sub">Registra salida de inventario (sede actual)</div>
        <div class="branch"><?= h($branch_name) ?></div>
      </div>

      <?php if (!empty($errors)): ?>
        <div class="inv-card" style="border:1px solid rgba(220,38,38,.35); background: rgba(220,38,38,.10);">
          <strong style="color:#f87171;">Revisa:</strong>
          <ul style="margin:8px 0 0 18px; color:#f2b8b8;">
            <?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <!-- ACUSE OCULTO PARA IMPRIMIR AUTOMÁTICO -->
      <?php if ($autoPrint && is_array($printData)): ?>
        <div class="acuse-hidden">
          <div class="receipt">
            <div class="logo">
              <!-- ✅ CAMBIA EL NOMBRE SI TU LOGO NO ES logo.png -->
              <img src="/assets/img/logo.png" alt="CEVIMEP">
            </div>

            <div class="title">Comprobante de Salida</div>

            <div class="meta">
              <div class="col">
                <div class="row"><strong># Orden:</strong> <?= h($printData["order_code"] ?? "") ?></div>
                <div class="row"><strong>Fecha:</strong> <?= h($printData["fecha"] ?? "") ?></div>
                <div class="row"><strong>Área de destino:</strong> <?= h($printData["destino"] ?? "") ?></div>
              </div>
              <div class="col">
                <div class="row"><strong>Destino:</strong> <?= h($printData["uso_destino"] ?? "") ?></div>
                <div class="row"><strong>Nota:</strong> <?= h($printData["nota"] ?? "") ?></div>
              </div>
            </div>

            <table>
              <thead>
                <tr>
                  <th>Categoría</th>
                  <th>Producto</th>
                  <th>Cantidad</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (($printData["lines"] ?? []) as $ln): ?>
                  <tr>
                    <td><?= h($ln["categoria"] ?? "") ?></td>
                    <td><?= h($ln["producto"] ?? "") ?></td>
                    <td><?= (int)($ln["cantidad"] ?? 0) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>

            <div class="footer-note">© <?= (int)date("Y") ?> CEVIMEP. Todos los derechos reservados.</div>
          </div>
        </div>
      <?php endif; ?>

      <!-- FORMULARIO (igual a Entrada pero adaptado a Salida) -->
      <div class="inv-card">
        <div class="entry-card-title">Salida</div>
        <div class="entry-card-sub">Registra salida de inventario (sede actual)</div>

        <!-- save -->
        <form method="post" id="saveForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_print">
        </form>

        <!-- add -->
        <form method="post" id="addForm">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add">
        </form>

        <!-- Arriba: Fecha / Destino / Nota -->
        <div class="entry-grid">
          <div>
            <label>Fecha</label>
            <input form="saveForm" type="date" name="fecha" value="<?= h(date("Y-m-d")) ?>">
          </div>

          <div>
            <label>Destino</label>
            <input form="saveForm" type="text" name="destino" placeholder="Ej: Consultorio, Brigada, Paciente..." required>
          </div>

          <div class="full">
            <label>Nota (opcional)</label>
            <input form="saveForm" type="text" name="nota" placeholder="Observación...">
          </div>
        </div>

        <!-- Fila: categoría / producto / cantidad / añadir -->
        <div class="row-3" style="margin-top:18px">
          <div>
            <label>Categoría</label>
            <select form="addForm" id="category_id" name="category_id">
              <option value="">— Todas —</option>
              <?php foreach($categories as $c): ?>
                <option value="<?= (int)$c["id"] ?>"><?= h($c["name"]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label>Producto</label>
            <select form="addForm" id="item_id" name="item_id" required>
              <option value="">— Seleccionar —</option>
              <?php foreach($products as $p): ?>
                <?php
                  $stk = (float)($p["stock_qty"] ?? 0);
                  $label = (string)$p["name"];
                ?>
                <option value="<?= (int)$p["id"] ?>" data-cat="<?= (int)($p["category_id"] ?? 0) ?>">
                  <?= h($label) ?><?= $stk > 0 ? " (Stock: ".$stk.")" : " (Stock: 0)" ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="hint-small">Solo productos de esta sucursal.</div>
          </div>

          <div>
            <label>Cantidad</label>
            <input form="addForm" type="number" name="qty" min="1" step="1" value="1" required>
          </div>

          <div class="entry-actions">
            <button class="btn-action btn-primary" type="submit" form="addForm">Añadir</button>
          </div>
        </div>

        <!-- Tabla detalle -->
        <div class="table-wrap" style="margin-top:16px;">
          <table>
            <thead>
              <tr>
                <th>Categoría</th>
                <th>Producto</th>
                <th style="width:140px;">Cantidad</th>
                <th style="width:140px;">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($cart)): ?>
                <tr><td colspan="4" style="text-align:center; padding:18px;">No hay productos agregados.</td></tr>
              <?php else: ?>
                <?php foreach($cart as $row): ?>
                  <?php
                    $iid = (int)$row["item_id"];
                    $cid = (int)($prodCatMap[$iid] ?? 0);
                    $cname = $cid > 0 ? ($catMap[$cid] ?? "") : "";
                  ?>
                  <tr>
                    <td><?= h($cname) ?></td>
                    <td style="font-weight:900;"><?= h($row["name"]) ?></td>
                    <td><?= (int)$row["qty"] ?></td>
                    <td>
                      <form method="post" style="display:inline;">
          <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="remove_id" value="<?= (int)$row["item_id"] ?>">
                        <button class="btn-action btn-ghost" type="submit">Quitar</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="save-row" style="gap:10px;">
          <form method="post" style="margin:0;">
          <?= csrf_field() ?>
            <input type="hidden" name="action" value="clear">
            <button class="btn-action btn-ghost" type="submit">Vaciar</button>
          </form>

          <button id="btnSave" class="btn-action btn-primary" type="submit" form="saveForm">Guardar e Imprimir</button>
        </div>
      </div>

      <!-- HISTORIAL -->
      <div class="inv-card" style="margin-top:16px;">
        <div class="section-head">
          <div>
            <h3>Historial de Salidas</h3>
            <p class="muted">Últimos 50 registros (sede actual)</p>
          </div>

          <button class="btn-action btn-ghost" type="button"
            onclick="document.getElementById('histBody').classList.toggle('show')">
            Ver el historial
          </button>
        </div>

        <div id="histBody" class="hist-body show">
          <div class="table-wrap" style="margin-top:12px;">
            <table>
              <thead>
                <tr>
                  <th style="width:230px;">Fecha</th>
                  <th>Productos incluidos en el acuse</th>
                  <th style="width:140px;"># Orden</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($historyGrouped)): ?>
                  <tr><td colspan="3" style="text-align:center; padding:18px;">No hay registros.</td></tr>
                <?php else: ?>
                  <?php foreach($historyGrouped as $g): ?>
                    <tr>
                      <td><?= h($g["date"] ?? "") ?></td>
                      <td>
                        <?php foreach (($g["items"] ?? []) as $it): ?>
                          <div style="font-weight:900;"><?= h($it["name"] ?? "") ?> <span style="font-weight:700;">x <?= (int)($it["qty"] ?? 0) ?></span></div>
                        <?php endforeach; ?>
                      </td>
                      <td style="font-weight:900;"><?= h($g["order_code"] ?? "—") ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </main>
</div>

<footer class="footer">
  © <?= (int)date("Y") ?> CEVIMEP. Todos los derechos reservados.
</footer>

<script>
  // Filtro por categoría (front)
  (function(){
    const cat = document.getElementById('category_id');
    const item = document.getElementById('item_id');
    if(!cat || !item) return;

    function apply(){
      const c = parseInt(cat.value || "0", 10);
      [...item.options].forEach(opt=>{
        if(!opt.value) return;
        const oc = parseInt(opt.getAttribute('data-cat') || "0", 10);
        opt.style.display = (!c || oc === c) ? "" : "none";
      });
      if(item.value){
        const sel = item.selectedOptions[0];
        if(sel && sel.style.display === "none") item.value = "";
      }
    }
    cat.addEventListener('change', apply);
    apply();
  })();

  // Evitar doble click en Guardar
  (function(){
    const btn = document.getElementById('btnSave');
    const form = document.getElementById('saveForm');
    if(!btn || !form) return;
    form.addEventListener('submit', function(){
      btn.disabled = true;
      btn.textContent = 'Guardando...';
    });
  })();

  // Imprimir automático si venimos de ?autoprint=1
  (function(){
    const url = new URL(window.location.href);
    const ap = url.searchParams.get('autoprint');
    if(ap === '1'){
      setTimeout(function(){
        window.print();
        url.searchParams.delete('autoprint');
        window.history.replaceState({}, document.title, url.toString());
      }, 250);
    }
  })();
</script>

</body>
</html>