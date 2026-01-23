<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); }

$user = $_SESSION["user"] ?? [];
$branch_id = (int)($user["branch_id"] ?? 0);

if ($branch_id <= 0) {
  http_response_code(400);
  die("Sucursal inválida.");
}

$search = trim($_GET["q"] ?? "");

/**
 * Listado de pacientes de ESTA SUCURSAL para facturar
 * (Ajusta nombres de campos si tu tabla patients usa otros)
 */
$params = [$branch_id];
$sql = "
  SELECT id,
         TRIM(CONCAT(first_name,' ',last_name)) AS full_name
  FROM patients
  WHERE branch_id = ?
";

if ($search !== "") {
  $sql .= " AND (first_name LIKE ? OR last_name LIKE ?)";
  $like = "%{$search}%";
  $params[] = $like;
  $params[] = $like;
}

$sql .= " ORDER BY id DESC LIMIT 60";

$st = $conn->prepare($sql);
$st->execute($params);
$patients = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Facturación</title>

  <!-- Estilo dashboard -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=50">
  <!-- Si tienes facturacion.css -->
  <link rel="stylesheet" href="/assets/css/facturacion.css?v=3">
</head>

<body>

<!-- TOPBAR igual -->
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

  <!-- SIDEBAR (igual que dashboard) -->
  <aside class="sidebar">
    <h3 class="menu-title">Menú</h3>
    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👤</span> Pacientes</a>
      <a href="/private/citas/index.php"><span class="ico">📅</span> Citas</a>
      <a class="active" href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💳</span> Caja</a>
      <a href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="/private/estadisticas/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">

    <section class="hero">
      <h1>Facturación</h1>
      <p>Selecciona un paciente para ver su historial y crear una factura.</p>
    </section>

    <div class="fact-wrap">
      <div class="fact-card">

        <div class="fact-card-head">
          <div>
            <div class="title">Pacientes</div>
            <div class="subtitle">Sucursal actual</div>
          </div>

          <form class="fact-search" method="GET">
            <input type="search" name="q" placeholder="Buscar paciente..." value="<?= h($search) ?>">
            <button class="btn-pill" type="submit">Buscar</button>
          </form>
        </div>

        <?php if (!$patients): ?>
          <div class="fact-empty">No hay pacientes para mostrar.</div>
        <?php else: ?>
          <?php foreach ($patients as $p): ?>
            <div class="fact-row">
              <div class="fact-name">
                <a href="/private/facturacion/paciente.php?patient_id=<?= (int)$p["id"] ?>">
                  <?= h($p["full_name"] ?: ("Paciente #".$p["id"])) ?>
                </a>
              </div>
              <a class="fact-badge ok" href="/private/facturacion/paciente.php?patient_id=<?= (int)$p["id"] ?>">
                Ver historial
              </a>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

      </div>
    </div>

  </main>
</div>

<div class="footer">
  <div class="inner">© <?= date("Y") ?> CEVIMEP. Todos los derechos reservados.</div>
</div>

</body>
</html>
