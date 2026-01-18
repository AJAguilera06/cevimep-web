<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . "/../../config/db.php";

if (empty($_SESSION["user"])) {
  header("Location: /login.php");
  exit;
}

$year = (int)date("Y");

function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");
}

$error = "";
$success = "";

/**
 * Helpers BD (para evitar 500 si cambia el esquema)
 */
function table_has_column(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*) 
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = :t
        AND COLUMN_NAME = :c
      LIMIT 1
    ");
    $st->execute(["t" => $table, "c" => $column]);
    return ((int)$st->fetchColumn()) > 0;
  } catch (Throwable $e) {
    // Si Railway restringe INFORMATION_SCHEMA, asumimos que no existe para no romper.
    return false;
  }
}

$hasIsActive = table_has_column($pdo, "inventory_categories", "is_active");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = $_POST["action"] ?? "";
  $name   = trim($_POST["name"] ?? "");
  $id     = (int)($_POST["id"] ?? 0);

  try {
    if ($action === "create") {
      if ($name === "") throw new Exception("Nombre requerido.");

      // Si existe is_active, intentamos reactivar si ya existe por nombre (opcional)
      // y si no, insert normal.
      $pdo->beginTransaction();

      // Insert simple
      $st = $pdo->prepare("INSERT INTO inventory_categories (name" . ($hasIsActive ? ", is_active" : "") . ") VALUES (:n" . ($hasIsActive ? ", 1" : "") . ")");
      $st->execute(["n" => $name]);

      $pdo->commit();
      $success = "Categoría creada.";
    }

    if ($action === "delete") {
      if ($id <= 0) throw new Exception("ID inválido.");

      if ($hasIsActive) {
        $st = $pdo->prepare("UPDATE inventory_categories SET is_active = 0 WHERE id = :id");
        $st->execute(["id" => $id]);
      } else {
        // Fallback: delete real si no existe is_active
        $st = $pdo->prepare("DELETE FROM inventory_categories WHERE id = :id");
        $st->execute(["id" => $id]);
      }

      $success = "Categoría eliminada.";
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
  }
}

/**
 * Obtener categorías sin romper si is_active no existe
 */
try {
  if ($hasIsActive) {
    $st = $pdo->query("SELECT id, name FROM inventory_categories WHERE is_active = 1 ORDER BY name ASC");
  } else {
    $st = $pdo->query("SELECT id, name FROM inventory_categories ORDER BY name ASC");
  }
  $cats = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
  // Último fallback para evitar 500
  $cats = [];
  $error = $error ?: "Error cargando categorías.";
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Categorías</title>

  <!-- EXACTO igual que dashboard.php -->
  <link rel="stylesheet" href="/assets/css/styles.css?v=11">

  <style>
    /* Tabs internos (sin sidebar extra) */
    .invTabs{ display:flex; gap:10px; flex-wrap:wrap; margin:10px 0 16px; }
    .invTabs a{
      display:inline-flex; align-items:center; gap:8px;
      padding:10px 12px; border-radius:14px;
      border:1px solid rgba(2,21,44,.12);
      background:#fff; color:#0b2a4a;
      text-decoration:none; font-weight:900;
    }
    .invTabs a.active{
      background:rgba(127,178,255,.18);
      border-color:rgba(127,178,255,.45);
    }

    .msg{ margin:10px 0 0; padding:10px 12px; border-radius:14px; font-weight:900; font-size:13px; }
    .ok{ background:#eafff7; border:1px solid rgba(20,184,166,.35); color:#065f46; }
    .err{ background:#ffe8e8; border:1px solid #ffb2b2; color:#7a1010; }

    .input{
      width:100%; padding:11px 12px; border-radius:14px;
      border:1px solid rgba(2,21,44,.12);
      font-size:14px; font-weight:800;
      outline:none;
    }
    .input:focus{
      border-color:#7fb2ff;
      box-shadow:0 0 0 3px rgba(127,178,255,.20);
    }

    table{ width:100%; border-collapse:separate; border-spacing:0 10px; }
    .row{
      background:#fff; border:1px solid rgba(2,21,44,.12);
      border-radius:18px; overflow:hidden;
    }
    .row td{ padding:12px; font-weight:800; }
    .btnDanger{ background:linear-gradient(135deg,#ef4444,#991b1b) !important; }
  </style>
</head>

<body>

<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right">
      <a class="btn-pill" href="/logout.php">Salir</a>
    </div>
  </div>
</header>

<div class="layout">

  <!-- Sidebar EXACTO igual al dashboard.php -->
  <aside class="sidebar">
    <div class="menu-title">Menú</div>

    <nav class="menu">
      <a href="/private/dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="/private/patients/index.php"><span class="ico">👥</span> Pacientes</a>
      <a href="javascript:void(0)" style="opacity:.45; cursor:not-allowed;">
        <span class="ico">🗓️</span> Citas
      </a>
      <a href="/private/facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="/private/caja/index.php"><span class="ico">💵</span> Caja</a>

      <!-- Inventario activo -->
      <a class="active" href="/private/inventario/index.php"><span class="ico">📦</span> Inventario</a>

      <a href="/private/estadistica/index.php"><span class="ico">📊</span> Estadísticas</a>
    </nav>
  </aside>

  <main class="content">

    <section class="hero">
      <h1>Inventario</h1>
      <p>Categorías</p>
    </section>

    <div class="card">
      <div class="invTabs">
        <a class="active" href="/private/inventario/categorias.php"><span class="ico">🏷️</span> Categorías</a>
        <a href="/private/inventario/items.php"><span class="ico">🧾</span> Inventario</a>
        <a href="/private/inventario/entrada.php"><span class="ico">📥</span> Entrada</a>
        <a href="/private/inventario/salida.php"><span class="ico">📤</span> Salida</a>
      </div>

      <h3>Categorías</h3>
      <p class="muted">Ej: Vacunas, Productos, Insumos…</p>

      <?php if ($success): ?><div class="msg ok"><?= h($success) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="msg err"><?= h($error) ?></div><?php endif; ?>

      <form method="post" style="margin-top:12px; display:flex; gap:10px; align-items:center;">
        <input type="hidden" name="action" value="create">
        <input class="input" name="name" placeholder="Nueva categoría..." required>
        <button class="btn-pill" type="submit">Agregar</button>
      </form>

      <table style="margin-top:12px;">
        <thead>
          <tr>
            <th style="text-align:left;">Nombre</th>
            <th style="width:140px; text-align:right;">Acción</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cats as $c): ?>
            <tr class="row">
              <td><b><?= h($c["name"]) ?></b></td>
              <td style="text-align:right;">
                <form method="post" onsubmit="return confirm('¿Eliminar categoría?');" style="display:inline;">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$c["id"] ?>">
                  <button class="btn-pill btnDanger" type="submit">Eliminar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (count($cats) === 0): ?>
            <tr><td colspan="2" class="muted">No hay categorías.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </main>
</div>

<footer class="footer">
  <div class="footer-inner">© <?= $year ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

</body>
</html>
