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
        OR p.email LIKE ?
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
        p.email,
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
    <!-- MISMO CSS DEL DASHBOARD -->
    <link rel="stylesheet" href="/assets/css/styles.css?v=50">
    <link rel="stylesheet" href="/assets/css/paciente.css?v=2">

    <style>
        /* ✅ Esto arregla “lo mal puesto” SIN tocar sidebar/topbar del dashboard */
        .content{ width:100%; } /* por si tu CSS tiene align-items raro */
        .patients-page{
            max-width: 1200px;
            margin: 0 auto;
            padding: 24px 18px 40px;
        }
        .patients-header{
            text-align:center;
            margin: 10px 0 18px;
        }
        .patients-header h1{
            margin:0;
            font-size: 42px;
            font-weight: 900;
        }
        .patients-header p{
            margin: 6px 0 0;
            opacity: .75;
            font-weight: 600;
        }
        .patients-actions{
            display:flex;
            gap:12px;
            justify-content:center;
            flex-wrap:wrap;
            align-items:center;
            margin: 18px 0;
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
        }
        .patients-card{
            background:#fff;
            border-radius:14px;
            box-shadow:0 10px 25px rgba(0,0,0,.08);
            overflow:hidden;
        }
        .td-empty{
            text-align:center;
            padding: 22px 10px;
            opacity:.75;
            font-weight:700;
        }

        /* Paginación */
        .pagination-wrap{display:flex;justify-content:center;margin-top:14px;}
        .pagination{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:center;}
        .page-btn{
            display:inline-block;
            padding:8px 12px;
            border-radius:10px;
            text-decoration:none;
            font-weight:800;
            border:1px solid rgba(0,0,0,.12);
            background:#fff;
        }
        .page-btn.active{background:#0f4fa8;border-color:#0f4fa8;color:#fff;}
        .page-btn.disabled{opacity:.5;pointer-events:none;}
        .page-info{opacity:.75;font-weight:700;margin-left:8px;}

        @media (max-width: 900px){
            .patients-actions input[type="text"]{min-width: 240px; width: 75vw;}
        }
    </style>
</head>
<body>

<!-- ✅ TOPBAR EXACTO DEL DASHBOARD -->
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

    <!-- ✅ SIDEBAR EXACTO DEL DASHBOARD -->
    <aside class="sidebar">
        <div class="menu-title">Menú</div>

        <nav class="menu">
            <a href="/private/dashboard.php">🏠 Panel</a>
            <a class="active" href="/private/patients/index.php">👤 Pacientes</a>
            <a href="/private/citas/index.php">📅 Citas</a>
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
                        placeholder="Buscar por nombre, No. libro, cédula, teléfono, correo"
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
                        <th>Correo</th>
                        <th>Edad</th>
                        <th>Género</th>
                        <th>Sangre</th>
                        <th>Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($patients)): ?>
                        <tr>
                            <td colspan="9" class="td-empty">
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
                                <td><?= h($row['email'] ?? '') ?></td>
                                <td><?= h(calcAge($row['birth_date'] ?? null)) ?></td>
                                <td><?= h($row['gender'] ?? '') ?></td>
                                <td><?= h($row['blood_type'] ?? '') ?></td>
                                <td style="white-space:nowrap;">
                                    <a href="/private/patients/esquema.php?id=<?= $id ?>" style="font-weight:800;text-decoration:none;">Vacunas</a>
                                    <span style="opacity:.5;margin:0 8px;">·</span>
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

<!-- ✅ FOOTER EXACTO DEL DASHBOARD -->
<footer class="footer">
    © <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>
