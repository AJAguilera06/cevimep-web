<?php
declare(strict_types=1);

// Zona horaria RD
date_default_timezone_set('America/Santo_Domingo');

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/caja_helpers.php';

$hoy = date('Y-m-d');
$horaActual24 = date('H:i');

$mensaje = '';
$tipo_mensaje = 'info';

// Obtener branch_id
$branch_id = caja_require_branch_id();
$created_by = (int)($_SESSION['user']['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $monto_in = (float)($_POST['monto'] ?? 0);
    $motivo   = trim((string)($_POST['motivo'] ?? ''));
    $hechoPor = trim((string)($_POST['hecho_por'] ?? ''));

    if ($branch_id <= 0) {
        $mensaje = "⚠️ No se encontró la sucursal (branch_id).";
        $tipo_mensaje = "warning";

    } elseif (!($monto_in > 0) || $motivo === '') {
        $mensaje = "⚠️ Completa Motivo y Monto (mayor a 0).";
        $tipo_mensaje = "warning";

    } else {

        // 🔥 Crear / obtener sesión automática
        $caja_sesion_id = caja_get_or_create_session_id($pdo, $branch_id);

        if ($caja_sesion_id <= 0) {
            $mensaje = "⚠️ No se encontró/creó una sesión de caja válida (caja_sesion_id).";
            $tipo_mensaje = "warning";
        } else {

            try {

                $amount = -abs($monto_in); // desembolso negativo

                $motivo_db = $hechoPor !== ''
                    ? "Hecho por: {$hechoPor} | {$motivo}"
                    : $motivo;

                $sql = "INSERT INTO cash_movements
                        (branch_id, caja_sesion_id, type, motivo, metodo_pago, amount, created_by)
                        VALUES
                        (:branch_id, :caja_sesion_id, 'desembolso', :motivo, 'efectivo', :amount, :created_by)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':branch_id' => $branch_id,
                    ':caja_sesion_id' => $caja_sesion_id,
                    ':motivo' => $motivo_db,
                    ':amount' => $amount,
                    ':created_by' => $created_by,
                ]);

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
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Caja | Registrar Desembolso</title>
<link rel="stylesheet" href="/assets/css/styles.css?v=100">
<style>
.page-wrap{max-width:980px;margin:0 auto;}
.card-soft{background:#fff;border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:18px;}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.grid-1{display:grid;grid-template-columns:1fr;gap:14px;}
label{font-weight:700;margin-bottom:6px;display:block;}
input,textarea{width:100%;padding:12px;border:1px solid #d9d9d9;border-radius:12px;}
textarea{resize:vertical;}
.btn-row{display:flex;justify-content:flex-end;margin-top:10px;}
.btn-strong{padding:10px 18px;border-radius:999px;background:#0b5ed7;color:#fff;font-weight:800;border:none;}
.alertbox{border-radius:14px;padding:12px;margin:12px 0;}
.alertbox.success{background:#eaf8ef;}
.alertbox.warning{background:#fff6df;}
.alertbox.error{background:#ffe9e9;}
</style>
</head>
<body>

<div class="page-wrap">

<h1>💸 Registrar Desembolso</h1>

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
<input type="date" value="<?= htmlspecialchars($hoy) ?>" readonly>
</div>
<div>
<label>Hora (GMT-4)</label>
<input type="time" value="<?= htmlspecialchars($horaActual24) ?>" readonly>
</div>
</div>

<div class="grid-2">
<div>
<label>Monto (RD$)</label>
<input type="number" name="monto" step="0.01" min="0" required>
</div>
<div>
<label>Hecho por</label>
<input type="text" name="hecho_por">
</div>
</div>

<div>
<label>Motivo</label>
<textarea name="motivo" rows="3" required></textarea>
</div>

<div class="btn-row">
<button type="submit" class="btn-strong">🖨️ Guardar e Imprimir</button>
</div>

</form>
</div>

</div>

<?php if ($print_id > 0): ?>
<script>
window.open("/private/caja/acuse_desembolso.php?id=<?= (int)$print_id ?>","_blank");
if (window.history && window.history.replaceState) {
window.history.replaceState({}, document.title, "/private/caja/desembolso.php");
}
</script>
<?php endif; ?>

</body>
</html>
