<?php
declare(strict_types=1);

// Zona horaria RD (GMT-4)
date_default_timezone_set('America/Santo_Domingo');

require_once __DIR__ . '/../_guard.php'; // tu _guard.php en /private/_guard.php

// _guard.php debe cargar $pdo (PDO). Si tu variable es $db, hacemos alias:
if (isset($db) && !isset($pdo) && $db instanceof PDO) { $pdo = $db; }
if (!isset($pdo) || !($pdo instanceof PDO)) {
  http_response_code(500);
  die("Error: no hay conexión PDO disponible (\$pdo).");
}

$hoy = date('Y-m-d');
$horaActual = date('H:i');

$mensaje = '';
$tipo_mensaje = 'info';
$print_payload = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fecha    = $_POST['fecha'] ?? $hoy;
    $hora     = $_POST['hora'] ?? $horaActual;
    $monto    = (float)($_POST['monto'] ?? 0);
    $motivo   = trim((string)($_POST['motivo'] ?? ''));
    $hechoPor = trim((string)($_POST['hecho_por'] ?? ''));

    if ($monto > 0 && $motivo !== '') {
        try {
            $created_at = "{$fecha} {$hora}:00";

            // Intento 1: esquema esperado (como tu historial viejo)
            $stmt = $pdo->prepare("
              INSERT INTO cash_movements (type, motivo, amount, created_at, created_by)
              VALUES (:type, :motivo, :amount, :created_at, :created_by)
            ");
            $stmt->execute([
              ':type' => 'desembolso',
              ':motivo' => $motivo,
              ':amount' => $monto,
              ':created_at' => $created_at,
              ':created_by' => $hechoPor,
            ]);
            $id = (int)$pdo->lastInsertId();

            $mensaje = "✅ Desembolso registrado correctamente.";
            $tipo_mensaje = "success";

            $print_payload = [
                'id' => $id,
                'fecha' => $fecha,
                'hora' => $hora,
                'monto' => $monto,
                'motivo' => $motivo,
                'hecho_por' => $hechoPor,
            ];
        } catch (Throwable $e1) {
            try {
                // Intento 2: si created_at tiene default
                $stmt = $pdo->prepare("
                  INSERT INTO cash_movements (type, motivo, amount, created_by)
                  VALUES (:type, :motivo, :amount, :created_by)
                ");
                $stmt->execute([
                  ':type' => 'desembolso',
                  ':motivo' => $motivo,
                  ':amount' => $monto,
                  ':created_by' => $hechoPor,
                ]);
                $id = (int)$pdo->lastInsertId();

                $mensaje = "✅ Desembolso registrado correctamente.";
                $tipo_mensaje = "success";

                $print_payload = [
                    'id' => $id,
                    'fecha' => $fecha,
                    'hora' => $hora,
                    'monto' => $monto,
                    'motivo' => $motivo,
                    'hecho_por' => $hechoPor,
                ];
            } catch (Throwable $e2) {
                // Modo debug: muestra el error real para que sepamos el campo exacto
                $mensaje = "❌ Error al guardar el desembolso: " . $e2->getMessage();
                $tipo_mensaje = "error";
            }
        }
    } else {
        $mensaje = "⚠️ Completa Motivo y Monto (mayor a 0).";
        $tipo_mensaje = "warning";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Caja | Registrar Desembolso</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=70">
  <style>
    .page-wrap{max-width: 980px; margin: 0 auto;}
    .page-head{display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom: 10px;}
    .page-head h1{margin:0; font-size: 40px; line-height: 1.1;}
    .subhead{margin:6px 0 0 0; opacity:.85}
    .card-soft{background:#fff; border-radius: 18px; box-shadow: 0 10px 30px rgba(0,0,0,.08); padding: 18px;}
    .grid-2{display:grid; grid-template-columns: 1fr 1fr; gap:14px;}
    .grid-1{display:grid; grid-template-columns: 1fr; gap:14px;}
    label{display:block; font-weight:700; margin-bottom:6px;}
    input, textarea{width:100%; padding: 12px 12px; border:1px solid #d9d9d9; border-radius: 12px; outline:none;}
    input[readonly]{background:#f7f7f7;}
    textarea{resize: vertical;}
    .btn-row{display:flex; justify-content:flex-end; gap:10px; margin-top: 6px;}
    .hint{font-size:12px; opacity:.75; margin-top:6px;}
    .alertbox{border-radius:14px; padding:12px 14px; margin: 12px 0;}
    .alertbox.success{background:#eaf8ef; border:1px solid #bfe7c9;}
    .alertbox.warning{background:#fff6df; border:1px solid #f0d08a;}
    .alertbox.error{background:#ffe9e9; border:1px solid #f3b2b2; white-space:pre-wrap;}
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
          <h1>💸 Registrar Desembolso</h1>
          <p class="subhead">Registra un desembolso y genera el comprobante para imprimir.</p>
        </div>
        <a href="/private/caja/historial_desembolso.php" class="btn-pill">📄 Ver historial</a>
      </div>

      <?php if ($mensaje): ?>
        <div class="alertbox <?= htmlspecialchars($tipo_mensaje) ?>">
          <?= htmlspecialchars($mensaje) ?>
        </div>
      <?php endif; ?>

      <div class="card-soft">
        <form method="POST" class="grid-1">

          <div class="grid-2">
            <div>
              <label>Fecha</label>
              <input type="date" name="fecha" value="<?= htmlspecialchars($hoy) ?>" readonly>
            </div>
            <div>
              <label>Hora (GMT-4)</label>
              <input type="time" name="hora" value="<?= htmlspecialchars($horaActual) ?>" readonly>
            </div>
          </div>

          <div class="grid-2">
            <div>
              <label>Monto (RD$)</label>
              <input type="number" name="monto" step="0.01" min="0" required placeholder="Ej: 1500.00">
            </div>
            <div>
              <label>Hecho por</label>
              <input type="text" name="hecho_por" placeholder="Ej: Juan Pérez / Caja Santiago">
              <div class="hint">Este campo queda vacío si no lo llenas.</div>
            </div>
          </div>

          <div>
            <label>Motivo</label>
            <textarea name="motivo" rows="3" required placeholder="Ej: Pago suplidor, combustible, mensajería..."></textarea>
          </div>

          <div class="btn-row">
            <button type="submit" class="btn-pill" style="padding:12px 16px; font-weight:800;">Guardar e Imprimir</button>
          </div>

        </form>
      </div>

    </div>
  </main>
</div>

<footer class="footer">
  © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

<?php if ($print_payload): ?>
<script>
(function () {
  const data = <?= json_encode($print_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  const html = `
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Imprimir Desembolso #${data.id}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family: Arial, sans-serif; margin: 16px;}
    .box{max-width: 420px; margin: 0 auto; border: 1px solid #ddd; padding: 14px; border-radius: 10px;}
    h2{margin:0 0 10px 0; font-size:18px; text-align:center;}
    .row{display:flex; justify-content:space-between; margin:6px 0; gap:10px;}
    .label{font-weight:700;}
    .small{font-size:12px; color:#444; text-align:center; margin-top:10px;}
    hr{border:none; border-top:1px dashed #bbb; margin:12px 0;}
  </style>
</head>
<body>
  <div class="box">
    <h2>CEVIMEP - Desembolso</h2>
    <hr/>
    <div class="row"><div class="label">No.</div><div>#${data.id}</div></div>
    <div class="row"><div class="label">Fecha</div><div>${data.fecha}</div></div>
    <div class="row"><div class="label">Hora</div><div>${data.hora}</div></div>
    <div class="row"><div class="label">Hecho por</div><div>${escapeHtml(data.hecho_por || '')}</div></div>
    <hr/>
    <div class="row"><div class="label">Motivo</div><div style="max-width:240px; text-align:right;">${escapeHtml(data.motivo)}</div></div>
    <div class="row"><div class="label">Monto</div><div><strong>RD$ ${Number(data.monto).toFixed(2)}</strong></div></div>
    <div class="small">Documento interno.</div>
  </div>
  <script>
    window.onload = () => { window.print(); setTimeout(() => window.close(), 300); };
  <\/script>
</body>
</html>`;

  const w = window.open('', '_blank', 'width=520,height=720');
  if (w) {
    w.document.open();
    w.document.write(html);
    w.document.close();
  }

  function escapeHtml(str){
    return String(str)
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }
})();
</script>
<?php endif; ?>

</body>
</html>
