<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    header("Location: /login.php");
    exit;
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/caja/caja_helpers.php';

$user = $_SESSION['user'];

$nombreSucursal = $user['full_name'] ?? 'CEVIMEP';
$rol = $user['role'] ?? '';
$branchId = (int)($user['branch_id'] ?? ($_SESSION['branch_id'] ?? 0));

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtMoney($n){ return number_format((float)$n, 2, ".", ","); }

$today = date('Y-m-d');

/* =========================================================
   Tarjeta 1: Caja neta de hoy (misma lógica que caja/index.php,
   sumando Caja 1 + Caja 2, incluyendo ingresos y anulaciones,
   restando desembolsos reales)
   ========================================================= */
$cajaHoy = 0.0;
if ($branchId > 0) {
    try {
        $allSessionIds = [];
        foreach ([1, 2] as $cajaNum) {
            $sessions = caja_get_sessions($pdo, $branchId, $today, $cajaNum);
            foreach ($sessions as $s) {
                $allSessionIds[] = (int)$s['id'];
            }
        }

        if (!empty($allSessionIds)) {
            $ph = implode(",", array_fill(0, count($allSessionIds), "?"));
            $st = $pdo->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN type IN ('ingreso','anulacion') THEN amount END), 0) AS ingresos_netos,
                    COALESCE(SUM(CASE WHEN type='desembolso' THEN ABS(amount) END), 0) AS desembolsos
                FROM cash_movements
                WHERE caja_sesion_id IN ($ph)
            ");
            $st->execute($allSessionIds);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['ingresos_netos' => 0, 'desembolsos' => 0];
            $cajaHoy = (float)$r['ingresos_netos'] - (float)$r['desembolsos'];
        }
    } catch (Throwable $e) {
        $cajaHoy = 0.0;
    }
}

/* =========================================================
   Detectar la columna de fecha real en invoices
   ========================================================= */
function pickDateCol(PDO $pdo, string $table): ?string {
    $cols = caja_table_columns($pdo, $table);
    foreach (['created_at', 'fecha', 'issued_at', 'invoice_date', 'date'] as $c) {
        if (in_array($c, $cols, true)) return $c;
    }
    return null;
}

$invoiceDateCol = pickDateCol($pdo, 'invoices');

/* =========================================================
   Tarjeta 2: Facturas de hoy (no anuladas)
   Tarjeta 3: Pacientes distintos atendidos hoy
   ========================================================= */
$facturasHoy = 0;
$pacientesHoy = 0;

if ($branchId > 0 && $invoiceDateCol !== null) {
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) 
            FROM invoices
            WHERE branch_id = ?
              AND DATE({$invoiceDateCol}) = ?
              AND (status IS NULL OR status NOT IN ('CANCELLED','ANULADA'))
        ");
        $st->execute([$branchId, $today]);
        $facturasHoy = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        $facturasHoy = 0;
    }

    try {
        $st = $pdo->prepare("
            SELECT COUNT(DISTINCT patient_id)
            FROM invoices
            WHERE branch_id = ?
              AND DATE({$invoiceDateCol}) = ?
              AND (status IS NULL OR status NOT IN ('CANCELLED','ANULADA'))
        ");
        $st->execute([$branchId, $today]);
        $pacientesHoy = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        $pacientesHoy = 0;
    }
}

/* =========================================================
   Tarjeta 4: Productos con stock bajo
   Usa min_stock/stock_minimo si existe; si no, umbral fijo de 5.
   ========================================================= */
$stockBajo = 0;

