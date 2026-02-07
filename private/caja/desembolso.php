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

$hoy = date('Y-m-d');
$horaActual = date('H:i');

$mensaje = '';
$tipo_mensaje = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fecha    = $_POST['fecha'] ?? $hoy;
    $hora     = $_POST['hora'] ?? $horaActual;
    $monto    = (float)($_POST['monto'] ?? 0);
    $motivo   = trim((string)($_POST['motivo'] ?? ''));
    $hechoPor = trim((string)($_POST['hecho_por'] ?? ''));

    // En tu BD, created_by ES INT (lo confirmó el error)
    $created_by = (int)($_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0));

    if ($monto > 0 && $motivo !== '') {
        try {
            $created_at = "{$fecha} {$hora}:00";

            // Guardamos el "Hecho por" escrito en el campo, dentro de motivo (para no cambiar BD)
            $motivo_db = $hechoPor !== '' ? ("Hecho por: {$hechoPor} | {$motivo}") : $motivo;

            $stmt = $pdo->prepare("
              INSERT INTO cash_movements (type, motivo, amount, created_at, created_by)
              VALUES (:type, :motivo, :amount, :created_at, :created_by)
            ");
            $stmt->execute([
              ':type' => 'desembolso',
              ':motivo' => $motivo_db,
              ':amount' => $monto,
              ':created_at' => $created_at,
              ':created_by' => $created_by,
            ]);

            $id = (int)$pdo->lastInsertId();

            // Abrir acuse en una NUEVA pestaña y luego recargar la pantalla limpia
            header("Location: /private/caja/desembolso.php?ok=1&print_id={$id}");
            exit;
        } catch (Throwable $e) {
            $mensaje = "❌ Error al guardar el desembolso: " . $e->getMessage();
            $tipo_mensaje = "error";
        }
    } else {
        $mensaje = "⚠️ Completa Motivo y Monto (mayor a 0).";
        $tipo_mensaje = "warning";
    }
}

// Si viene print_id, abrimos el acuse en nueva pestaña con JS
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
  <link rel="stylesheet" href="/assets/css/styles.css?v=80">
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

    /* Botones más visibles */
    .btn-strong{
      display:inline-flex; align-items:center; gap:8px;
      padding: 10px 14px; border-radius: 999px;
      background: #0b5ed7; color:#fff !important;
      border: 1px solid rgba(0,0,0,.12);
      font-weight: 800; text-decoration:none;
      box-shadow: 0 8px 18px rgba(11,94,215,.18);
    }
    .btn-strong:hover{filter: brightness(0.96);}
    .btn-ghost{
      display:inline-flex; align-items:center; gap:8px;
      padding: 10px 14px; border-radius: 999px;
      background:#fff; color:#0b5ed7 !important;
      border: 2px solid rgba(11,94,215,.35);
      font-weight: 800; text-decoration:none;
    }
    .btn-ghost:hover{background: rgba(11,94,215,.06);}

    .hint{font-size:12px; opacity:.75; margin-top:6px;}
    .alertbox{border-radius:14px; padding:12px 14px; margin: 12px 0; white-space:pre-wrap;}
    .alertbox.success{background:#eaf8ef; border:1px solid #bfe7c9;}
    .alertbox.warning{background:#fff6df; border:1px solid #f0d08a;}
    .alertbox.error{background:#ffe9e9; border:1px solid #f3b2b2;}
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
          <p class="subhead">Registra un desembolso y genera el acuse para imprimir.</p>
        </div>
        <a href="/private/caja/historial_desembolso.php" class="btn-ghost">📄 Ver historial</a>
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
              <input type="text" name="hecho_por" placeholder="Ej: Claudia Peña / Caja Santiago">
              <div class="hint">Déjalo vacío si no lo quieres en el motivo (recomendado para el acuse).</div>
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
  // Abrir el acuse en nueva pestaña (después de guardar)
  window.open("/private/caja/acuse_desembolso.php?id=<?= (int)$print_id ?>", "_blank");
  // Limpiar la URL para que no vuelva a abrir al recargar
  if (window.history && window.history.replaceState) {
    window.history.replaceState({}, document.title, "/private/caja/desembolso.php");
  }
</script>
<?php endif; ?>

</body>
</html>
