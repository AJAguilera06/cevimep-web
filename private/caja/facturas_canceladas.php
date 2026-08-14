<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
require_once __DIR__ . "/caja_helpers.php";

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
date_default_timezone_set("America/Santo_Domingo");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtMoney($n){ return number_format((float)$n, 2, ".", ","); }

function isAdminRole(string $role): bool {
    $r = mb_strtolower(trim($role));
    return in_array($r, ['administrador', 'admin', 'superadmin'], true);
}

$user = $_SESSION["user"] ?? [];
$role = (string)($user["role"] ?? "");
$branchId = isAdminRole($role)
    ? (int)($_SESSION['selected_branch_id'] ?? ($user["branch_id"] ?? 0))
    : (int)($user["branch_id"] ?? 0);

if ($branchId <= 0) {
    die("Sucursal inválida. Selecciona una sucursal en Facturación primero.");
}

$month = trim((string)($_GET["month"] ?? date("Y-m")));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date("Y-m");
}

$branchName = "";
try {
    $st = $pdo->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
    $st->execute([$branchId]);
    $branchName = (string)($st->fetchColumn() ?: "");
} catch (Throwable $e) {}

$rows = [];
$totalAnulado = 0.0;

try {
    $sql = "
        SELECT
            i.id,
            i.invoice_code,
            i.total,
            i.cancelled_at,
            i.cancel_reason,
            i.cancelled_by,
            i.patient_id,
            COALESCE(u.full_name, u.name, CONCAT('Usuario #', i.cancelled_by)) AS cancelled_by_name
        FROM invoices i
        LEFT JOIN users u ON u.id = i.cancelled_by
        WHERE i.branch_id = ?
          AND i.status = 'CANCELLED'
          AND DATE_FORMAT(i.cancelled_at, '%Y-%m') = ?
        ORDER BY i.cancelled_at DESC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$branchId, $month]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    // Fallback sin join a users, por si la tabla/columnas difieren.
    try {
        $sql = "
            SELECT id, invoice_code, total, cancelled_at, cancel_reason, cancelled_by, patient_id
            FROM invoices
            WHERE branch_id = ?
              AND status = 'CANCELLED'
              AND DATE_FORMAT(cancelled_at, '%Y-%m') = ?
            ORDER BY cancelled_at DESC
        ";
        $st = $pdo->prepare($sql);
        $st->execute([$branchId, $month]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) { $r["cancelled_by_name"] = "Usuario #" . (int)($r["cancelled_by"] ?? 0); }
        unset($r);
    } catch (Throwable $e2) {
        $rows = [];
    }
}

foreach ($rows as $r) {
    $totalAnulado += (float)($r["total"] ?? 0);
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Facturas canceladas</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=110">
  <style>
    .page-wrap{max-width: 1180px; width:100%; margin: 0 auto;}
    .card-soft{background:#fff;border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:18px;}
    .head-row{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:10px;flex-wrap:wrap;}
    .title{margin:0;font-size:40px;line-height:1.1;}
    .sub{margin:6px 0 0 0;opacity:.85;}
    .muted{opacity:.75;font-weight:700;}
    .btn-ghost{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:999px;background:#fff;color:#0b5ed7!important;border:2px solid rgba(11,94,215,.35);font-weight:800;text-decoration:none;}
    .btn-ghost:hover{background:rgba(11,94,215,.06);}
    .filter-row{display:flex;gap:10px;align-items:center;margin-bottom:14px;flex-wrap:wrap;}
    .filter-row input{padding:10px 12px;border-radius:12px;border:1px solid #d9d9d9;outline:none;}
    .filter-row button{border:0;border-radius:999px;padding:10px 16px;font-weight:900;color:#fff;background:#0b5ed7;cursor:pointer;}
    table{width:100%;border-collapse:collapse;}
    th,td{padding:10px 10px;border-bottom:1px solid #eef2f6;font-size:13px;text-align:left;}
    th{color:#0b4d87;font-weight:900;font-size:12px;}
    .money{font-weight:900;white-space:nowrap;}
    .totalbox{margin-top:14px;text-align:right;font-weight:900;font-size:16px;}
    .empty{text-align:center;padding:24px;opacity:.7;font-weight:700;}
    @media(max-width:980px){.title{font-size:34px}}
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
      <a href="#" onclick="return false;" style="opacity:.5;cursor:not-allowed;">📅 Citas (Próximamente)</a>
      <a href="/private/facturacion/index.php">🧾 Facturación</a>
      <a class="active" href="/private/caja/index.php">💳 Caja</a>
      <a href="/private/inventario/index.php">📦 Inventario</a>
      <a href="/private/estadistica/index.php">📊 Estadísticas</a>
    </nav>
  </aside>

  <main class="content">
    <div class="page-wrap">
      <div class="head-row">
        <div>
          <h1 class="title">Facturas canceladas</h1>
          <p class="sub"><span class="muted">Sucursal:</span> <?= h($branchName ?: '—') ?></p>
        </div>
        <a class="btn-ghost" href="/private/caja/index.php">⬅ Volver a Caja</a>
      </div>

      <div class="card-soft">
        <form method="get" class="filter-row">
          <label class="muted">Mes:</label>
          <input type="month" name="month" value="<?= h($month) ?>">
          <button type="submit">Filtrar</button>
        </form>

        <?php if (empty($rows)): ?>
          <div class="empty">No hay facturas anuladas en este mes.</div>
        <?php else: ?>
          <table>
            <thead>
              <tr>
                <th>Factura</th>
                <th>Total</th>
                <th>Anulada el</th>
                <th>Anulada por</th>
                <th>Motivo</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?= h($r["invoice_code"] ?? ('#' . $r["id"])) ?></td>
                  <td class="money">RD$ <?= fmtMoney($r["total"] ?? 0) ?></td>
                  <td><?= h($r["cancelled_at"] ?? '') ?></td>
                  <td><?= h($r["cancelled_by_name"] ?? '') ?></td>
                  <td><?= h($r["cancel_reason"] ?? '') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div class="totalbox">Total anulado en el mes: RD$ <?= fmtMoney($totalAnulado) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>

<footer class="footer">© <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.</footer>
</body>
</html>