<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* =========================
   CARGAR DB (ruta robusta)
   ========================= */
$db_candidates = [
    __DIR__ . '/../config/db.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../db.php',
    __DIR__ . '/../../db.php',
];

$loaded = false;
foreach ($db_candidates as $db_file) {
    if (is_file($db_file)) {
        require_once $db_file;
        $loaded = true;
        break;
    }
}

if (!$loaded || !isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo "Error crítico: no se pudo cargar la conexión a la base de datos.";
    exit;
}

if (!isset($_SESSION["user"])) {
    header("Location: /login.php");
    exit;
}

$user = $_SESSION["user"];
$yearNow = date("Y");

// Sidebar active
$active = "estadistica";
$base = "../";

// Protección (password)
$estadistica_ok = !empty($_SESSION["estadistica_ok"]);

/* =======================
   Filtro de MES/AÑO
   ======================= */
$tz = new DateTimeZone("America/Santo_Domingo");
$now = new DateTime("now", $tz);

$month = isset($_GET["month"]) ? (int)$_GET["month"] : (int)$now->format("n");
$year  = isset($_GET["year"])  ? (int)$_GET["year"]  : (int)$now->format("Y");

if ($month < 1 || $month > 12) {
    $month = (int)$now->format("n");
}
if ($year < 2020 || $year > 2100) {
    $year = (int)$now->format("Y");
}

$start = new DateTime(sprintf("%04d-%02d-01 00:00:00", $year, $month), $tz);
$end = (clone $start)->modify("+1 month");

$startStr = $start->format("Y-m-d H:i:s");
$endStr   = $end->format("Y-m-d H:i:s");

/* =======================
   Helpers DB
   ======================= */
function table_exists($pdo, $table)
{
    $sql = "
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ";

    $st = $pdo->prepare($sql);
    $st->execute([$table]);

    return (int)$st->fetchColumn() > 0;
}

function column_exists($pdo, $table, $col)
{
    $sql = "
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ";

    $st = $pdo->prepare($sql);
    $st->execute([$table, $col]);

    return (int)$st->fetchColumn() > 0;
}
/* =======================
   DATA
   ======================= */
$top = [];
$historial = [];
$ventasPorDia = [];

if ($estadistica_ok) {
    $joinInv = table_exists($pdo, "inventory_items")
        ? "LEFT JOIN inventory_items it ON it.id = ii.item_id"
        : "";

    $joinVac = table_exists($pdo, "vaccines")
        ? "LEFT JOIN vaccines v ON v.id = ii.item_id"
        : "";

    $itName = null;
    if ($joinInv) {
        if (column_exists($pdo, "inventory_items", "nombre")) {
            $itName = "it.nombre";
        } elseif (column_exists($pdo, "inventory_items", "name")) {
            $itName = "it.name";
        } elseif (column_exists($pdo, "inventory_items", "title")) {
            $itName = "it.title";
        }
    }

    $vName = null;
    if ($joinVac) {
        if (column_exists($pdo, "vaccines", "nombre")) {
            $vName = "v.nombre";
        } elseif (column_exists($pdo, "vaccines", "name")) {
            $vName = "v.name";
        } elseif (column_exists($pdo, "vaccines", "title")) {
            $vName = "v.title";
        }
    }

    $nameExprParts = [];
    if ($itName) $nameExprParts[] = $itName;
    if ($vName)  $nameExprParts[] = $vName;
    $nameExprParts[] = "CONCAT('Item #', ii.item_id)";
    $nameExpr = "COALESCE(" . implode(", ", $nameExprParts) . ")";

    // Ranking mensual
    $sqlTop = "
        SELECT $nameExpr AS vacuna, SUM(ii.qty) AS cantidad
        FROM invoice_items ii
        INNER JOIN invoices i ON i.id = ii.invoice_id
        $joinInv
        $joinVac
        WHERE i.created_at >= ? AND i.created_at < ?
        GROUP BY ii.item_id
        ORDER BY cantidad DESC, vacuna ASC
    ";
    $st = $pdo->prepare($sqlTop);
    $st->execute([$startStr, $endStr]);
    $top = $st->fetchAll(PDO::FETCH_ASSOC);

    // Historial del mes
    $sqlHist = "
        SELECT DATE(i.created_at) AS fecha, $nameExpr AS vacuna, SUM(ii.qty) AS cantidad
        FROM invoice_items ii
        INNER JOIN invoices i ON i.id = ii.invoice_id
        $joinInv
        $joinVac
        WHERE i.created_at >= ? AND i.created_at < ?
        GROUP BY DATE(i.created_at), ii.item_id
        ORDER BY fecha DESC, vacuna ASC
    ";
    $st2 = $pdo->prepare($sqlHist);
    $st2->execute([$startStr, $endStr]);
    $historial = $st2->fetchAll(PDO::FETCH_ASSOC);

    // Ventas por día
    $sqlDia = "
        SELECT DATE(i.created_at) AS fecha, SUM(ii.qty) AS cantidad
        FROM invoice_items ii
        INNER JOIN invoices i ON i.id = ii.invoice_id
        WHERE i.created_at >= ? AND i.created_at < ?
        GROUP BY DATE(i.created_at)
        ORDER BY fecha ASC
    ";
    $st3 = $pdo->prepare($sqlDia);
    $st3->execute([$startStr, $endStr]);
    $ventasPorDia = $st3->fetchAll(PDO::FETCH_ASSOC);
}

