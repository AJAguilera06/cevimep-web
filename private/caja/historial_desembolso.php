<?php
declare(strict_types=1);

// Zona horaria RD (GMT-4)
date_default_timezone_set('America/Santo_Domingo');

require_once __DIR__ . '/../_guard.php';

// Alias $db -> $pdo si aplica
if (isset($db) && !isset($pdo) && $db instanceof PDO) { $pdo = $db; }
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  die("Error: no hay conexión PDO disponible (\$pdo).");
}

$rows = [];
$error = '';

try {
    $stmt = $pdo->query("
      SELECT id, motivo, amount, created_at, created_by
      FROM cash_movements
      WHERE type='desembolso'
      ORDER BY id DESC
      LIMIT 500
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = "❌ No se pudo cargar el historial: " . $e->getMessage();
}

function parse_motivo(string $motivo_raw): array {
  $hecho_por = '';
  $motivo = $motivo_raw;

  if (preg_match('/^Hecho por:\s*(.*?)\s*\|\s*(.*)$/u', $motivo_raw, $m)) {
    $hecho_por = trim($m[1]);
    $motivo = trim($m[2]);
  }
  return [$motivo, $hecho_por];
}
function to_date(string $dt): string { return $dt !== '' ? substr($dt, 0, 10) : ''; }
function to_time(string $dt): string { return $dt !== '' ? substr($dt, 11, 5) : ''; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Caja | Historial de Desembolsos</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=80">
  <style>
    .page-wrap{max-width: 1200px; margin: 0 auto;}
    .page-head{display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom: 10px;}
    .page-head h1{margin:0; font-size: 40px; line-height: 1.1;}
    .card-soft{background:#fff; border-radius: 18px; box-shadow: 0 10px 30px rgba(0,0,0,.08); padding: 14px;}
    .table-wrap{overflow:auto;}
    table{width:100%; border-collapse: collapse; min-width: 860px;}
    th, td{padding: 10px; border-bottom: 1px solid #f1f1f1;}
    th{font-weight:800; text-align:left; background: rgba(0,0,0,.02);}
    td.amount{text-align:right; font-weight:800;}
    .muted{opacity:.75;}
    .alertbox{border-radius:14px; padding:12px 14px; margin: 12px 0; background:#ffe9e9; border:1px solid #f3b2b2; white-space:pre-wrap;}
    .btn-ghost{
      display:inline-flex; align-items:center; gap:8px;
      padding: 10px 14px; border-radius: 999px;
      background:#fff; color:#0b5ed7 !important;
      border: 2px solid rgba(11,94,215,.35);
      font-weight: 800; text-decoration:none;
    }
    .btn-ghost:hover{background: rgba(11,94,215,.06);}
  </style>
</head>
<body>

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
      <a href="/private/citas/index.php">📅 Citas</a>
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
          <h1>📄 Historial de Desembolsos</h1>
          <p class="muted" style="margin:6px 0 0 0;">Listado de los desembolsos registrados en Caja.</p>
        </div>
        <a href="/private/caja/desembolso.php" class="btn-ghost">➕ Nuevo desembolso</a>
      </div>

      <?php if ($error): ?>
        <div class="alertbox"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="card-soft">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Hora</th>
                <th>Motivo</th>
                <th style="text-align:right;">Monto</th>
                <th>Hecho por</th>
                <th>Acuse</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!empty($rows)): ?>
              <?php foreach ($rows as $r): ?>
                <?php
                  $motivo_raw = (string)($r['motivo'] ?? '');
                  [$motivo, $hecho_por] = parse_motivo($motivo_raw);
                  $id = (int)($r['id'] ?? 0);
                ?>
                <tr>
                  <td><?= htmlspecialchars(to_date((string)($r['created_at'] ?? ''))) ?></td>
                  <td><?= htmlspecialchars(to_time((string)($r['created_at'] ?? ''))) ?></td>
                  <td><?= htmlspecialchars($motivo) ?></td>
                  <td class="amount">RD$ <?= number_format((float)($r['amount'] ?? 0), 2) ?></td>
                  <td><?= htmlspecialchars($hecho_por ?: '—') ?></td>
                  <td>
                    <a class="btn-ghost" target="_blank" href="/private/caja/acuse_desembolso.php?id=<?= $id ?>">🖨️ Imprimir</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="6" style="text-align:center; padding: 14px;">No hay desembolsos registrados</td>
              </tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </main>
</div>

<footer class="footer">
  © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>