try {
    $stockCandidates = ["inventory_stock", "inventory_branch_stock", "inventario_stock", "inventory_sucursal_stock", "stock"];
    $stockTable = null;
    foreach ($stockCandidates as $t) {
        try {
            $chk = $pdo->prepare("SHOW TABLES LIKE ?");
            $chk->execute([$t]);
            if ($chk->fetchColumn()) { $stockTable = $t; break; }
        } catch (Throwable $e) {}
    }

    if ($stockTable !== null && $branchId > 0) {
        $cols = caja_table_columns($pdo, $stockTable);

        $branchCol = in_array("branch_id", $cols, true) ? "branch_id" : (in_array("sucursal_id", $cols, true) ? "sucursal_id" : null);
        $qtyCol = in_array("quantity", $cols, true) ? "quantity" : (in_array("qty", $cols, true) ? "qty" : (in_array("stock", $cols, true) ? "stock" : (in_array("existencia", $cols, true) ? "existencia" : null)));
        $minCol = in_array("min_stock", $cols, true) ? "min_stock" : (in_array("stock_minimo", $cols, true) ? "stock_minimo" : null);

        if ($branchCol !== null && $qtyCol !== null) {
            if ($minCol !== null) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM {$stockTable} WHERE {$branchCol} = ? AND {$qtyCol} <= {$minCol}");
            } else {
                $st = $pdo->prepare("SELECT COUNT(*) FROM {$stockTable} WHERE {$branchCol} = ? AND {$qtyCol} <= 5");
            }
            $st->execute([$branchId]);
            $stockBajo = (int)$st->fetchColumn();
        }
    }
} catch (Throwable $e) {
    $stockBajo = 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel | CEVIMEP</title>
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

        .content{ position:relative; z-index:1; flex:1; padding:40px 40px 60px; }

        .welcome-center h1{ font-family:'Manrope'; font-weight:800; font-size:30px; margin:0 0 30px; }
        .welcome-center h1 strong{ color:var(--teal); }

        .cards{ display:grid; grid-template-columns:repeat(4,1fr); gap:16px; max-width:920px; }
        .card{
            background:rgba(255,255,255,.07);
            backdrop-filter:blur(14px);
            -webkit-backdrop-filter:blur(14px);
            border:1px solid rgba(255,255,255,.12);
            border-radius:16px;
            padding:20px;
        }
        .card .lbl{ font-size:12.5px; font-weight:600; color:#9FB6CC; margin:0 0 10px; }
        .card .val{ font-family:'Manrope'; font-weight:800; font-size:24px; margin:0; color:#fff; }
        .card.accent .val{ color:var(--teal); }

        .footer{ position:relative; z-index:1; text-align:center; padding:20px; font-size:12px; color:#5C7896; }

        @media(max-width:980px){
            .cards{ grid-template-columns:repeat(2,1fr); }
            .layout{ flex-direction:column; }
            .sidebar{ width:100%; }
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

<!-- TOPBAR -->
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

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="menu-title">Menú</div>

        <nav class="menu">
            <a class="active" href="/private/dashboard.php">🏠 Panel</a>
            <a href="/private/patients/index.php">👤 Pacientes</a>
            <a href="#" onclick="return false;" style="opacity:.5;cursor:not-allowed;">
    📅 Citas (Próximamente)
</a>
            <a href="/private/facturacion/index.php">🧾 Facturación</a>
            <a href="/private/caja/index.php">💳 Caja</a>
            <a href="/private/inventario/index.php">📦 Inventario</a>
            <a href="/private/estadistica/index.php">📊 Estadísticas</a>
        </nav>
    </aside>

    <!-- CONTENIDO -->
    <main class="content">

        <div class="welcome-center">
            <h1>Bienvenido <strong><?= h($nombreSucursal) ?></strong></h1>
        </div>

        <div class="cards">
            <div class="card accent">
                <p class="lbl">Caja hoy (neto)</p>
                <p class="val">RD$ <?= fmtMoney($cajaHoy) ?></p>
            </div>
            <div class="card">
                <p class="lbl">Facturas hoy</p>
                <p class="val"><?= (int)$facturasHoy ?></p>
            </div>
            <div class="card">
                <p class="lbl">Pacientes atendidos</p>
                <p class="val"><?= (int)$pacientesHoy ?></p>
            </div>
            <div class="card">
                <p class="lbl">Stock bajo</p>
                <p class="val"><?= (int)$stockBajo ?> items</p>
            </div>
        </div>

    </main>
</div>

<footer class="footer">
    © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>