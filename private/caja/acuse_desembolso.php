<?php
declare(strict_types=1);

date_default_timezone_set('America/Santo_Domingo');
require_once __DIR__ . '/../_guard.php';

if (isset($db) && !isset($pdo) && $db instanceof PDO) { $pdo = $db; }
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  die("Error: no hay conexión PDO disponible (\$pdo).");
}


function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

function colExists(PDO $conn, string $table, string $col): bool {
  try {
    $st = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    try {
      $st = $conn->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ? LIMIT 1");
      $st->execute([$table, $col]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e2) {
      return false;
    }
  }
}

function desembolsoPrefixByBranch(int $branchId): string {
  $map = [
    1 => "M",
    2 => "L",
    3 => "SC",
    4 => "S",
    5 => "V",
    6 => "P",
  ];

  return $map[$branchId] ?? "D";
}

function buildDesembolsoCode(int $branchId, int $number): string {
  return desembolsoPrefixByBranch($branchId) . "-D-" . str_pad((string)$number, 7, "0", STR_PAD_LEFT);
}


$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); die("ID inválido."); }

$extraSelect = "";
if (colExists($pdo, "cash_movements", "desembolso_number")) {
  $extraSelect .= ", desembolso_number";
}
if (colExists($pdo, "cash_movements", "desembolso_code")) {
  $extraSelect .= ", desembolso_code";
}

$stmt = $pdo->prepare("
  SELECT id, branch_id, type, motivo, amount, created_at, created_by {$extraSelect}
  FROM cash_movements
  WHERE id = :id AND type='desembolso'
  LIMIT 1
");
$stmt->execute([':id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); die("No se encontró el desembolso."); }

$motivo_raw = (string)($row['motivo'] ?? '');
$hecho_por = '';
$motivo = $motivo_raw;
if (preg_match('/^Hecho por:\s*(.*?)\s*\|\s*(.*)$/u', $motivo_raw, $m)) {
  $hecho_por = trim($m[1]);
  $motivo = trim($m[2]);
}

$created_at = (string)($row['created_at'] ?? '');
$fecha = substr($created_at, 0, 10);
$hora  = substr($created_at, 11, 5);

// En BD viene negativo, mostramos positivo en el acuse
$monto = abs((float)($row['amount'] ?? 0));

$branch_id = (int)($row['branch_id'] ?? 0);
$desembolso_code = trim((string)($row['desembolso_code'] ?? ""));

if ($desembolso_code === "") {
  $desembolso_number = (int)($row['desembolso_number'] ?? 0);

  if ($desembolso_number <= 0 && $branch_id > 0) {
    // Fallback sin modificar BD: calcula el número correlativo dentro de la sucursal.
    $stN = $pdo->prepare("
      SELECT COUNT(*)
      FROM cash_movements
      WHERE type = 'desembolso'
        AND branch_id = ?
        AND id <= ?
    ");
    $stN->execute([$branch_id, (int)$row['id']]);
    $desembolso_number = (int)$stN->fetchColumn();
  }

  if ($desembolso_number <= 0) {
    $desembolso_number = (int)$row['id'];
  }

  $desembolso_code = buildDesembolsoCode($branch_id, $desembolso_number);
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Acuse de Desembolso <?= h($desembolso_code) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family: Arial, sans-serif; margin: 16px; color:#111;}
    .ticket{max-width: 420px; margin: 0 auto; border: 1px solid #ddd; padding: 14px; border-radius: 10px;}
    h2{margin:0 0 10px 0; font-size:18px; text-align:center;}
    hr{border:none; border-top:1px dashed #bbb; margin:12px 0;}
    .row{display:flex; justify-content:space-between; gap:10px; margin:6px 0;}
    .label{font-weight:700;}
    .right{text-align:right;}
    .small{font-size:12px; color:#444; text-align:center; margin-top:10px;}
    .motivo{margin-top:8px; font-size:13px;}
  </style>
</head>
<body>
  <div class="ticket">
    <h2>CEVIMEP - Acuse de Desembolso</h2>
    <hr/>
    <div class="row"><div class="label">No.</div><div><?= h($desembolso_code) ?></div></div>
    <div class="row"><div class="label">Fecha</div><div><?= htmlspecialchars($fecha) ?></div></div>
    <div class="row"><div class="label">Hora</div><div><?= htmlspecialchars($hora) ?></div></div>
    <div class="row"><div class="label">Hecho por</div><div class="right"><?= htmlspecialchars($hecho_por) ?></div></div>
    <hr/>
    <div class="row"><div class="label">Monto</div><div><strong>RD$ <?= number_format($monto, 2) ?></strong></div></div>
    <div class="motivo"><span class="label">Motivo:</span> <?= htmlspecialchars($motivo) ?></div>
    <div class="small">Documento interno • Impresión automática</div>

    <div style="margin-top:40px;text-align:center;">
      <div style="font-weight:bold;margin-bottom:25px;">
        Recibido por:
      </div>

      <div style="width:240px;margin:0 auto;border-bottom:1px solid #000;height:20px;"></div>

      <div style="margin-top:6px;font-size:12px;color:#555;">
        Firma
      </div>
    </div>
  </div>

  <script>
    window.onload = () => { window.print(); };
  </script>
</body>
</html>