/* =======================
   Preparar charts
   ======================= */
$chartLabels = [];
$chartValues = [];
foreach ($top as $r) {
    $chartLabels[] = $r["vacuna"];
    $chartValues[] = (int)$r["cantidad"];
}

$daysInMonth = (int)$start->format("t");
$mapDia = [];
foreach ($ventasPorDia as $r) {
    $mapDia[$r["fecha"]] = (int)$r["cantidad"];
}

$lineLabels = [];
$lineValues = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateKey = sprintf("%04d-%02d-%02d", $year, $month, $d);
    $lineLabels[] = (string)$d;
    $lineValues[] = $mapDia[$dateKey] ?? 0;
}

$monthName = $start->format("F");
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estadística | CEVIMEP</title>

    <link rel="stylesheet" href="/assets/css/styles.css?v=60">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

    <style>
        body{ background:#f3f6fb; }
        .topbar{
            height:58px;
            background: linear-gradient(90deg, #0a2c6b, #063b85);
            color:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            position:relative;
            box-shadow: 0 6px 18px rgba(0,0,0,.10);
        }
        .topbar .brand{
            font-weight:800;
            letter-spacing:.5px;
            display:flex;
            align-items:center;
            gap:10px;
        }
        .topbar .brand .dot{
            width:9px;height:9px;border-radius:50%;
            background:#22c55e; display:inline-block;
        }
        .topbar .right{ position:absolute; right:18px; display:flex; gap:10px; align-items:center; }
        .topbar .right a{
            color:#fff; text-decoration:none;
            border:1px solid rgba(255,255,255,.35);
            padding:6px 14px; border-radius:999px;
            font-weight:600;
        }
        .appwrap{ display:flex; min-height: calc(100vh - 58px - 52px); }
        .sidebar{
            width:260px;
            background:#f7f7f7;
            border-right:1px solid rgba(0,0,0,.06);
            padding:18px 14px;
        }
        .content{ flex:1; padding:22px 24px; }
        .soft-card{
            background:#fff;
            border-radius:16px;
            box-shadow: 0 10px 24px rgba(10,44,107,.08);
            border:1px solid rgba(0,0,0,.04);
        }
        .soft-card .card-h{
            padding:14px 16px;
            border-bottom:1px solid rgba(0,0,0,.06);
            display:flex; align-items:center; justify-content:space-between;
            gap:10px;
            flex-wrap:wrap;
        }
        .soft-card .card-b{ padding:16px; }
        .footer{
            height:52px;
            background: linear-gradient(90deg, #0a2c6b, #063b85);
            color:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            font-weight:600;
        }
        .mini-muted{ font-size:12px; color:#6b7280; }
        .filter-wrap{ display:flex; gap:10px; align-items:end; flex-wrap:wrap; }
        .filter-wrap .form-select, .filter-wrap .form-control{ min-width:140px; }
    </style>
</head>
<body>

<header class="topbar">
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="right">
        <a href="/logout.php">Salir</a>
    </div>
</header>

<div class="appwrap">
    <aside class="sidebar">
        <?php
        $active = "estadistica";
        $base   = "../";
        ?>
        <?php include "../partials/sidebar.php"; ?>
    </aside>

    <section class="content">

        <div class="soft-card mb-3">
            <div class="card-h">
                <div>
                    <div style="font-size:20px;font-weight:900;">Estadística</div>
                    <div class="text-muted">Mes: <strong><?php echo htmlspecialchars($monthName . " " . $year); ?></strong></div>
                </div>

                <div class="filter-wrap">
                    <form method="GET" class="d-flex gap-2 flex-wrap align-items-end">
                        <div>
                            <div class="mini-muted">Mes</div>
                            <select name="month" class="form-select form-select-sm">
                                <?php
                                for ($m = 1; $m <= 12; $m++) {
                                    $sel = ($m === $month) ? "selected" : "";
                                    $tmp = new DateTime(sprintf("%04d-%02d-01", $year, $m), $tz);
                                    echo "<option value='{$m}' {$sel}>".$tmp->format("F")."</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div>
                            <div class="mini-muted">Año</div>
                            <input name="year" type="number" class="form-control form-control-sm" value="<?php echo (int)$year; ?>" min="2020" max="2100">
                        </div>
                        <div>
                            <button class="btn btn-sm btn-primary" type="submit">Aplicar</button>
                            <?php if ($estadistica_ok): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="logout.php">Cerrar</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card-b">
                <div class="text-muted">Revisa cuáles vacunas tienen más demanda, picos por día y el historial detallado del mes.</div>
            </div>
        </div>

        <?php if (!$estadistica_ok): ?>
            <div class="alert alert-warning">Sección protegida. Ingresa contraseña.</div>
        <?php else: ?>

            <div class="row g-3">

                <div class="col-lg-5">
                    <div class="soft-card">
                        <div class="card-h">
                            <strong>Demanda por vacuna</strong>
                            <span class="mini-muted">Ranking del mes</span>
                        </div>
                        <div class="card-b">
                            <?php if (empty($top)): ?>
                                <div class="text-muted">No hay ventas registradas en este mes.</div>
                            <?php else: ?>
                                <div class="table-responsive mb-3">
                                    <table class="table table-sm align-middle">
                                        <thead>
                                            <tr><th>Vacuna/Item</th><th class="text-end">Cantidad</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($top as $r): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($r["vacuna"]); ?></td>
                                                    <td class="text-end"><strong><?php echo (int)$r["cantidad"]; ?></strong></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <?php
                                $mas = $top[0];
                                $menos = $top[count($top) - 1];
                                ?>
                                <div class="small mb-3">
                                    ✅ <strong>Mayor demanda:</strong> <?php echo htmlspecialchars($mas["vacuna"]); ?> (<?php echo (int)$mas["cantidad"]; ?>)<br>
                                    ⚠️ <strong>Menor demanda:</strong> <?php echo htmlspecialchars($menos["vacuna"]); ?> (<?php echo (int)$menos["cantidad"]; ?>)
                                </div>

                                <div class="soft-card" style="border-radius:14px; box-shadow:none; border:1px solid rgba(0,0,0,.06);">
                                    <div class="card-b">
                                        <div class="fw-bold mb-2">Gráfica: demanda</div>
                                        <canvas id="demandChart" height="200"></canvas>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="soft-card mb-3">
                        <div class="card-h">
                            <strong>Ventas por día</strong>
                            <span class="mini-muted">Total de unidades vendidas cada día</span>
                        </div>
                        <div class="card-b">
                            <canvas id="dailyChart" height="140"></canvas>
                        </div>
                    </div>

                    <div class="soft-card">
                        <div class="card-h">
                            <div>
                                <strong>Historial del mes</strong>
                                <div class="mini-muted">Por día y por vacuna</div>
                            </div>
                        </div>
                        <div class="card-b">
                            <?php if (empty($historial)): ?>
                                <div class="text-muted">Aún no hay historial para este mes.</div>
                            <?php else: ?>
                                <div class="table-responsive" style="max-height:420px; overflow:auto;">
                                    <table class="table table-sm table-hover align-middle">
                                        <thead class="sticky-top bg-white">
                                            <tr><th>Fecha</th><th>Vacuna/Item</th><th class="text-end">Cantidad</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($historial as $r): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($r["fecha"]); ?></td>
                                                    <td><?php echo htmlspecialchars($r["vacuna"]); ?></td>
                                                    <td class="text-end"><strong><?php echo (int)$r["cantidad"]; ?></strong></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>

        <?php endif; ?>

    </section>
</div>

<footer class="footer">
    © <?php echo $yearNow; ?> CEVIMEP. Todos los derechos reservados.
</footer>

<div class="modal fade" id="passModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Acceso a Estadística</h5>
            </div>
            <div class="modal-body">
                <input type="password" id="passInput" class="form-control" placeholder="Contraseña">
                <div id="passMsg" class="small text-danger mt-2" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <a href="../dashboard.php" class="btn btn-outline-secondary">Salir</a>
                <button type="button" id="passBtn" class="btn btn-primary">Entrar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
(() => {
    const ok = <?php echo $estadistica_ok ? "true" : "false"; ?>;

    if (!ok) {
        const modal = new bootstrap.Modal(document.getElementById('passModal'), {
            backdrop: 'static',
            keyboard: false
        });
        modal.show();

        const passBtn = document.getElementById("passBtn");
        const inp = document.getElementById("passInput");
        const msg = document.getElementById("passMsg");

        async function go() {
            msg.style.display = "none";
            const password = inp.value.trim();

            if (!password) {
                msg.textContent = "Escribe la contraseña.";
                msg.style.display = "block";
                return;
            }

            passBtn.disabled = true;
            passBtn.textContent = "Verificando...";

            try {
                const fd = new FormData();
                fd.append("password", password);

                const r = await fetch("auth.php", {
                    method: "POST",
                    body: fd
                });

                const data = await r.json();

                if (data.ok) {
                    location.reload();
                    return;
                }

                msg.textContent = data.msg || "Contraseña incorrecta";
                msg.style.display = "block";
            } catch (e) {
                msg.textContent = "Error de conexión.";
                msg.style.display = "block";
            } finally {
                passBtn.disabled = false;
                passBtn.textContent = "Entrar";
            }
        }

        passBtn.addEventListener("click", go);
        inp.addEventListener("keydown", (e) => {
            if (e.key === "Enter") go();
        });

        setTimeout(() => inp.focus(), 250);
    }

    const labels = <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>;
    const values = <?php echo json_encode($chartValues); ?>;

    const elBar = document.getElementById("demandChart");
    if (elBar && labels.length) {
        new Chart(elBar, {
            type: "bar",
            data: {
                labels,
                datasets: [{
                    label: "Cantidad vendida",
                    data: values,
                    borderWidth: 1,
                    borderRadius: 10
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true }
                }
            }
        });
    }

    const dayLabels = <?php echo json_encode($lineLabels); ?>;
    const dayValues = <?php echo json_encode($lineValues); ?>;

    const elLine = document.getElementById("dailyChart");
    if (elLine) {
        new Chart(elLine, {
            type: "line",
            data: {
                labels: dayLabels,
                datasets: [{
                    label: "Unidades por día",
                    data: dayValues,
                    borderWidth: 2,
                    tension: 0.35,
                    pointRadius: 2
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true }
                }
            }
        });
    }
})();
</script>

</body>
</html>