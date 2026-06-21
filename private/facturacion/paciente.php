<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }

function colExists(PDO $conn, string $table, string $col): bool {
    try {
        $db = (string)$conn->query("SELECT DATABASE()")->fetchColumn();
        $st = $conn->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?
        ");
        $st->execute([$db, $table, $col]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function isAdminRole(string $role): bool {
    $r = mb_strtolower(trim($role));
    return in_array($r, ['administrador', 'admin', 'superadmin'], true);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$user = $_SESSION['user'] ?? [];
$nombreSucursal = $user['full_name'] ?? 'CEVIMEP';
$rol = $user['role'] ?? '';
$isAdmin = isAdminRole((string)$rol);
$sucursalId = (int)($user['branch_id'] ?? 0);

if ($sucursalId <= 0) {
    http_response_code(400);
    die("Sucursal inválida (branch_id).");
}

$patient_id = (int)($_GET["patient_id"] ?? 0);
if ($patient_id <= 0) {
    http_response_code(400);
    die("Paciente inválido (patient_id).");
}

/** Paciente (solo en esta sucursal) */
$stmt = $conn->prepare("
    SELECT p.id, p.first_name, p.last_name, b.name AS branch_name
    FROM patients p
    INNER JOIN branches b ON b.id = p.branch_id
    WHERE p.id = :pid AND p.branch_id = :bid
    LIMIT 1
");
$stmt->execute(["pid" => $patient_id, "bid" => $sucursalId]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$patient) {
    http_response_code(404);
    die("Paciente no encontrado en esta sucursal.");
}

/** Facturas del paciente en esta sucursal */
$hasStatus = colExists($conn, 'invoices', 'status');
$hasCancelReason = colExists($conn, 'invoices', 'cancel_reason');
$hasCancelledAt = colExists($conn, 'invoices', 'cancelled_at');

$extraInvoiceCols = "";
if ($hasStatus) {
    $extraInvoiceCols .= ", i.status";
}
if ($hasCancelReason) {
    $extraInvoiceCols .= ", i.cancel_reason";
}
if ($hasCancelledAt) {
    $extraInvoiceCols .= ", i.cancelled_at";
}

$stmt = $conn->prepare("
    SELECT i.id,
           i.invoice_code,
           i.invoice_number,
           DATE(i.created_at) AS invoice_date,
           i.payment_method,
           i.total
           {$extraInvoiceCols}
    FROM invoices i
    WHERE i.patient_id = :pid
      AND i.branch_id  = :bid
    ORDER BY i.id DESC
");
$stmt->execute(["pid" => $patient_id, "bid" => $sucursalId]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

/** Totales */
$total_count = count($invoices);
$total_active_count = 0;
$total_cancelled_count = 0;
$total_amount = 0.0;

foreach ($invoices as $inv) {
    $status = strtoupper((string)($inv["status"] ?? "ACTIVE"));
    if ($status === "CANCELLED" || $status === "ANULADA") {
        $total_cancelled_count++;
        continue;
    }

    $total_active_count++;
    $total_amount += (float)$inv["total"];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Facturación | CEVIMEP</title>
    <link rel="stylesheet" href="/assets/css/styles.css?v=50">

    <style>
        /* Solo ajustes locales, sin cambiar tu estilo base */
        .page-wrap{ padding: 18px; }
        .page-header{
            display:flex; align-items:center; justify-content:space-between;
            gap:12px; flex-wrap:wrap; margin-bottom: 14px;
        }
        .page-header h1{
            margin:0; font-size: 44px; font-weight: 900; letter-spacing: -.5px;
        }
        .page-subtitle{ margin: 6px 0 0; opacity:.75; font-weight: 700; }

        .actions{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .btn-pill.secondary{ background:#fff; color:#0b4d87; border:1px solid #d7e7fb; }
        .btn-pill.primary{ background:#0b63b6; color:#fff; border:1px solid #0b63b6; }

        .card{
            background:#fff;
            border-radius: 18px;
            border: 1px solid #e6eef8;
            box-shadow: 0 12px 30px rgba(0,0,0,.06);
            padding: 16px;
        }

        .patient-center{ text-align:center; margin-bottom: 12px; }
        .patient-center .name{ font-size: 18px; font-weight: 900; margin:0; }
        .patient-center .branch{ margin:3px 0 0; opacity:.75; font-weight:700; font-size:13px; }

        .chips{
            display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap;
            margin-top: 10px;
        }
        .chip{
            background:#f7fbff;
            border:1px solid #d7e7fb;
            color:#0b4d87;
            padding: 8px 12px;
            border-radius: 999px;
            font-weight: 900;
            font-size: 13px;
            white-space: nowrap;
        }

        .section-title{
            margin: 8px 0 10px;
            font-size: 18px;
            font-weight: 900;
            color:#0b4d87;
            text-align:center;
        }

        /* Scroll: máximo ~5 filas visibles */
        .table-scroll{
            max-height: 360px;
            overflow: auto;
            border: 1px solid #eef2f6;
            border-radius: 14px;
            background:#fff;
        }

        table{
            width:100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 820px;
            background:#fff;
        }

        th, td{
            padding: 12px 10px;
            border-bottom: 1px solid #eef2f6;
            font-size: 13px;
        }

        th{
            text-align:left;
            font-weight: 900;
            color:#0b4d87;
            background:#fff;
            position: sticky;
            top: 0;
            z-index: 2;
        }

        .money{ font-weight: 900; white-space: nowrap; }

        .mini-btn{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding: 7px 12px;
            border-radius: 999px;
            border:1px solid #d7e7fb;
            background:#f7fbff;
            color:#0b4d87;
            font-weight: 900;
            font-size: 12px;
            text-decoration:none;
        }

        .mini-btn.danger{
            background:#fff1f1;
            color:#a40000;
            border-color:#ffc4c4;
        }

        .status-badge{
            display:inline-flex;
            padding:5px 9px;
            border-radius:999px;
            font-weight:900;
            font-size:11px;
            border:1px solid #cfe8d6;
            background:#effff3;
            color:#0a7a33;
        }

        .status-badge.cancelled{
            border-color:#ffc4c4;
            background:#fff1f1;
            color:#a40000;
        }

        .cancel-form{
            display:inline;
            margin-left:6px;
        }

        .muted{
            text-align:center;
            opacity:.7;
            font-weight: 700;
            padding: 18px 10px;
        }
    </style>
</head>
<body>

<!-- TOPBAR (igual dashboard.php) -->
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

    <!-- SIDEBAR (igual dashboard.php) -->
    <aside class="sidebar">
        <div class="menu-title">Menú</div>

        <nav class="menu">
            <a href="/private/dashboard.php">🏠 Panel</a>
            <a href="/private/patients/index.php">👤 Pacientes</a>
            <a href="/private/citas/index.php">📅 Citas</a>
            <a class="active" href="/private/facturacion/index.php">🧾 Facturación</a>
            <a href="/private/caja/index.php">💳 Caja</a>
            <a href="/private/inventario/index.php">📦 Inventario</a>
            <a href="/private/estadistica/index.php">📊 Estadísticas</a>
        </nav>
    </aside>

    <!-- CONTENIDO -->
    <main class="content">
        <div class="page-wrap">

            <div class="page-header">
                <div>
                    <h1>Facturación</h1>
                    <div class="page-subtitle">Historial del paciente en esta sucursal</div>
                </div>

                <div class="actions">
                    <a class="btn-pill secondary" href="/private/facturacion/index.php">← Volver</a>
                    <a class="btn-pill primary" href="/private/facturacion/nueva.php?patient_id=<?= (int)$patient_id ?>">➕ Nueva factura</a>
                </div>
            </div>

            <div class="card">
                <div class="patient-center">
                    <p class="name">
                        <?= h(($patient["first_name"] ?? "") . " " . ($patient["last_name"] ?? "")) ?>
                    </p>
                    <p class="branch">Sucursal: <?= h($patient["branch_name"] ?? "") ?></p>

                    <div class="chips">
                        <div class="chip">Facturas activas: <strong><?= (int)$total_active_count ?></strong></div>
                        <div class="chip">Anuladas: <strong><?= (int)$total_cancelled_count ?></strong></div>
                        <div class="chip">Monto activo: <strong>RD$ <?= number_format((float)$total_amount, 2) ?></strong></div>
                    </div>
                </div>

                <div class="section-title">Facturas</div>

                <div class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:140px;">Factura</th>
                                <th style="width:150px;">Fecha</th>
                                <th style="width:170px;">Método</th>
                                <th style="width:170px;">Total</th>
                                <th style="width:130px;">Estado</th>
                                <th style="width:260px;">Detalle</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($invoices)): ?>
                            <tr>
                                <td colspan="6" class="muted">Este paciente no tiene facturas en esta sucursal.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($invoices as $inv): ?>
                                <?php
                                    $status = strtoupper((string)($inv["status"] ?? "ACTIVE"));
                                    $isCancelled = ($status === "CANCELLED" || $status === "ANULADA");
                                    $invoiceLabel = $inv["invoice_code"] ?? ("#" . $inv["id"]);
                                ?>
                                <tr>
                                    <td><?= h($invoiceLabel) ?></td>
                                    <td><?= h($inv["invoice_date"]) ?></td>
                                    <td><?= h($inv["payment_method"]) ?></td>
                                    <td class="money">RD$ <?= number_format((float)$inv["total"], 2) ?></td>
                                    <td>
                                        <?php if ($isCancelled): ?>
                                            <span class="status-badge cancelled" title="<?= h($inv["cancel_reason"] ?? "") ?>">ANULADA</span>
                                        <?php else: ?>
                                            <span class="status-badge">ACTIVA</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a class="mini-btn" href="/private/facturacion/ver.php?id=<?= (int)$inv["id"] ?>">📄 Ver</a>

                                        <?php if ($isAdmin && !$isCancelled): ?>
                                            <form class="cancel-form" method="post" action="/private/facturacion/anular.php" onsubmit="return pedirMotivoAnulacion(this, '<?= h($invoiceLabel) ?>');">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                <input type="hidden" name="invoice_id" value="<?= (int)$inv["id"] ?>">
                                                <input type="hidden" name="patient_id" value="<?= (int)$patient_id ?>">
                                                <input type="hidden" name="reason" value="">
                                                <button type="submit" class="mini-btn danger">🚫 Anular</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>

        </div>
    </main>

</div>

<!-- FOOTER (igual dashboard.php) -->
<footer class="footer">
    © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

<script>
function pedirMotivoAnulacion(form, factura) {
    const motivo = prompt("Motivo de anulación para la factura " + factura + ":");

    if (motivo === null) {
        return false;
    }

    const limpio = motivo.trim();

    if (limpio.length < 5) {
        alert("Debe escribir un motivo de al menos 5 caracteres.");
        return false;
    }

    form.querySelector('input[name="reason"]').value = limpio;
    return confirm("¿Seguro que deseas anular esta factura? Esta acción no borrará la factura, solo la marcará como ANULADA.");
}
</script>

</body>
</html>
