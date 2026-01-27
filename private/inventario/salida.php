<?php
declare(strict_types=1);
require_once __DIR__ . "/../_guard.php";

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
  if (preg_match('/(ORD-\d{1,10})/i', $text, $m)) return strtoupper($m[1]);
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
========================= */
$history = [];
try {
  if ($mov_branch && $mov_item && $mov_qty) {
    $selDate = $mov_date ? "`$mov_date` AS mov_date" : "NULL AS mov_date";
    $selNote = $mov_note ? "`$mov_note` AS mov_note" : "NULL AS mov_note";

    $sql = "
      SELECT $selDate, $selNote,
             i.name AS item_name,
             m.`$mov_qty` AS qty
      FROM inventory_movements m
      LEFT JOIN inventory_items i ON i.id = m.`$mov_item`
      WHERE m.`$mov_branch` = ?
    ";
    if ($mov_type) {
      $sql .= " AND (m.`$mov_type`='OUT' OR m.`$mov_type`='salida' OR m.`$mov_type`='SALIDA') ";
    }
    $sql .= " ORDER BY " . ($mov_date ? "m.`$mov_date`" : "m.id") . " DESC LIMIT 50";

    $stH = $conn->prepare($sql);
    $stH->execute([$branch_id]);
    $history = $stH->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Throwable $e) {}

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
  <link rel="stylesheet" href="/assets/css/styles.css?v=60">
  <link rel="stylesheet" href="/assets/css/inventario.css?v=60">

  <style>
    /* Acuse NO visible en pantalla */
    .acuse-hidden { display:none; }

    /* Estilo del acuse como tu ejemplo */
    .receipt {
      padding: 18px 24px;
      font-family: Arial, Helvetica, sans-serif;
      color: #111;
    }
    .receipt .logo {
      text-align:center;
      margin-top: 8px;
    }
    .receipt .logo img {
      width: 160px;
      height: auto;
      display:inline-block;
    }
    .receipt .title {
      text-align:center;
      margin: 10px 0 14px;
      font-weight: 800;
      font-size: 18px;
    }
    .receipt .meta {
      display:flex;
      justify-content: space-between;
      gap: 18px;
      font-size: 12px;
      margin-bottom: 10px;
    }
    .receipt .meta .col { flex:1; }
    .receipt .meta .row { margin: 3px 0; }
    .receipt table {
      width:100%;
      border-collapse: collapse;
      font-size: 12px;
      margin-top: 10px;
    }
    .receipt th, .receipt td {
      border: 1px solid #cfcfcf;
      padding: 6px 8px;
      text-align:left;
    }
    .receipt th { font-weight: 800; background:#f3f3f3; text-align:center; }
    .receipt td:last-child, .receipt th:last-child { text-align:center; width: 90px; }
    .receipt .footer {
      text-align:center;
      margin-top: 18px;
      font-size: 11px;
      color:#333;
    }

    @media print {
      /* Ocultar todo menos el acuse */
      .navbar, .sidebar, .footer, .inv-head, .inv-card { display:none !important; }
      .acuse-hidden { display:block !important; }

      body, html { background:#fff !important; }
      .layout { display:block !important; }
      .content.inv-root { padding:0 !important; margin:0 !important; }
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
      <a href="/private/citas/index.php">📅 Citas</a>
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
        <div class="inv-card" style="border:1px solid rgba(255,0,0,.15); background: rgba(255,0,0,.04);">
          <strong style="color:#7a1010;">Revisa:</strong>
          <ul style="margin:8px 0 0 18px;">
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

            <div class="footer">© <?= (int)date("Y") ?> CEVIMEP. Todos los derechos reservados.</div>
          </div>
        </div>
      <?php endif; ?>

      <!-- FORMULARIO (igual a Entrada pero adaptado a Salida) -->
      <div class="inv-card">
        <div class="entry-card-title">Salida</div>
        <div class="entry-card-sub">Registra salida de inventario (sede actual)</div>

        <!-- save -->
        <form method="post" id="saveForm">
          <input type="hidden" name="action" value="save_print">
        </form>

        <!-- add -->
        <form method="post" id="addForm">
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
            <input type="hidden" name="action" value="clear">
            <button class="btn-action btn-ghost" type="submit">Vaciar</button>
          </form>

          <button id="btnSave" class="btn-action" type="submit" form="saveForm">Guardar e Imprimir</button>
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

        <div id="histBody" class="hist-body">
          <div class="table-wrap" style="margin-top:12px;">
            <table>
              <thead>
                <tr>
                  <th style="width:230px;">Fecha</th>
                  <th>Producto</th>
                  <th style="width:120px;">Cantidad</th>
                  <th style="width:140px;"># Orden</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($history)): ?>
                  <tr><td colspan="4" style="text-align:center; padding:18px;">No hay registros.</td></tr>
                <?php else: ?>
                  <?php foreach($history as $r): ?>
                    <?php
                      $code = extract_order_code($r["mov_note"] ?? null);
                      $ord_txt = $code ? $code : "—";
                    ?>
                    <tr>
                      <td><?= h($r["mov_date"] ?? "") ?></td>
                      <td style="font-weight:900;"><?= h($r["item_name"] ?? "") ?></td>
                      <td><?= (int)($r["qty"] ?? 0) ?></td>
                      <td style="font-weight:900;"><?= h($ord_txt) ?></td>
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
