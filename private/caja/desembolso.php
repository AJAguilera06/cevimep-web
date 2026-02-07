<?php
declare(strict_types=1);

require_once __DIR__ . '/../_guard.php'; // valida sesión y carga $pdo (PDO)

$user = $_SESSION['user'] ?? [];
$hechoPor = $user['full_name'] ?? $user['nombre'] ?? $user['name'] ?? 'Usuario';
$usuario_id = $user['id'] ?? ($_SESSION['user_id'] ?? null);

$hoy  = date('Y-m-d');
$horaActual = date('H:i');

$mensaje = '';
$tipo_mensaje = 'info';
$print_payload = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fecha  = $_POST['fecha'] ?? $hoy;
    $hora   = $_POST['hora'] ?? $horaActual;
    $monto  = (float)($_POST['monto'] ?? 0);
    $motivo = trim((string)($_POST['motivo'] ?? ''));

    if ($monto > 0 && $motivo !== '' && $usuario_id) {
        try {
            // 1) Intento con columnas nuevas (si existen): motivo, hora
            $stmt = $pdo->prepare("
                INSERT INTO caja_desembolsos (fecha, hora, monto, motivo, usuario_id)
                VALUES (:fecha, :hora, :monto, :motivo, :usuario_id)
            ");
            $stmt->execute([
                ':fecha' => $fecha,
                ':hora' => $hora,
                ':monto' => $monto,
                ':motivo' => $motivo,
                ':usuario_id' => $usuario_id,
            ]);
            $id = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            // 2) Fallback a estructura vieja: descripcion
            $descripcion = ($hora ? ("Hora: {$hora} | ") : '') . $motivo;

            $stmt = $pdo->prepare("
                INSERT INTO caja_desembolsos (fecha, monto, descripcion, usuario_id)
                VALUES (:fecha, :monto, :descripcion, :usuario_id)
            ");
            $stmt->execute([
                ':fecha' => $fecha,
                ':monto' => $monto,
                ':descripcion' => $descripcion,
                ':usuario_id' => $usuario_id,
            ]);
            $id = (int)$pdo->lastInsertId();
        }

        $mensaje = "✅ Desembolso registrado correctamente.";
        $tipo_mensaje = "success";

        // Datos para imprimir (sin necesidad de otro archivo)
        $print_payload = [
            'id' => $id,
            'fecha' => $fecha,
            'hora' => $hora,
            'monto' => $monto,
            'motivo' => $motivo,
            'hecho_por' => $hechoPor,
        ];
    } else {
        $mensaje = "⚠️ Completa Motivo y Monto (mayor a 0).";
        $tipo_mensaje = "warning";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Caja | Registrar Desembolso</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <link rel="stylesheet" href="/assets/css/styles.css?v=50">
</head>
<body>

<!-- TOPBAR (mismo diseño dashboard.php) -->
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

    <!-- SIDEBAR (mismo diseño dashboard.php) -->
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

    <!-- CONTENIDO -->
    <main class="content">

        <div class="welcome-center" style="text-align:left; max-width: 900px;">
            <h1 style="margin-bottom:6px;">💸 Registrar Desembolso</h1>
            <p style="margin-top:0;">
                Hecho por: <strong><?= htmlspecialchars($hechoPor) ?></strong>
                &nbsp;•&nbsp;
                <a href="/private/caja/historial_desembolso.php">Ver historial</a>
            </p>
        </div>

        <?php if ($mensaje): ?>
            <div class="card" style="max-width:900px; margin: 14px auto; padding: 14px;">
                <div class="<?= htmlspecialchars($tipo_mensaje) ?>">
                    <?= htmlspecialchars($mensaje) ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card" style="max-width:900px; margin: 0 auto; padding: 18px;">
            <form method="POST" style="display:grid; gap:14px;">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px;">
                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:6px;">Fecha</label>
                        <input type="date" name="fecha" value="<?= htmlspecialchars($hoy) ?>" readonly style="width:100%; padding:10px;">
                    </div>

                    <div>
                        <label style="display:block; font-weight:600; margin-bottom:6px;">Hora</label>
                        <input type="time" name="hora" value="<?= htmlspecialchars($horaActual) ?>" readonly style="width:100%; padding:10px;">
                    </div>
                </div>

                <div>
                    <label style="display:block; font-weight:600; margin-bottom:6px;">Motivo</label>
                    <textarea name="motivo" rows="3" required placeholder="Ej: Pago suplidor, combustible, mensajería..."
                              style="width:100%; padding:10px; resize:vertical;"></textarea>
                </div>

                <div>
                    <label style="display:block; font-weight:600; margin-bottom:6px;">Monto (RD$)</label>
                    <input type="number" name="monto" step="0.01" min="0" required style="width:100%; padding:10px;">
                </div>

                <div>
                    <label style="display:block; font-weight:600; margin-bottom:6px;">Hecho por</label>
                    <input type="text" value="<?= htmlspecialchars($hechoPor) ?>" readonly style="width:100%; padding:10px;">
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top: 6px;">
                    <button type="submit" class="btn-pill" style="padding: 10px 16px;">
                        Guardar e Imprimir
                    </button>
                </div>
            </form>
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
    body{font-family: Arial, sans-serif; margin: 20px;}
    .box{max-width: 420px; margin: 0 auto; border: 1px solid #ddd; padding: 16px;}
    h2{margin:0 0 10px 0; font-size:18px; text-align:center;}
    .row{display:flex; justify-content:space-between; margin:6px 0;}
    .label{font-weight:600;}
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
    <div class="row"><div class="label">Hecho por</div><div>${escapeHtml(data.hecho_por)}</div></div>
    <hr/>
    <div class="row"><div class="label">Motivo</div><div style="max-width:240px; text-align:right;">${escapeHtml(data.motivo)}</div></div>
    <div class="row"><div class="label">Monto</div><div><strong>RD$ ${Number(data.monto).toFixed(2)}</strong></div></div>
    <div class="small">Gracias. Documento interno.</div>
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
