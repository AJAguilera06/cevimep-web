<?php
session_start();
require_once __DIR__ . "/../../config/db.php";
if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }
$year = date("Y");
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$error=""; $success="";

if ($_SERVER["REQUEST_METHOD"]==="POST") {
  $action = $_POST["action"] ?? "";
  $name = trim($_POST["name"] ?? "");
  $id = (int)($_POST["id"] ?? 0);

  try{
    if ($action==="create") {
      if ($name==="") throw new Exception("Nombre requerido.");
      $st=$pdo->prepare("INSERT INTO inventory_categories (name) VALUES (:n)");
      $st->execute(["n"=>$name]);
      $success="Categoría creada.";
    }
    if ($action==="delete") {
      if ($id<=0) throw new Exception("ID inválido.");
      $st=$pdo->prepare("UPDATE inventory_categories SET is_active=0 WHERE id=:id");
      $st->execute(["id"=>$id]);
      $success="Categoría desactivada.";
    }
  } catch(Throwable $e){ $error=$e->getMessage(); }
}

$cats=$pdo->query("SELECT * FROM inventory_categories WHERE is_active=1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Categorías</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <style>
    html,body{height:100%;}
    body{display:flex;flex-direction:column;min-height:100vh;overflow:hidden;}
    .app{flex:1;display:flex;min-height:0;}
    .main{flex:1;min-width:0;overflow:auto;padding:26px 22px 32px;}
    .msg{margin:10px 0 0;padding:10px 12px;border-radius:14px;font-weight:900;font-size:13px;}
    .ok{background:#eafff7;border:1px solid rgba(20,184,166,.35);color:#065f46;}
    .err{background:#ffe8e8;border:1px solid #ffb2b2;color:#7a1010;}
    /* respetar estilo base del dashboard (botones del styles.css) */
    .input{width:100%;padding:11px 12px;border-radius:14px;border:1px solid var(--border);font-size:14px;}
    table{width:100%;border-collapse:separate;border-spacing:0 10px;}
    .row{background:#fff;border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow-soft);}
    .row td{padding:12px;}

    .invTabs{display:flex;gap:10px;flex-wrap:wrap;margin:6px 0 14px;}
    .invTabs a{display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:14px;border:1px solid rgba(2,21,44,.12);background:#fff;color:#0b2a4a;text-decoration:none;font-weight:900;}
    .invTabs a.active{background:rgba(127,178,255,.18);border-color:rgba(127,178,255,.45);}
    .btnDanger{background:linear-gradient(135deg,#ef4444,#991b1b) !important;}
  </style>
</head>
<body>
<header class="navbar"><div class="inner"><div></div><div class="brand"><span class="dot"></span> CEVIMEP</div><div class="nav-right"><a href="../../public/logout.php">Salir</a></div></div></header>

<main class="app">
  <!-- Sidebar global (igual al dashboard.php) -->
  <aside class="sidebar">
    <div class="title">Menú</div>
    <nav class="menu">
      <a href="../dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="../patients/index.php"><span class="ico">🧑‍🤝‍🧑</span> Pacientes</a>

      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;">
        <span class="ico">📅</span> Citas
      </a>
      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;">
        <span class="ico">🧾</span> Facturación
      </a>

      <a href="../caja/index.php"><span class="ico">💳</span> Caja</a>
      <a class="active" href="items.php"><span class="ico">📦</span> Inventario</a>

      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;">
        <span class="ico">⏳</span> Coming Soon
      </a>
    </nav>
  </aside>

  <section class="main">
    <div class="card">
      <div class="invTabs">
        <a class="active" href="categorias.php"><span class="ico">🏷️</span> Categorías</a>
        <a href="items.php"><span class="ico">🧾</span> Inventario</a>
        <a href="entrada.php"><span class="ico">📥</span> Entrada</a>
        <a href="salida.php"><span class="ico">📤</span> Salida</a>
      </div>

      <h3>Categorías</h3>
      <p class="muted">Ej: Vacunas, Productos, Insumos…</p>

      <?php if($success): ?><div class="msg ok"><?php echo h($success); ?></div><?php endif; ?>
      <?php if($error): ?><div class="msg err"><?php echo h($error); ?></div><?php endif; ?>

      <form method="post" style="margin-top:12px; display:flex; gap:10px; align-items:center;">
        <input type="hidden" name="action" value="create">
        <input class="input" name="name" placeholder="Nueva categoría..." required>
        <button class="btn" type="submit">Agregar</button>
      </form>

      <table style="margin-top:12px;">
        <thead><tr><th>Nombre</th><th style="width:120px;">Acción</th></tr></thead>
        <tbody>
          <?php foreach($cats as $c): ?>
            <tr class
