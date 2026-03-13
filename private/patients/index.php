<?php
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

/* DB */
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

/* BUSCADOR */
$search = trim((string)($_GET['q'] ?? ''));

/* PAGINACIÓN */
$perPage = 8;
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $perPage;

/* WHERE */
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
    $params = array_merge($params, [$like,$like,$like,$like,$like]);
}

/* TOTAL */
$countSql = "SELECT COUNT(*) FROM patients p {$where}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$totalPages = (int)ceil($totalRows / $perPage);
if ($totalPages < 1) $totalPages = 1;

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
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Pacientes | CEVIMEP</title>
<link rel="stylesheet" href="/assets/css/styles.css?v=50">
<link rel="stylesheet" href="/assets/css/paciente.css?v=2">
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
<a class="active" href="/private/patients/index.php">👤 Pacientes</a>
<a href="/private/citas/index.php">📅 Citas</a>
<a href="/private/facturacion/index.php">🧾 Facturación</a>
<a href="/private/caja/index.php">💳 Caja</a>
<a href="/private/inventario/index.php">📦 Inventario</a>
<a href="/private/estadistica/index.php">📊 Estadísticas</a>
</nav>
</aside>

<main class="content">

<h1>Pacientes</h1>

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

<?php foreach ($patients as $row): ?>
<?php
$id = (int)$row['id'];
$fullName = trim($row['first_name'].' '.$row['last_name']);
?>

<tr>
<td><?= h($row['no_libro']) ?></td>
<td><?= h($fullName) ?></td>
<td><?= h($row['cedula']) ?></td>
<td><?= h($row['phone']) ?></td>
<td><?= h($row['email']) ?></td>
<td><?= h(calcAge($row['birth_date'])) ?></td>
<td><?= h($row['gender']) ?></td>
<td><?= h($row['blood_type']) ?></td>

<td style="white-space:nowrap;">

<a href="/private/patients/esquema.php?id=<?= $id ?>" style="font-weight:800;text-decoration:none;">
Vacunas
</a>

<span style="opacity:.5;margin:0 8px;">·</span>

<a href="/private/patients/edit.php?id=<?= $id ?>" style="font-weight:800;text-decoration:none;">
Editar
</a>

</td>
</tr>

<?php endforeach; ?>

</tbody>
</table>

</main>
</div>

<footer class="footer">
© <?= date('Y') ?> CEVIMEP — Todos los derechos reservados.
</footer>

</body>
</html>