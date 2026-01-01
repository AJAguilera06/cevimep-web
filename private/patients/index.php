<?php
session_start();
if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }

require_once __DIR__ . "/../../config/db.php";

$isAdmin = (($_SESSION["user"]["role"] ?? "") === "admin");
$branchId = $_SESSION["user"]["branch_id"] ?? null;

if (!$isAdmin && empty($branchId)) {
  header("Location: ../../public/logout.php");
  exit;
}

function calcAge(?string $birthDate): string {
  if (!$birthDate) return "";
  try {
    $dob = new DateTime($birthDate);
    $now = new DateTime();
    return (string)$now->diff($dob)->y;
  } catch (Exception $e) {
    return "";
  }
}

$q = trim($_GET["q"] ?? "");

$where = [];
$params = [];

if (!$isAdmin) {
  $where[] = "branch_id = :bid";
  $params["bid"] = (int)$branchId;
}

if ($q !== "") {
  $where[] = "(first_name LIKE :q
            OR last_name LIKE :q
            OR cedula LIKE :q
            OR phone LIKE :q
            OR email LIKE :q
            OR blood_type LIKE :q
            OR gender LIKE :q)";
  $params["q"] = "%".$q."%";
}

$sql = "SELECT * FROM patients";
if (!empty($where)) {
  $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll();

$year = date("Y");
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CEVIMEP | Pacientes</title>

  <link rel="stylesheet" href="../../assets/css/styles.css">

  <style>
    html,body{height:100%;}
    body{margin:0; display:flex; flex-direction:column; min-height:100vh; overflow:hidden !important;}
    .app{flex:1; display:flex; min-height:0;}
    .main{flex:1; min-width:0; overflow:auto;}

    .menu a.active{
      background:#fff4e6;
      color:#b45309;
      border:1px solid #fed7aa;
    }

    .wrap{
      max-width:1200px;
      margin:auto;
      padding:22px;
      flex:1;
      display:flex;
      flex-direction:column;
      gap:18px;
    }

    .card{
      background:#fff;
      border:1px solid #e6eef7;
      border-radius:22px;
      padding:18px;
      box-shadow:0 10px 30px rgba(2,6,23,.08);
    }
  </style>
</head>

<body>

<header class="navbar">
  <div class="inner">
    <div></div>
    <div class="brand"><span class="dot"></span> CEVIMEP</div>
    <div class="nav-right"><a href="../../public/logout.php">Salir</a></div>
  </div>
</header>

<main class="app">

  <!-- ✅ MENU (ORDEN FIJO COMO TU IMAGEN) -->
  <aside class="sidebar">
    <div class="title">Menú</div>
    <nav class="menu">
      <a href="../dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a class="active" href="index.php"><span class="ico">🧑‍🤝‍🧑</span> Pacientes</a>
      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;"><span class="ico">📅</span> Citas</a>
      <a href="../facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a href="../caja/index.php"><span class="ico">💳</span> Caja</a>
      <a href="../inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="../estadistica/index.php"><span class="ico">⏳</span> Estadística</a>
    </nav>
  </aside>

  <section class="main">

    <div class="wrap">

      <div class="card">
        <div class="top" style="display:flex; justify-content:space-between; gap:14px; flex-wrap:wrap;">
          <div>
            <h2 style="margin:0; color:#052a7a;">Pacientes</h2>
            <div class="muted" style="color:#6b7280; font-weight:600;">Listado filtrado por sucursal (automático).</div>
          </div>

          <div class="actions" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:flex-end;">
            <form method="get" style="margin:0; display:flex; gap:10px; align-items:center;">
              <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Buscar por nombre, cédula, teléfono..." style="padding:10px 12px; border-radius:14px; border:1px solid #e6eef7; outline:none; min-width:260px;">
              <button class="btn" type="submit" style="display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:14px;border:1px solid #dbeafe;background:#fff;color:#052a7a;font-weight:900;text-decoration:none;cursor:pointer;">Buscar</button>
            </form>

            <a class="btn primary" href="create.php" style="display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:14px;border:none;color:#fff;background:linear-gradient(135deg,#0b4be3,#052a7a);font-weight:900;text-decoration:none;cursor:pointer;">+ Nuevo paciente</a>
            <a class="btn" href="../dashboard.php" style="display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:14px;border:1px solid #dbeafe;background:#fff;color:#052a7a;font-weight:900;text-decoration:none;cursor:pointer;">Volver</a>
          </div>
        </div>

        <table style="width:100%; border-collapse:collapse; margin-top:12px; overflow:hidden; border-radius:16px; border:1px solid #e6eef7;">
          <thead>
            <tr>
              <th style="padding:12px 10px; border-bottom:1px solid #eef2f7; text-align:left; font-size:13px; background:#f7fbff; color:#0b3b9a; font-weight:900;">ID</th>
              <th style="padding:12px 10px; border-bottom:1px solid #eef2f7; text-align:left; font-size:13px; background:#f7fbff; color:#0b3b9a; font-weight:900;">Nombre</th>
              <th style="padding:12px 10px; border-bottom:1px solid #eef2f7; text-align:left; font-size:13px; background:#f7fbff; color:#0b3b9a; font-weight:900;">Cédula</th>
              <th style="padding:12px 10px; border-bottom:1px solid #eef2f7; text-align:left; font-size:13px; background:#f7fbff; color:#0b3b9a; font-weight:900;">Teléfono</th>
              <th style="padding:12px 10px; border-bottom:1px solid #eef2f7; text-align:left; font-size:13px; background:#f7fbff; color:#0b3b9a; font-weight:900;">Correo</th>
              <th style="padding:12px 10px; border-bottom:1px solid #eef2f7; text-align:left; font-size:13px; background:#f7fbff; color:#0b3b9a; font-weight:900;">Edad</th>
              <th style="padding:12px 10px; border-bottom:1px solid #eef2f7; text-align:left; font-size:13px; background:#f7fbff; color:#0b3b9a; font-weight:900;">Género</th>
              <th style="padding:12px 10px; border-bottom:1px solid #eef2f7; text-align:left; font-size:13px; background:#f7fbff; color:#0b3b9a; font-weight:900;">Sangre</th>
              <th style="padding:12px 10px; border-bottom:1px solid #eef2f7; text-align:left; font-size:13px; background:#f7fbff; color:#0b3b9a; font-weight:900;">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($patients)): ?>
            <tr><td colspan="9" style="padding:12px 10px;">No hay pacientes registrados.</td></tr>
          <?php else: ?>
            <?php foreach ($patients as $p): ?>
              <tr>
                <td style="padding:12px 10px; border-bottom:1px solid #eef2f7; font-size:13px;"><?php echo (int)$p["id"]; ?></td>
                <td style="padding:12px 10px; border-bottom:1px solid #eef2f7; font-size:13px;"><?php echo htmlspecialchars(trim(($p["first_name"] ?? "")." ".($p["last_name"] ?? ""))); ?></td>
                <td style="padding:12px 10px; border-bottom:1px solid #eef2f7; font-size:13px;"><?php echo htmlspecialchars($p["cedula"] ?? ""); ?></td>
                <td style="padding:12px 10px; border-bottom:1px solid #eef2f7; font-size:13px;"><?php echo htmlspecialchars($p["phone"] ?? ""); ?></td>
                <td style="padding:12px 10px; border-bottom:1px solid #eef2f7; font-size:13px;"><?php echo htmlspecialchars($p["email"] ?? ""); ?></td>
                <td style="padding:12px 10px; border-bottom:1px solid #eef2f7; font-size:13px;"><?php echo htmlspecialchars(calcAge($p["birth_date"] ?? null)); ?></td>
                <td style="padding:12px 10px; border-bottom:1px solid #eef2f7; font-size:13px;"><?php echo htmlspecialchars($p["gender"] ?? ""); ?></td>
                <td style="padding:12px 10px; border-bottom:1px solid #eef2f7; font-size:13px;"><?php echo htmlspecialchars($p["blood_type"] ?? ""); ?></td>
                <td style="padding:12px 10px; border-bottom:1px solid #eef2f7; font-size:13px;">
                  <a href="edit.php?id=<?php echo (int)$p["id"]; ?>" style="margin-right:10px; font-weight:900; color:#0b4be3; text-decoration:none;">Editar</a>
                  <a href="delete.php?id=<?php echo (int)$p["id"]; ?>" onclick="return confirm('¿Eliminar paciente?');" style="font-weight:900; color:#0b4be3; text-decoration:none;">Eliminar</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>

      </div>

    </div>

  </section>
</main>

<footer class="footer">
  <div class="inner">© <?php echo $year; ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

</body>
</html>
