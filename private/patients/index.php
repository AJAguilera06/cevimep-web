<?php
declare(strict_types=1);

/* ===============================
   Sesión
   =============================== */
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/* ===============================
   Protección de acceso
   =============================== */
if (!isset($_SESSION["user"])) {
  header("Location: /login.php");
  exit;
}

$user = $_SESSION["user"];
$rol = $user["role"] ?? "";
$sucursal_id = (int)($user["branch_id"] ?? 0);
$nombre = $user["full_name"] ?? "Usuario";

/* ===============================
   DB
   =============================== */
require __DIR__ . "/../config/db.php";

/* ===============================
   Búsqueda (opcional)
   =============================== */
$q = trim((string)($_GET["q"] ?? ""));

$sql = "SELECT id, book_no, full_name, cedula, phone, email, age, gender, blood_type
        FROM patients
        WHERE branch_id = :branch_id";

$params = [":branch_id" => $sucursal_id];

if ($q !== "") {
  $sql .= " AND (
      full_name LIKE :q
      OR cedula LIKE :q
      OR phone LIKE :q
      OR email LIKE :q
      OR book_no LIKE :q
  )";
  $params[":q"] = "%{$q}%";
}

$sql .= " ORDER BY id DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Pacientes</title>

  <!-- Base del sistema -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=40">
  <!-- Estilos específicos de Pacientes -->
  <link rel="stylesheet" href="/assets/css/paciente.css?v=40">
</head>

<body class="patients-page">

<!-- NAVBAR -->
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

<!-- LAYOUT -->
<div class="layout">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <h3 class="menu-title">Menú</h3>

    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a class="active" href="/private/patients/index.php"><span class="ico">👤</span> Pacientes</a>
      <a href="/private/citas/index.php"><span class="ico">📅</span> Citas</a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💳</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadisticas/index.php"><span class="ico">📊</span> Estadísticas</a>
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
          <form method="GET" action="" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <input
              class="search-input"
              type="text"
              name="q"
              value="<?= htmlspecialchars($q) ?>"
              placeholder="Buscar por nombre, cédula, teléfono..."
            />
            <button class="btn btn-outline" type="submit">Buscar</button>
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
                  <td colspan="9" style="text-align:center; padding:18px;">
                    No hay pacientes registrados en esta sucursal.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($patients as $p): ?>
                  <tr>
                    <td><?= htmlspecialchars((string)($p["book_no"] ?? "")) ?></td>
                    <td><?= htmlspecialchars((string)($p["full_name"] ?? "")) ?></td>
                    <td><?= htmlspecialchars((string)($p["cedula"] ?? "")) ?></td>
                    <td><?= htmlspecialchars((string)($p["phone"] ?? "")) ?></td>
                    <td><?= htmlspecialchars((string)($p["email"] ?? "")) ?></td>
                    <td><?= htmlspecialchars((string)($p["age"] ?? "")) ?></td>
                    <td><?= htmlspecialchars((string)($p["gender"] ?? "")) ?></td>
                    <td><?= htmlspecialchars((string)($p["blood_type"] ?? "")) ?></td>
                    <td>
                      <div class="td-actions">
                        <a class="a-action" href="/private/patients/view.php?id=<?= (int)$p["id"] ?>">Ver</a>
                        <a class="a-action" href="/private/patients/edit.php?id=<?= (int)$p["id"] ?>">Editar</a>
                      </div>
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

<!-- FOOTER -->
<div class="footer">
  <div class="inner">
    © <?= (int)date("Y") ?> CEVIMEP. Todos los derechos reservados.
  </div>
</div>

</body>
</html>
