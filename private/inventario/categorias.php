<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
$conn = $pdo;

$user = $_SESSION["user"];

$year = (int)date("Y");

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

$error = "";
$success = "";

/**
 * Helpers BD (para evitar 500 si cambia el esquema)
 */
function table_has_column(PDO $conn, string $table, string $column): bool {
  try {
    $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Exception $e) {
    return false;
  }
}

$has_branch = table_has_column($conn, "inventory_categories", "branch_id");
$branch_id = (int)($user["branch_id"] ?? 0);

/* Crear categoría */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "create") {
  $name = trim((string)($_POST["name"] ?? ""));
  if ($name === "") {
    $error = "Debes escribir el nombre de la categoría.";
  } else {
    try {
      if ($has_branch) {
        $st = $conn->prepare("INSERT INTO inventory_categories (name, branch_id) VALUES (?, ?)");
        $st->execute([$name, $branch_id]);
      } else {
        $st = $conn->prepare("INSERT INTO inventory_categories (name) VALUES (?)");
        $st->execute([$name]);
      }
      $success = "Categoría creada.";
    } catch (Exception $e) {
      $error = "No se pudo crear la categoría.";
    }
  }
}

/* Eliminar categoría */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "delete") {
  $id = (int)($_POST["id"] ?? 0);
  if ($id > 0) {
    try {
      $st = $conn->prepare("DELETE FROM inventory_categories WHERE id=?");
      $st->execute([$id]);
      $success = "Categoría eliminada.";
    } catch (Exception $e) {
      $error = "No se pudo eliminar la categoría (puede estar en uso).";
    }
  }
}

/* Listado categorías */
try {
  if ($has_branch) {
    $st = $conn->prepare("SELECT id, name FROM inventory_categories WHERE branch_id=? ORDER BY name ASC");
    $st->execute([$branch_id]);
  } else {
    $st = $conn->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
  }
  $cats = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Exception $e) {
  $cats = [];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Categorías - Inventario</title>
  <link rel="stylesheet" href="/assets/css/styles.css?v=60">
  <style>
    .content-wrap{padding:22px 24px;}
    .page-title{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:12px}
    .page-title h1{margin:0;font-size:30px;font-weight:800;color:#0b2b4a}
    .subtitle{margin:2px 0 0;color:#5b6b7a;font-size:13px}
    .toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:flex-end}
    .input{height:38px;border:1px solid #d8e1ea;border-radius:12px;padding:0 12px;background:#fff;outline:none;min-width:260px}
    .btn{height:38px;border:none;border-radius:12px;padding:0 14px;font-weight:800;cursor:pointer}
    .btn-primary{background:#e8f4ff;color:#0b4d87}
    .btn-secondary{background:#eef2f6;color:#2b3b4a}
    .card{background:#fff;border-radius:16px;box-shadow:0 12px 30px rgba(0,0,0,.08);padding:14px 14px;margin-top:12px}
    table{width:100%;border-collapse:separate;border-spacing:0}
    th,td{padding:12px 10px;border-bottom:1px solid #eef2f6;font-size:13px}
    th{color:#0b4d87;text-align:left;font-weight:800;font-size:12px;letter-spacing:.2px}
    tr:last-child td{border-bottom:none}
    .flash-ok{background:#e9fff1;border:1px solid #a7f0bf;color:#0a7a33;border-radius:12px;padding:10px 12px;font-size:13px;margin-top:10px}
    .flash-err{background:#ffecec;border:1px solid #ffb6b6;color:#a40000;border-radius:12px;padding:10px 12px;font-size:13px;margin-top:10px}
    .btn-mini{height:32px;border-radius:10px;padding:0 10px;font-size:12px}
    .btn-danger{background:#ffecec;color:#a40000}
  </style>
</head>
<body>

  <div class="topbar">
    <div class="topbar-inner">
      <div class="brand">
        <span class="dot"></span>
        <span class="name">CEVIMEP</span>
      </div>
      <div class="right">
        <a class="logout" href="/logout.php">Salir</a>
      </div>
    </div>
  </div>

  <div class="layout">
    <aside class="sidebar">
      <div class="sidebar-title">Menú</div>
      <nav class="menu">
        <a class="menu-item" href="/private/dashboard.php">🏠 Panel</a>
        <a class="menu-item" href="/private/patients/index.php">👤 Pacientes</a>
        <a class="menu-item" href="/private/citas/index.php">📅 Citas</a>
        <a class="menu-item" href="/private/facturacion/index.php">🧾 Facturación</a>
        <a class="menu-item" href="/private/caja/index.php">💵 Caja</a>
        <a class="menu-item active" href="/private/inventario/index.php">📦 Inventario</a>
        <a class="menu-item" href="/private/estadisticas/index.php">📊 Estadísticas</a>
      </nav>
    </aside>

    <main class="main">
      <div class="content-wrap">

        <div class="page-title">
          <div>
            <h1>Categorías</h1>
            <div class="subtitle">Gestiona tus categorías de inventario.</div>
          </div>
          <div class="toolbar">
            <a class="btn btn-secondary" href="/private/inventario/items.php">Volver a productos</a>
          </div>
        </div>

        <?php if ($success): ?>
          <div class="flash-ok"><?= h($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="flash-err"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="card">
          <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="action" value="create">
            <input class="input" type="text" name="name" placeholder="Nombre de la categoría...">
            <button class="btn btn-primary" type="submit">Guardar</button>
          </form>

          <div style="height:12px"></div>

          <table>
            <thead>
              <tr>
                <th>Nombre</th>
                <th style="text-align:right">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$cats): ?>
                <tr><td colspan="2">No hay categorías registradas.</td></tr>
              <?php else: ?>
                <?php foreach ($cats as $c): ?>
                  <tr>
                    <td><b><?= h($c["name"]) ?></b></td>
                    <td style="text-align:right">
                      <form method="post" onsubmit="return confirm('¿Eliminar esta categoría?')" style="display:inline">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$c["id"] ?>">
                        <button class="btn-mini btn-danger" type="submit">Eliminar</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </main>
  </div>

  <footer class="footer">
    © <?= (int)$year ?> CEVIMEP. Todos los derechos reservados.
  </footer>

</body>
</html>
