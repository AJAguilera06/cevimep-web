<?php
session_start();
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/caja_lib.php";

if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }

$user = $_SESSION["user"];
$year = date("Y");

$isAdmin  = (($user["role"] ?? "") === "admin");
$branchId = (int)($user["branch_id"] ?? 0);
$userId   = (int)($user["id"] ?? 0);

if (!$isAdmin && $branchId <= 0) { header("Location: ../../public/logout.php"); exit; }

date_default_timezone_set("America/Santo_Domingo");
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$success = "";
$error = "";

$motivo = "";
$monto = "";

// ✅ Sesión automática del turno actual (sin abrir/cerrar manual)
$sessionId = caja_get_or_open_current_session($pdo, $branchId, $userId);
$currentCajaNum = caja_get_current_caja_num();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  try {
    $motivo = trim($_POST["motivo"] ?? "");
    $monto  = trim($_POST["monto"] ?? "");

    $amount = (float)str_replace([","," "], ["",""], $monto);

    if ($motivo === "") throw new Exception("Debes escribir el motivo.");
    if ($amount <= 0) throw new Exception("El monto debe ser mayor que 0.");

    // Si por alguna razón no hay sesión (fuera de horario), creamos una para no bloquear
    if ($sessionId <= 0) {
      $sessionId = caja_get_or_open_current_session($pdo, $branchId, $userId);
      if ($sessionId <= 0) {
        // fallback extremo: crear sesión de la caja "actual"
        $today = date("Y-m-d");
        $cajaNum = caja_get_current_caja_num();
        [$shiftStart, $shiftEnd] = caja_shift_times($cajaNum);

        $ins = $pdo->prepare("INSERT INTO cash_sessions
                                (branch_id, caja_num, shift_start, shift_end, date_open, opened_at, opened_by)
                              VALUES
                                (?, ?, ?, ?, ?, NOW(), ?)");
        $ins->execute([$branchId, $cajaNum, $shiftStart, $shiftEnd, $today, $userId]);
        $sessionId = (int)$pdo->lastInsertId();
      }
    }

    $st = $pdo->prepare("INSERT INTO cash_movements
                          (session_id, type, motivo, metodo_pago, amount, created_by)
                         VALUES
                          (?, 'desembolso', ?, 'efectivo', ?, ?)");
    $st->execute([$sessionId, $motivo, round($amount, 2), $userId]);

    $success = "Desembolso registrado.";
    $motivo = "";
    $monto = "";

  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Desembolso</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <style>
    html,body{height:100%;}
    body{margin:0; display:flex; flex-direction:column; min-height:100vh; overflow:hidden !important;}
    .app{flex:1; display:flex; min-height:0;}
    .main{flex:1; min-width:0; overflow:auto; padding:22px;}
    .menu a.active{background:#fff4e6;color:#b45309;border:1px solid #fed7aa;}

    .card{background:#fff;border:1px solid #e6eef7;border-radius:22px;padding:18px;box-shadow:0 10px 30px rgba(2,6,23,.08);}
    .muted{color:#6b7280; font-weight:700;}
    label{display:block; font-weight:900; margin-top:12px; color:#0b3b9a;}
    input[type="text"], input[type="number"]{
      width:100%; max-width:520px;
      padding:10px 12px;
      border:1px solid #e6eef7;
      border-radius:14px;
      outline:none;
    }
    .btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:14px;border:1px solid #dbeafe;background:#fff;color:#052a7a;font-weight:900;text-decoration:none;cursor:pointer;}
    .row{display:flex; gap:10px; flex-wrap:wrap; align-items:center;}
    .alert-ok{margin-top:10px; padding:10px 12px; border-radius:14px; background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; font-weight:900;}
    .alert-err{margin-top:10px; padding:10px 12px; border-radius:14px; background:#fff1f2; border:1px solid #fecdd3; color:#9f1239; font-weight:900;}
    .pill{display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px; background:#f3f7ff; border:1px solid #dbeafe; color:#052a7a; font-weight:900; font-size:12px;}
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
  <aside class="sidebar">
    <div class="title">Menú</div>
    <nav class="menu">
      <a href="../dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a href="../patients/index.php"><span class="ico">🧑‍🤝‍🧑</span> Pacientes</a>
      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;"><span class="ico">📅</span> Citas</a>
      <a href="../facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a class="active" href="index.php"><span class="ico">💳</span> Caja</a>
      <a href="../inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;"><span class="ico">⏳</span> Coming Soon</a>
    </nav>
  </aside>

  <section class="main">

    <div class="card">
      <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
        <div>
          <h2 style="margin:0; color:var(--primary-2);">Desembolso</h2>
          <div class="muted" style="margin-top:6px;">Registra salida de dinero (motivo y monto).</div>
          <div style="margin-top:10px;">
            <span class="pill">Caja activa ahora: <?php echo (int)$currentCajaNum; ?></span>
            <span class="pill">Sesión activa ID: <?php echo (int)$sessionId; ?></span>
          </div>
        </div>
        <div class="row">
          <a class="btn" href="index.php">Volver</a>
        </div>
      </div>

      <?php if ($success): ?><div class="alert-ok"><?php echo h($success); ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert-err"><?php echo h($error); ?></div><?php endif; ?>

      <form method="post" style="margin-top:12px;">
        <label>Motivo</label>
        <input type="text" name="motivo" value="<?php echo h($motivo); ?>" placeholder="Ej: Compra de agua, transporte, etc." required>

        <label>Monto (RD$)</label>
        <input type="text" name="monto" value="<?php echo h($monto); ?>" placeholder="Ej: 500" required>

        <div class="row" style="margin-top:14px;">
          <button class="btn" type="submit">Guardar</button>
          <a class="btn" href="index.php">Cancelar</a>
        </div>
      </form>

      <div class="muted" style="margin-top:12px;">
        * La caja se maneja automática por horario. No necesitas abrir ni cerrar manualmente.
      </div>
    </div>

  </section>
</main>

<footer class="footer">
  <div class="inner">© <?php echo $year; ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>

</body>
</html>
