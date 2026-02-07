<?php
declare(strict_types=1);

// Zona horaria RD (GMT-4)
date_default_timezone_set('America/Santo_Domingo');

require_once __DIR__ . '/../_guard.php';

$rows = [];
$error = '';

try {
    // Consulta compatible con tu tabla actual (id, fecha, monto, descripcion, usuario_id)
    $stmt = $pdo->query("
        SELECT id, fecha, monto, descripcion, usuario_id
        FROM caja_desembolsos
        ORDER BY fecha DESC, id DESC
    ");
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = "❌ No se pudo cargar el historial (revisa tabla/campos en BD).";
}

// Función simple para extraer campos desde descripcion
function parse_desc(string $desc): array {
    $out = ['hora'=>'', 'hecho_por'=>'', 'motivo'=>$desc];

    // Hora: HH:MM | Hecho por: X | Motivo: Y
    if (preg_match('/Hora:\s*([0-9]{2}:[0-9]{2})\s*\|\s*Hecho por:\s*(.*?)\s*\|\s*Motivo:\s*(.*)$/u', $desc, $m)) {
        $out['hora'] = $m[1];
        $out['hecho_por'] = trim($m[2]);
        $out['motivo'] = trim($m[3]);
        return $out;
    }

    // Fallback viejo: "Hora: HH:MM | ..."
    if (preg_match('/Hora:\s*([0-9]{2}:[0-9]{2})\s*\|\s*(.*)$/u', $desc, $m)) {
        $out['hora'] = $m[1];
        $out['motivo'] = trim($m[2]);
    }

    return $out;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Caja | Historial de Desembolsos</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=60">
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
    .alertbox{border-radius:14px; padding:12px 14px; margin: 12px 0; background:#ffe9e9; border:1px solid #f3b2b2;}
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
        <a href="/private/caja/desembolso.php" class="btn-pill">➕ Nuevo desembolso</a>
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
              </tr>
            </thead>
            <tbody>
            <?php if (!empty($rows)): ?>
              <?php foreach ($rows as $r): ?>
                <?php
                  $desc = (string)($r['descripcion'] ?? '');
                  $p = parse_desc($desc);
                ?>
                <tr>
                  <td><?= htmlspecialchars((string)($r['fecha'] ?? '')) ?></td>
                  <td><?= htmlspecialchars($p['hora']) ?></td>
                  <td><?= htmlspecialchars($p['motivo']) ?></td>
                  <td class="amount">RD$ <?= number_format((float)($r['monto'] ?? 0), 2) ?></td>
                  <td><?= htmlspecialchars($p['hecho_por'] ?: '—') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="5" style="text-align:center; padding: 14px;">No hay desembolsos registrados</td>
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
