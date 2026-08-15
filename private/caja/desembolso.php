<?php
declare(strict_types=1);

date_default_timezone_set('America/Santo_Domingo');

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/caja_helpers.php';

$hoy = date('Y-m-d');
$horaActual24 = date('H:i');

$mensaje = '';
$tipo_mensaje = 'info';

function h($s){ 
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function desembolsoPrefixByBranch(int $branchId): string {
  $map = [
    1 => "M",   // Moca
    2 => "L",   // La Vega
    3 => "SC",  // Salcedo
    4 => "S",   // Santiago
    5 => "V",   // Mao
    6 => "P",   // Puerto Plata
  ];

  return $map[$branchId] ?? "D";
}

function buildDesembolsoCode(int $branchId, int $number): string {
  return desembolsoPrefixByBranch($branchId) . "-D-" . str_pad((string)$number, 7, "0", STR_PAD_LEFT);
}

function tableColumns(PDO $pdo, string $table): array {
  try {
    $rows = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(fn($r) => $r["Field"], $rows);
  } catch (Throwable $e) {
    try {
      $st = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY ordinal_position");
      $st->execute([$table]);
      return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e2) {
      return [];
    }
  }
}


// Usar SIEMPRE la sucursal del usuario logueado.
// Antes estaba forzando Santiago, por eso los desembolsos no se reflejaban
// en la sucursal correcta.
$branch_id  = (int)caja_require_branch_id();
$created_by = (int)($_SESSION['user']['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $monto_in = (float)($_POST['monto'] ?? 0);
  $motivo   = trim((string)($_POST['motivo'] ?? ''));
  $hechoPor = trim((string)($_POST['hecho_por'] ?? ''));

  if ($branch_id <= 0) {
    $mensaje = "⚠️ No se encontró una sucursal válida.";
    $tipo_mensaje = "warning";

  } elseif (!($monto_in > 0) || $motivo === '') {
    $mensaje = "⚠️ Completa Motivo y Monto (mayor a 0).";
    $tipo_mensaje = "warning";

  } else {

    $caja_sesion_id = (int)caja_get_or_create_session_id($pdo, $branch_id);

    if ($caja_sesion_id <= 0) {
      $mensaje = "⚠️ La caja del turno actual está cerrada. Debes abrir caja antes de registrar desembolsos.";
      $tipo_mensaje = "warning";
    } else {

      try {
        $amount = -abs($monto_in);

        $motivo_db = $hechoPor !== ''
          ? "Hecho por: {$hechoPor} | {$motivo}"
          : $motivo;

        // Numeración independiente por sucursal, igual que facturación.
        // Si tu tabla tiene columnas desembolso_number/desembolso_code, se guardan.
        // Si no existen, el acuse lo calcula por sucursal al imprimir.
        $movementCols = tableColumns($pdo, "cash_movements");
        $hasDesembolsoNumber = in_array("desembolso_number", $movementCols, true);
        $hasDesembolsoCode   = in_array("desembolso_code", $movementCols, true);

        $desembolso_number = 1;

        if ($hasDesembolsoNumber) {
          $stNum = $pdo->prepare("
            SELECT COALESCE(MAX(desembolso_number), 0) + 1
            FROM cash_movements
            WHERE branch_id = ?
              AND type = 'desembolso'
          ");
          $stNum->execute([$branch_id]);
          $desembolso_number = (int)($stNum->fetchColumn() ?: 1);
          if ($desembolso_number <= 0) $desembolso_number = 1;
        } else {
          // Si no existe la columna, calculamos el próximo número por conteo de esta sucursal.
          // No se guarda en BD, pero el acuse lo podrá calcular por branch_id + id.
          $stNum = $pdo->prepare("
            SELECT COUNT(*) + 1
            FROM cash_movements
            WHERE branch_id = ?
              AND type = 'desembolso'
          ");
          $stNum->execute([$branch_id]);
          $desembolso_number = (int)($stNum->fetchColumn() ?: 1);
          if ($desembolso_number <= 0) $desembolso_number = 1;
        }

        $desembolso_code = buildDesembolsoCode($branch_id, $desembolso_number);

        $fields = ["branch_id", "caja_sesion_id", "type", "motivo", "metodo_pago", "amount", "created_by"];
        $values = [":branch_id", ":caja_sesion_id", "'desembolso'", ":motivo", "'efectivo'", ":amount", ":created_by"];

        $paramsIns = [
          ':branch_id' => $branch_id,
          ':caja_sesion_id' => $caja_sesion_id,
          ':motivo' => $motivo_db,
          ':amount' => $amount,
          ':created_by' => $created_by,
        ];

        if ($hasDesembolsoNumber) {
          $fields[] = "desembolso_number";
          $values[] = ":desembolso_number";
          $paramsIns[':desembolso_number'] = $desembolso_number;
        }

        if ($hasDesembolsoCode) {
          $fields[] = "desembolso_code";
          $values[] = ":desembolso_code";
          $paramsIns[':desembolso_code'] = $desembolso_code;
        }

        $sql = "INSERT INTO cash_movements (" . implode(",", $fields) . ")
                VALUES (" . implode(",", $values) . ")";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($paramsIns);

        $id = (int)$pdo->lastInsertId();

        header("Location: /private/caja/desembolso.php?ok=1&print_id={$id}");
        exit;

      } catch (Throwable $e) {
        $mensaje = "❌ Error al guardar el desembolso: " . $e->getMessage();
        $tipo_mensaje = "error";
      }
    }
  }
}

$print_id = isset($_GET['print_id']) ? (int)$_GET['print_id'] : 0;
$ok = isset($_GET['ok']) ? (int)$_GET['ok'] : 0;

if ($ok === 1 && $print_id > 0) {
  $mensaje = "✅ Desembolso registrado. Abriendo acuse para imprimir…";
  $tipo_mensaje = "success";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Caja | Registrar Desembolso</title>

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

    .page-wrap{max-width: 980px; margin: 0 auto;}
    .page-head{display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom: 10px;}
    .page-head h1{margin:0; font-family:'Manrope'; font-size: 40px; line-height: 1.1; font-weight:900;}
    .subhead{margin:6px 0 0 0; opacity:.85; color:#9FB6CC;}
    .card-soft{
        background:rgba(255,255,255,.07);
        backdrop-filter:blur(14px);
        -webkit-backdrop-filter:blur(14px);
        border:1px solid rgba(255,255,255,.12);
        border-radius: 18px;
        padding: 18px;
    }
    .grid-2{display:grid; grid-template-columns: 1fr 1fr; gap:14px;}
    .grid-1{display:grid; grid-template-columns: 1fr; gap:14px;}
    label{display:block; font-weight:700; margin-bottom:6px; color:#B9CBDD;}
    input, textarea{width:100%; padding: 12px 12px; border:1px solid rgba(255,255,255,.16); border-radius: 12px; outline:none; background:rgba(255,255,255,.06); color:#fff; font:inherit;}
    input::placeholder, textarea::placeholder{color:#7D95AC;}
    input:focus, textarea:focus{border-color:var(--teal);}
    input[readonly]{background:rgba(255,255,255,.03); color:#9FB6CC;}
    textarea{resize: vertical;}
    .btn-row{display:flex; justify-content:flex-end; gap:10px; margin-top: 6px;}

    .btn-strong{
      display:inline-flex; align-items:center; gap:8px;
      padding: 10px 14px; border-radius: 999px;
      background: var(--teal); color:#04211d !important;
      border: 1px solid var(--teal);
      font-weight: 800; text-decoration:none;
      cursor:pointer;
    }
    .btn-strong:hover{background: var(--teal-dark);}
    .btn-ghost{
      display:inline-flex; align-items:center; gap:8px;
      padding: 10px 14px; border-radius: 999px;
      background:rgba(20,184,166,.1); color:var(--teal) !important;
      border: 1px solid rgba(20,184,166,.35);
      font-weight: 800; text-decoration:none;
    }
    .btn-ghost:hover{background: rgba(20,184,166,.2);}

    .hint{font-size:12px; opacity:.75; margin-top:6px; color:#9FB6CC;}
    .alertbox{border-radius:14px; padding:12px 14px; margin: 12px 0; white-space:pre-wrap; font-weight:700;}
    .alertbox.success{background:rgba(20,184,166,.12); border:1px solid rgba(20,184,166,.3); color:var(--teal);}
    .alertbox.warning{background:rgba(234,179,8,.12); border:1px solid rgba(234,179,8,.3); color:#facc15;}
    .alertbox.error{background:rgba(220,38,38,.12); border:1px solid rgba(220,38,38,.3); color:#f87171;}

    @media (max-width: 980px){
      .layout{ flex-direction:column; }
      .sidebar{ width:100%; }
      .content{ padding:24px 18px 40px; }
      .grid-2{ grid-template-columns:1fr; }
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
    <div class="brand">
      <span class="dot"></span>
      <span>CEVIMEP</span>
    </div>
    <div class="nav-right">
      <a href="/logout.php" class="btn-pill">Salir</a>
    </div>
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
      <a class="active" href="/private/caja/index.php">💳 Caja</a>
      <a href="/private/inventario/index.php">📦 Inventario</a>
      <a href="/private/estadistica/index.php">📊 Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <div class="page-wrap">

      <div class="page-head">
        <div>
          <h1>🧾 Registrar Desembolso</h1>
          <p class="subhead">Registra un desembolso y genera el acuse para imprimir.</p>
        </div>
        <a href="/private/caja/historial_desembolso.php" class="btn-ghost">📄 Ver historial</a>
      </div>

      <?php if ($mensaje): ?>
        <div class="alertbox <?= h($tipo_mensaje) ?>">
          <?= h($mensaje) ?>
        </div>
      <?php endif; ?>

      <div class="card-soft">
        <form method="POST" class="grid-1">

          <div class="grid-2">
            <div>
              <label>Fecha</label>
              <input type="date" value="<?= h($hoy) ?>" readonly>
            </div>
            <div>
              <label>Hora (GMT-4)</label>
              <input type="time" value="<?= h($horaActual24) ?>" readonly>
            </div>
          </div>

          <div class="grid-2">
            <div>
              <label>Monto (RD$)</label>
              <input type="number" name="monto" step="0.01" min="0" required placeholder="Ej: 1500.00">
            </div>
            <div>
              <label>Hecho por</label>
              <input type="text" name="hecho_por" placeholder="Ej: Claudia Peña / Caja Santiago">
              <div class="hint">Este texto se imprime en el acuse.</div>
            </div>
          </div>

          <div>
            <label>Motivo</label>
            <textarea name="motivo" rows="3" required placeholder="Ej: Pago suplidor, combustible, mensajería..."></textarea>
          </div>

          <div class="btn-row">
            <button type="submit" class="btn-strong">🖨️ Guardar e Imprimir</button>
          </div>

        </form>
      </div>

    </div>
  </main>
</div>

<footer class="footer">
  © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

<?php if ($print_id > 0): ?>
<script>
  window.open("/private/caja/acuse_desembolso.php?id=<?= (int)$print_id ?>", "_blank");
  if (window.history && window.history.replaceState) {
    window.history.replaceState({}, document.title, "/private/caja/desembolso.php");
  }
</script>
<?php endif; ?>

</body>
</html>