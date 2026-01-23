<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION["user"])) {
    header("Location: /login.php");
    exit;
}

$user = $_SESSION["user"];
$branch_id = (int)($user["branch_id"] ?? 0);

require __DIR__ . "/../config/db.php";

$q = trim((string)($_GET["q"] ?? ""));

$sql = "
SELECT
    id,
    no_libro,
    CONCAT(first_name, ' ', last_name) AS full_name,
    cedula,
    phone,
    email,
    birth_date,
    gender,
    blood_type
FROM patients
WHERE branch_id = :branch_id
";

$params = [
    ":branch_id" => $branch_id
];

if ($q !== "") {
    $sql .= "
    AND (
        no_libro LIKE :q
        OR first_name LIKE :q
        OR last_name LIKE :q
        OR cedula LIKE :q
        OR phone LIKE :q
        OR email LIKE :q
    )";
    $params[":q"] = "%{$q}%";
}

$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

function calcularEdad(?string $fecha): string {
    if (!$fecha) return "";
    $fn = new DateTime($fecha);
    return (string)$fn->diff(new DateTime())->y;
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>CEVIMEP | Pacientes</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="stylesheet" href="/assets/css/styles.css">
<link rel="stylesheet" href="/assets/css/paciente.css">
</head>

<body class="patients-page">

<!-- TOPBAR -->
<div class="navbar">
    <div class="inner">
        <div class="brand">
            <span class="dot"></span>
            <strong>CEVIMEP</strong>
        </div>
        <div class="nav-right">
            <a class="btn-pill" href="/logout.php">Salir</a>
        </div>
    </div>
</div>

<div class="layout">

<!-- SIDEBAR -->
<aside class="sidebar">
    <h3 class="menu-title">Menú</h3>
    <nav class="menu">
        <a href="/private/dashboard.php">🏠 Panel</a>
        <a class="active" href="/private/patients/index.php">👤 Pacientes</a>
        <a href="/private/citas/index.php">📅 Citas</a>
        <a href="/private/facturacion/index.php">🧾 Facturación</a>
        <a href="/private/caja/index.php">💳 Caja</a>
        <a href="/private/inventario/index.php">📦 Inventario</a>
        <a href="/private/estadisticas/index.php">📊 Estadísticas</a>
    </nav>
</aside>

<!-- CONTENIDO -->
<main class="content">

<div class="patients-wrap">
<div class="patients-card">

    <div class="patients-head">
        <h1>Pacientes</h1>
        <div class="sub">Listado filtrado por sucursal (automático).</div>
    </div>

    <div class="patients-actions">
        <form method="GET" style="display:flex; gap:10px;">
            <input
                class="search-input"
                type="text"
                name="q"
                value="<?= htmlspecialchars($q) ?>"
                placeholder="Buscar por nombre, No. libro, cédula, teléfono..."
            >
            <button class="btn btn-outline">Buscar</button>
        </form>

        <a class="link" href="/private/patients/create.php">Registrar nuevo paciente</a>
        <a class="link" href="/private/dashboard.php">Volver</a>
    </div>

    <div class="table-wrap">
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

            <?php if (!$patients): ?>
                <tr>
                    <td colspan="9" style="text-align:center;padding:20px;">
                        No hay pacientes registrados en esta sucursal.
                    </td>
                </tr>
            <?php else: foreach ($patients as $p): ?>
                <tr>
                    <td><?= htmlspecialchars((string)$p["no_libro"]) ?></td>
                    <td><?= htmlspecialchars((string)$p["full_name"]) ?></td>
                    <td><?= htmlspecialchars((string)$p["cedula"]) ?></td>
                    <td><?= htmlspecialchars((string)$p["phone"]) ?></td>
                    <td><?= htmlspecialchars((string)$p["email"]) ?></td>
                    <td><?= calcularEdad($p["birth_date"]) ?></td>
                    <td><?= htmlspecialchars((string)$p["gender"]) ?></td>
                    <td><?= htmlspecialchars((string)$p["blood_type"]) ?></td>
                    <td class="td-actions">
                        <a href="/private/patients/view.php?id=<?= (int)$p["id"] ?>">Ver</a>
                        <a href="/private/patients/edit.php?id=<?= (int)$p["id"] ?>">Editar</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>

            </tbody>
        </table>
    </div>

</div>
</div>

</main>
</div>

<div class="footer">
    © <?= date("Y") ?> CEVIMEP. Todos los derechos reservados.
</div>

</body>
</html>
