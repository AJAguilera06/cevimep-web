<?php
declare(strict_types=1);

date_default_timezone_set('America/Santo_Domingo');

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/caja_helpers.php';

if (isset($db) && !isset($pdo) && $db instanceof PDO) {
  $pdo = $db;
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  die("Error: no hay conexión PDO disponible (\$pdo).");
}

$rows = [];
$error = '';

try {

  // ✅ Sucursal actual del usuario
  $branch_id = (int)caja_require_branch_id();

  // ✅ Mostrar SOLO desembolsos de esa sucursal
  $stmt = $pdo->prepare("
    SELECT id, motivo, amount, created_at, created_by
    FROM cash_movements
    WHERE type='desembolso'
    AND branch_id = :branch_id
    ORDER BY id DESC
    LIMIT 500
  ");

  $stmt->execute([
    ':branch_id' => $branch_id
  ]);

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

function to_date(string $dt): string {
  return $dt !== '' ? substr($dt, 0, 10) : '';
}

function to_time(string $dt): string {
  return $dt !== '' ? substr($dt, 11, 5) : '';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Caja | Historial de Desembolsos</title>

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

    .page-wrap{
      max-width:1200px;
      margin:0 auto;
    }

    .page-head{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:16px;
      margin-bottom:10px;
    }

    .page-head h1{
      margin:0;
      font-family:'Manrope';
      font-size:40px;
      font-weight:900;
      line-height:1.1;
    }

    .card-soft{
      background:rgba(255,255,255,.07);
      backdrop-filter:blur(14px);
      -webkit-backdrop-filter:blur(14px);
      border:1px solid rgba(255,255,255,.12);
      border-radius:18px;
      padding:14px;
    }

    .table-wrap{
      overflow:auto;
    }

    table{
      width:100%;
      border-collapse:collapse;
      min-width:900px;
    }

    th, td{
      padding:10px;
      border-bottom:1px solid rgba(255,255,255,.08);
      color:#E7EEF6;
    }

    th{
      font-weight:800;
      text-align:left;
      background:rgba(20,184,166,.1);
      color:var(--teal);
    }

    td.amount{
      text-align:right;
      font-weight:800;
    }

    .muted{
      opacity:.78;
      color:#9FB6CC;
    }

    .alertbox{
      border-radius:14px;
      padding:12px 14px;
      margin:12px 0;
      background:rgba(220,38,38,.12);
      border:1px solid rgba(220,38,38,.3);
      color:#f87171;
      white-space:pre-wrap;
      font-weight:700;
    }

    .btn-ghost{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:10px 14px;
      border-radius:999px;
      background:rgba(20,184,166,.1);
      color:var(--teal) !important;
      border:1px solid rgba(20,184,166,.35);
      font-weight:800;
      text-decoration:none;
    }

    .btn-ghost:hover{
      background:rgba(20,184,166,.2);
    }

    @media (max-width: 980px){
      .layout{ flex-direction:column; }
      .sidebar{ width:100%; }
      .content{ padding:24px 18px 40px; }
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

          <p class="muted" style="margin:6px 0 0 0;">
            Listado de los desembolsos registrados en Caja.
          </p>
        </div>

        <a href="/private/caja/desembolso.php" class="btn-ghost">
          ➕ Nuevo desembolso
        </a>

      </div>

      <?php if ($error): ?>
        <div class="alertbox">
          <?= htmlspecialchars($error) ?>
        </div>
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

                  $monto = abs((float)($r['amount'] ?? 0));
                ?>

                <tr>

                  <td>
                    <?= htmlspecialchars(to_date((string)($r['created_at'] ?? ''))) ?>
                  </td>

                  <td>
                    <?= htmlspecialchars(to_time((string)($r['created_at'] ?? ''))) ?>
                  </td>

                  <td>
                    <?= htmlspecialchars($motivo) ?>
                  </td>

                  <td class="amount">
                    RD$ <?= number_format($monto, 2) ?>
                  </td>

                  <td>
                    <?= htmlspecialchars($hecho_por ?: '—') ?>
                  </td>

                  <td>
                    <a
                      class="btn-ghost"
                      target="_blank"
                      href="/private/caja/acuse_desembolso.php?id=<?= $id ?>"
                    >
                      🖨️ Imprimir
                    </a>
                  </td>

                </tr>

              <?php endforeach; ?>

            <?php else: ?>

              <tr>
                <td colspan="6" style="text-align:center; padding:14px;" class="muted">
                  No hay desembolsos registrados
                </td>
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