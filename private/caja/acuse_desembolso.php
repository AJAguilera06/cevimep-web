<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION["user"])) { header("Location: /login.php"); exit; }

$user = $_SESSION["user"];
$branchId = (int)($user["branch_id"] ?? 0);

date_default_timezone_set("America/Santo_Domingo");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function fmtMoney($n){ return number_format((float)$n, 2, ".", ","); }

function colExists(PDO $pdo, string $table, string $col): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { return false; }
}

$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) { http_response_code(400); echo "ID inválido."; exit; }

$hasBranch = colExists($pdo, "cash_movements", "branch_id");
$hasRep    = colExists($pdo, "cash_movements", "representante");
$createdCol = colExists($pdo, "cash_movements", "created_at") ? "created_at"
            : (colExists($pdo, "cash_movements", "created_on") ? "created_on" : null);

$where = "id = ? AND type = 'desembolso'";
$params = [$id];

if ($hasBranch) { $where .= " AND branch_id = ?"; $params[] = $branchId; }

$sql = "SELECT *" . ($createdCol ? ", $createdCol AS created_time" : "") . " FROM cash_movements WHERE $where LIMIT 1";
$st = $pdo->prepare($sql);
$st->execute($params);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) { http_response_code(404); echo "No encontrado."; exit; }

$fecha = $createdCol ? ($row["created_time"] ?? "") : "";
$motivo = $row["motivo"] ?? "";
$metodo = $row["metodo_pago"] ?? "efectivo";
$amount = abs((float)($row["amount"] ?? 0));
$rep = $hasRep ? ($row["representante"] ?? "") : "";
$createdBy = (int)($row["created_by"] ?? 0);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CEVIMEP | Acuse de desembolso #<?php echo (int)$row["id"]; ?></title>
  <style>
    :root{ --bg:#0b1220; --card:#0f172a; --ink:#0f172a; --muted:#64748b; --border:#e5e7eb; }
    body{ margin:0; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial; background:#f3f4f6; color:#111827; }
    .wrap{ max-width:720px; margin:24px auto; padding:0 14px; }
    .card{ background:#fff; border:1px solid var(--border); border-radius:14px; padding:18px; box-shadow:0 10px 25px rgba(0,0,0,.06); }
    .top{ display:flex; align-items:flex-start; justify-content:space-between; gap:12px; }
    .title{ font-size:18px; font-weight:900; letter-spacing:.2px; margin:0; }
    .sub{ color:var(--muted); font-weight:700; margin-top:4px; font-size:12px; }
    .pill{ display:inline-flex; align-items:center; gap:8px; background:#eff6ff; color:#1d4ed8; padding:6px 10px; border-radius:999px; font-weight:900; font-size:12px; }
    .grid{ margin-top:14px; display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .item{ border:1px solid var(--border); border-radius:12px; padding:10px 12px; }
    .k{ font-size:11px; color:var(--muted); font-weight:900; text-transform:uppercase; letter-spacing:.6px; }
    .v{ margin-top:6px; font-size:14px; font-weight:900; }
    .amt{ font-size:20px; }
    .actions{ margin-top:14px; display:flex; gap:10px; }
    .btn{ border:1px solid var(--border); background:#111827; color:#fff; padding:10px 12px; border-radius:12px; font-weight:900; cursor:pointer; }
    .btn2{ border:1px solid var(--border); background:#fff; color:#111827; }
    @media print{
      body{ background:#fff; }
      .wrap{ margin:0; max-width:none; padding:0; }
      .actions{ display:none !important; }
      .card{ box-shadow:none; border:1px solid #ddd; border-radius:10px; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card" id="acuse">
      <div class="top">
        <div>
          <h1 class="title">Acuse de Desembolso</h1>
          <div class="sub">CEVIMEP • Movimiento #<?php echo (int)$row["id"]; ?> <?php echo $fecha ? "• ".h($fecha) : ""; ?></div>
        </div>
        <div class="pill">DESembolso</div>
      </div>

      <div class="grid">
        <div class="item">
          <div class="k">Motivo</div>
          <div class="v"><?php echo h($motivo); ?></div>
        </div>

        <div class="item">
          <div class="k">Monto</div>
          <div class="v amt">RD$ <?php echo fmtMoney($amount); ?></div>
        </div>

        <div class="item">
          <div class="k">Método de pago</div>
          <div class="v"><?php echo h($metodo); ?></div>
        </div>

        <?php if ($hasRep): ?>
        <div class="item">
          <div class="k">Representante</div>
          <div class="v"><?php echo h($rep); ?></div>
        </div>
        <?php endif; ?>

        <div class="item">
          <div class="k">Usuario</div>
          <div class="v">#<?php echo $createdBy; ?></div>
        </div>

        <?php if ($hasBranch): ?>
        <div class="item">
          <div class="k">Sucursal</div>
          <div class="v">#<?php echo (int)($row["branch_id"] ?? 0); ?></div>
        </div>
        <?php endif; ?>
      </div>

      <div class="actions">
        <button class="btn" onclick="window.print()">Imprimir</button>
        <button class="btn btn2" onclick="window.close()">Cerrar</button>
      </div>
    </div>
  </div>

  <script>
    // Al abrir desde "Guardar e Imprimir", imprime automáticamente
    window.addEventListener('load', () => {
      setTimeout(() => { window.print(); }, 250);
    });
  </script>
</body>
</html>
