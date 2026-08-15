<?php
// private/patients/index.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['user'])) {
    header("Location: /login.php");
    exit;
}

$user = $_SESSION['user'];
$branchId = (int)($user['branch_id'] ?? 0);

if ($branchId <= 0) {
    header("Location: /private/dashboard.php");
    exit;
}

/* ===============================
   DB (ruta robusta)
   =============================== */
$db_candidates = [
    __DIR__ . "/../config/db.php",
    __DIR__ . "/../../config/db.php",
    __DIR__ . "/../db.php",
    __DIR__ . "/../../db.php",
];

$loaded = false;
foreach ($db_candidates as $pp) {
    if (is_file($pp)) {
        require_once $pp;
        $loaded = true;
        break;
    }
}

if (!$loaded || !isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo "Error crítico: no se pudo cargar la conexión a la base de datos.";
    exit;
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function calcAge($birthDate): string {
    if (!$birthDate) return '';
    try {
        $dob = new DateTime((string)$birthDate);
        $now = new DateTime();
        return (string)$now->diff($dob)->y;
    } catch (Throwable $e) {
        return '';
    }
}

/* ===============================
   BUSCADOR
   =============================== */
$search = trim((string)($_GET['q'] ?? ''));

/* ===============================
   PAGINACIÓN
   =============================== */
$perPage = 8;
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $perPage;

/* ===============================
   WHERE + PARAMS
   =============================== */
$where = " WHERE p.branch_id = ? ";
$params = [$branchId];

if ($search !== '') {
    $where .= " AND (
        p.no_libro LIKE ?
        OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?
        OR p.cedula LIKE ?
        OR p.phone LIKE ?
        OR p.direccion LIKE ?
    )";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

/* ===============================
   TOTAL
   =============================== */
$countSql = "SELECT COUNT(*) FROM patients p {$where}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$totalPages = (int)ceil($totalRows / $perPage);
if ($totalPages < 1) $totalPages = 1;

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/* ===============================
   LISTADO (8 por página)
   =============================== */
$sql = "
    SELECT
        p.id,
        p.no_libro,
        p.first_name,
        p.last_name,
        p.cedula,
        p.phone,
        p.direccion,
        p.birth_date,
        p.gender,
        p.blood_type
    FROM patients p
    {$where}
    ORDER BY p.id DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

function buildPageUrl(int $toPage, string $search): string {
    $query = [];
    if ($search !== '') $query['q'] = $search;
    $query['page'] = $toPage;
    return '/private/patients/index.php?' . http_build_query($query);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Pacientes | CEVIMEP</title>
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

        .content{ position:relative; z-index:1; flex:1; padding:40px 40px 60px; width:100%; }

        .footer{ position:relative; z-index:1; text-align:center; padding:20px; font-size:12px; color:#5C7896; }

        /* ===== Botones genéricos ===== */
        .btn{
            display:inline-block;
            background:rgba(255,255,255,.08);
            border:1px solid rgba(255,255,255,.16);
            color:#fff;
            padding:10px 18px;
            border-radius:999px;
            text-decoration:none;
            font-weight:700;
            font-size:14px;
            cursor:pointer;
            transition:background .15s;
        }
        .btn:hover{ background:rgba(255,255,255,.14); }
        .btn-primary{ background:var(--teal); border-color:var(--teal); color:#04211d; }
        .btn-primary:hover{ background:var(--teal-dark); color:#04211d; }

        /* ===== Página pacientes ===== */
        .patients-page{
            max-width: 1200px;
            margin: 0 auto;
        }
        .patients-header{
            text-align:center;
            margin: 0 0 22px;
        }
        .patients-header h1{
            margin:0;
            font-family:'Manrope';
            font-size: 34px;
            font-weight: 900;
        }
        .patients-header p{
            margin: 8px 0 0;
            opacity: .78;
            font-weight: 600;
            color:#9FB6CC;
        }
        .patients-actions{
            display:flex;
            gap:12px;
            justify-content:center;
            flex-wrap:wrap;
            align-items:center;
            margin: 18px 0 26px;
        }
        .patients-actions form{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            justify-content:center;
            align-items:center;
        }
        .patients-actions input[type="text"]{
            min-width: 360px;
            max-width: 560px;
            width: 50vw;
            background:rgba(255,255,255,.06);
            border:1px solid rgba(255,255,255,.16);
            color:#fff;
            border-radius:999px;
            padding:10px 16px;
            font:inherit;
            outline:none;
        }
        .patients-actions input[type="text"]::placeholder{ color:#7D95AC; }
        .patients-actions input[type="text"]:focus{ border-color:var(--teal); }

        .patients-card{
            background:rgba(255,255,255,.07);
            backdrop-filter:blur(14px);
            -webkit-backdrop-filter:blur(14px);
            border:1px solid rgba(255,255,255,.12);
            border-radius:16px;
            overflow:hidden;
        }

        .table{ width:100%; border-collapse:collapse; }
        .patients-card .table th,
        .patients-card .table td{
            text-align:center;
            vertical-align:middle;
        }
        .table th{
            background:rgba(20,184,166,.14);
            color:var(--teal);
            padding:12px 10px;
            font-size:13px;
            font-weight:800;
            letter-spacing:.02em;
        }
        .table td{
            padding:12px 8px;
            border-bottom:1px solid rgba(255,255,255,.08);
            color:#E7EEF6;
            font-weight:500;
        }
        .table tbody tr:last-child td{ border-bottom:none; }
        .table tbody tr:hover td{ background:rgba(255,255,255,.03); }

        .td-empty{
            text-align:center;
            padding: 26px 10px;
            opacity:.7;
            font-weight:700;
            color:#9FB6CC;
        }

        .table a{ color:var(--teal); }

        /* Paginación */
        .pagination-wrap{display:flex;justify-content:center;margin-top:18px;}
        .pagination{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:center;}
        .page-btn{
            display:inline-block;
            padding:8px 12px;
            border-radius:10px;
            text-decoration:none;
            font-weight:800;
            font-size:13px;
            border:1px solid rgba(255,255,255,.16);
            background:rgba(255,255,255,.06);
            color:#fff;
        }
        .page-btn:hover{ background:rgba(255,255,255,.12); }
        .page-btn.active{background:var(--teal);border-color:var(--teal);color:#04211d;}
        .page-btn.disabled{opacity:.4;pointer-events:none;}
        .page-info{opacity:.75;font-weight:700;margin-left:8px;color:#9FB6CC;}

        @media (max-width: 980px){
            .layout{ flex-direction:column; }
            .sidebar{ width:100%; }
            .content{ padding:24px 18px 40px; }
        }
        @media (max-width: 900px){
            .patients-actions input[type="text"]{min-width: 240px; width: 75vw;}
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
            <a href="/private/dashboard.php">🏠 Panel</a>
            <a class="active" href="/private/patients/index.php">👤 Pacientes</a>
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
        <div class="patients-page">

            <div class="patients-header">
                <h1>Pacientes</h1>
                <p>Listado filtrado por sucursal (automático).</p>
            </div>

            <div class="patients-actions">
                <form method="get" action="/private/patients/index.php">
                    <input
                        type="text"
                        name="q"
                        value="<?= h($search) ?>"
                        placeholder="Buscar por nombre, No. libro, cédula, teléfono, dirección"
                    >
                    <button class="btn btn-primary" type="submit">Buscar</button>
                </form>

                <a class="btn" href="/private/patients/create.php">Registrar nuevo paciente</a>
            </div>

            <div class="patients-card">
                <table class="table">
                    <thead>
                    <tr>
                        <th>No. Libro</th>
                        <th>Nombre</th>
                        <th>Cédula</th>
                        <th>Teléfono</th>
                        <th>Edad</th>
                        <th>Género</th>
                        <th>Sangre</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($patients)): ?>
                        <tr>
                            <td colspan="8" class="td-empty">
                                No hay pacientes<?= $search ? " con ese filtro." : "." ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($patients as $row): ?>
                            <?php
                            $id = (int)$row['id'];
                            $fullName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
                            ?>
                            <tr>
                                <td><?= h($row['no_libro'] ?? '') ?></td>
                                <td><?= h($fullName) ?></td>
                                <td><?= h($row['cedula'] ?? '') ?></td>
                                <td><?= h($row['phone'] ?? '') ?></td>
                                <td><?= h(calcAge($row['birth_date'] ?? null)) ?></td>
                                <td><?= h($row['gender'] ?? '') ?></td>
                                <td><?= h($row['blood_type'] ?? '') ?></td>
                                <td style="white-space:nowrap;">
                                    <a href="/private/patients/esquema.php?id=<?= $id ?>" style="font-weight:800;text-decoration:none;">Vacunas</a>
                                    <span style="opacity:.4;margin:0 8px;">·</span>
                                    <a href="/private/patients/edit.php?id=<?= $id ?>" style="font-weight:800;text-decoration:none;">Editar</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalRows > $perPage): ?>
                <div class="pagination-wrap">
                    <div class="pagination">
                        <a class="page-btn <?= ($page <= 1 ? 'disabled' : '') ?>"
                           href="<?= h(buildPageUrl(max(1, $page - 1), $search)) ?>">Anterior</a>

                        <?php
                        $window = 2;
                        $start = max(1, $page - $window);
                        $end   = min($totalPages, $page + $window);

                        if ($start > 1) {
                            echo '<a class="page-btn" href="' . h(buildPageUrl(1, $search)) . '">1</a>';
                            if ($start > 2) echo '<span class="page-info">…</span>';
                        }

                        for ($i = $start; $i <= $end; $i++) {
                            $active = ($i === $page) ? 'active' : '';
                            echo '<a class="page-btn ' . $active . '" href="' . h(buildPageUrl($i, $search)) . '">' . $i . '</a>';
                        }

                        if ($end < $totalPages) {
                            if ($end < $totalPages - 1) echo '<span class="page-info">…</span>';
                            echo '<a class="page-btn" href="' . h(buildPageUrl($totalPages, $search)) . '">' . $totalPages . '</a>';
                        }
                        ?>

                        <a class="page-btn <?= ($page >= $totalPages ? 'disabled' : '') ?>"
                           href="<?= h(buildPageUrl(min($totalPages, $page + 1), $search)) ?>">Siguiente</a>

                        <span class="page-info">
                            Página <?= (int)$page ?> de <?= (int)$totalPages ?> (<?= (int)$totalRows ?>)
                        </span>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </main>
</div>

<footer class="footer">
    © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>