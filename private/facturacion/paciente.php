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

if ($isAdmin) {
    $sucursalId = (int)($_SESSION['selected_branch_id'] ?? 0);
} else {
    $sucursalId = (int)($user['branch_id'] ?? 0);
}

if ($sucursalId <= 0) {
    http_response_code(400);
    die("Sucursal inválida. Selecciona una sucursal en Facturación primero.");
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
    <meta name="viewport" content="width=device-width, initial-scale=1">

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

        .content{ position:relative; z-index:1; flex:1; width:100%; padding:0; }
        .footer{ position:relative; z-index:1; text-align:center; padding:20px; font-size:12px; color:#5C7896; }

        /* Solo ajustes locales, sin cambiar tu estilo base */
        .page-wrap{ padding: 24px 32px 40px; max-width:1300px; margin:0 auto; }
        .page-header{
            display:flex; align-items:center; justify-content:space-between;
            gap:12px; flex-wrap:wrap; margin-bottom: 14px;
        }
        .page-header h1{
            margin:0; font-family:'Manrope'; font-size: 40px; font-weight: 900; letter-spacing: -.5px;
        }
        .page-subtitle{ margin: 6px 0 0; opacity:.78; font-weight: 700; color:#9FB6CC; }

        .actions{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .btn-pill.secondary{ background:rgba(255,255,255,.08); color:#fff; border:1px solid rgba(255,255,255,.16); }
        .btn-pill.secondary:hover{ background:rgba(255,255,255,.14); }
        .btn-pill.primary{ background:var(--teal); color:#04211d; border:1px solid var(--teal); }
        .btn-pill.primary:hover{ background:var(--teal-dark); }

        .card{
            background:rgba(255,255,255,.07);
            backdrop-filter:blur(14px);
            -webkit-backdrop-filter:blur(14px);
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,.12);
            padding: 20px;
        }

        .patient-center{ text-align:center; margin-bottom: 12px; }
        .patient-center .name{ font-size: 19px; font-weight: 900; margin:0; color:#fff; }
        .patient-center .branch{ margin:3px 0 0; opacity:.75; font-weight:700; font-size:13px; color:#9FB6CC; }

        .chips{
            display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap;
            margin-top: 10px;
        }
        .chip{
            background:rgba(20,184,166,.1);
            border:1px solid rgba(20,184,166,.3);
            color:var(--teal);
            padding: 8px 12px;
            border-radius: 999px;
            font-weight: 900;
            font-size: 13px;
            white-space: nowrap;
        }

        .section-title{
            margin: 8px 0 10px;
            font-family:'Manrope';
            font-size: 18px;
            font-weight: 900;
            color:var(--teal);
            text-align:center;
        }

        /* Scroll: máximo ~5 filas visibles */
        .table-scroll{
            max-height: 360px;
            overflow: auto;
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 14px;
            background:rgba(255,255,255,.02);
        }

        table{
            width:100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 820px;
            background:transparent;
        }

        th, td{
            padding: 12px 10px;
            border-bottom: 1px solid rgba(255,255,255,.08);
            font-size: 13px;
            color:#E7EEF6;
        }

        th{
            text-align:left;
            font-weight: 900;
            color:var(--teal);
            background:rgba(20,184,166,.1);
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
            border:1px solid rgba(20,184,166,.35);
            background:rgba(20,184,166,.1);
            color:var(--teal);
            font-weight: 900;
            font-size: 12px;
            text-decoration:none;
        }
        .mini-btn:hover{ background:rgba(20,184,166,.2); }

        .mini-btn.danger{
            background:rgba(220,38,38,.12);
            color:#f87171;
            border-color:rgba(220,38,38,.35);
        }
        .mini-btn.danger:hover{ background:rgba(220,38,38,.2); }

        .status-badge{
            display:inline-flex;
            padding:5px 9px;
            border-radius:999px;
            font-weight:900;
            font-size:11px;
            border:1px solid rgba(20,184,166,.35);
            background:rgba(20,184,166,.12);
            color:var(--teal);
        }

        .status-badge.cancelled{
            border-color:rgba(220,38,38,.35);
            background:rgba(220,38,38,.12);
            color:#f87171;
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
            color:#9FB6CC;
        }

        @media (max-width: 980px){
            .layout{ flex-direction:column; }
            .sidebar{ width:100%; }
            .page-wrap{ padding: 20px 16px 32px; }
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