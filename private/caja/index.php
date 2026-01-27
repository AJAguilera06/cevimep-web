<?php
declare(strict_types=1);

require_once __DIR__ . "/../_guard.php";
require_once __DIR__ . "/caja_lib.php"; // ✅ aquí era el fallo original en muchos casos

if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }

$user = $_SESSION["user"];
$isAdmin  = (($user["role"] ?? "") === "admin");
$branchId = (int)($user["branch_id"] ?? 0);
$userId   = (int)($user["id"] ?? 0);

if (!$isAdmin && $branchId <= 0) { header("Location: ../../public/logout.php"); exit; }

date_default_timezone_set("America/Santo_Domingo");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return number_format((float)$n, 2, ".", ","); }

$today = date("Y-m-d");
$year  = date("Y");

// Nombre de sucursal
$branchName = "Sucursal #".$branchId;
try {
  $stB = $pdo->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branchId]);
  $bn = $stB->fetchColumn();
  if ($bn) $branchName = (string)$bn;
} catch (Throwable $e) {}

// Sesión actual (auto)
$activeSessionId = caja_get_or_open_current_session($pdo, $branchId, $userId);
$currentCajaNum  = caja_get_current_caja_num();

// Totales por sesión (si existe cash_movements)
function getTotals(PDO $pdo, int $sessionId): array {
  try {
    $st = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='efectivo' THEN amount END),0) AS efectivo,
        COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='tarjeta' THEN amount END),0) AS tarjeta,
        COALESCE(SUM(CASE WHEN type='ingreso' AND metodo_pago='transferencia' THEN amount END),0) AS transferencia,
        COALESCE(SUM(CASE WHEN type='desembolso' THEN amount END),0) AS desembolso
      FROM cash_movements
      WHERE session_id=?");
    $st->execute([$sessionId]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"desembolso"=>0];
  } catch (Throwable $e) {
    $r = ["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"desembolso"=>0];
  }

  $ing = (float)$r["efectivo"] + (float)$r["tarjeta"] + (float)$r["transferencia"];
  $net = $ing - (float)$r["desembolso"];
  return [$r,$ing,$net];
}

// Buscar sesiones del día (Caja 1 y Caja 2)
function getSession(PDO $pdo, int $branchId, int $cajaNum, string $date, string $shiftStart, string $shiftEnd){
  try {
    $st = $pdo->prepare("SELECT * FROM cash_sessions
                         WHERE branch_id=? AND date_open=? AND caja_num=? AND shift_start=? AND shift_end=?
                         ORDER BY id DESC LIMIT 1");
    $st->execute([$branchId, $date, $cajaNum, $shiftStart, $shiftEnd]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

[$s1Start,$s1End] = ["08:00:00","13:00:00"];
[$s2Start,$s2End] = ["13:00:00","18:00:00"];

$caja1 = getSession($pdo, $branchId, 1, $today, $s1Start, $s1End);
$caja2 = getSession($pdo, $branchId, 2, $today, $s2Start, $s2End);

$sum = [
  1 => ["r"=>["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"desembolso"=>0], "ing"=>0, "net"=>0],
  2 => ["r"=>["efectivo"=>0,"tarjeta"=>0,"transferencia"=>0,"desembolso"=>0], "ing"=>0, "net"=>0],
];

if ($caja1) { [$r,$ing,$net] = getTotals($pdo, (int)$caja1["id"]); $sum[1]=["r"=>$r,"ing"=>$ing,"net"=>$net]; }
if ($caja2) { [$r,$ing,$net] = getTotals($pdo, (int)$caja2["id"]); $sum[2]=["r"=>$r,"ing"=>$ing,"net"=>$net]; }

// Estado real (si id=0 => fuera de horario / sin sesión abierta)
$isOpen = ($activeSessionId > 0);

$current = "caja";
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Caja</title>

  <!-- ✅ usa tu ruta real: public/assets/css -->
  <link rel="stylesheet" href="../../public/assets/css/caja.css?v=2">
</head>

<body>
<header class="topbar">
  <div class="topbar__inner">
    <div class="brand">
      <span class="brand__dot"></span>
      <span class="brand__text">CEVIMEP</span>
    </div>

    <div class="topbar__title">
      Caja <span class="muted">|</span> <?= h($branchName) ?>
    </div>

    <a class="topbar__link" href="../../public/logout.php">Salir</a>
  </div>
</header>

<div class="layout">
  <aside class="sidebar">
    <div class="sidebar__title">Menú</div>

    <!-- ✅ ORDEN “dashboard style” -->
    <nav class="menu">
      <a class="<?= ($current==='dashboard'?'active':'') ?>" href="../dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a class="<?= ($current==='facturacion'?'active':'') ?>" href="../facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a class="<?= ($current==='inventario'?'active':'') ?>" href="../inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a class="<?= ($current==='caja'?'active':'') ?>" href="./index.php"><span class="ico">💳</span> Caja</a>
      <a class="<?= ($current==='pacientes'?'active':'') ?>" href="../patients/index.php"><span class="ico">🧑‍🤝‍🧑</span> Pacientes</a>
      <a class="disabled" href="#" onclick="return false;"><span class="ico">📅</span> Citas</a>
      <a class="<?= ($current==='estadistica'?'active':'') ?>" href="../estadistica/index.php"><span class="ico">📊</span> Estadística</a>
    </nav>

    <div class="sidebar__footer">
      <div class="chip">
        <div class="chip__label">Sucursal</div>
        <div class="chip__value"><?= h($branchName) ?></div>
      </div>
    </div>
  </aside>

  <main class="content">

    <div class="page-head">
      <h1 class="page-title">Caja</h1>
      <div class="page-subtitle"><?= h($branchName) ?> · <?= h($today) ?></div>
    </div>

    <section class="grid">
      <div class="card">
        <div class="card__head">
          <div class="card__title">Estado de Caja</div>
          <div class="badge <?= $isOpen ? 'badge--ok' : 'badge--warn' ?>">
            <?= $isOpen ? 'Abierta' : 'Fuera de horario' ?>
          </div>
        </div>

        <div class="kv">
          <div class="kv__row">
            <div class="kv__k">Sesión activa</div>
            <div class="kv__v"><?= $isOpen ? ("#".h((string)$activeSessionId)) : "<span class='muted'>N/D</span>" ?></div>
          </div>
          <div class="kv__row">
            <div class="kv__k">Turno</div>
            <div class="kv__v">Caja <?= (int)$currentCajaNum ?></div>
          </div>
          <div class="kv__row">
            <div class="kv__k">Apertura</div>
            <div class="kv__v"><?= ($isOpen && $currentCajaNum===1 && $caja1) ? h($caja1["opened_at"] ?? "—") : (($isOpen && $currentCajaNum===2 && $caja2) ? h($caja2["opened_at"] ?? "—") : "<span class='muted'>N/D</span>") ?></div>
          </div>
        </div>

        <div class="card__actions">
          <a class="btn btn--primary" href="../facturacion/index.php">Ir a Facturación</a>
          <a class="btn btn--ghost" href="../dashboard.php">Volver al Panel</a>
        </div>

        <div class="note">
          * Las cajas se abren y cierran automáticamente por horario (sin botones).
        </div>
      </div>

      <div class="card">
        <div class="card__head">
          <div class="card__title">Resumen de Hoy</div>
          <div class="badge badge--info">RD$</div>
        </div>

        <div class="stats">
          <div class="stat">
            <div class="stat__label">Entradas</div>
            <div class="stat__value"><?= money($sum[1]["ing"] + $sum[2]["ing"]) ?></div>
          </div>
          <div class="stat">
            <div class="stat__label">Salidas</div>
            <div class="stat__value"><?= money($sum[1]["r"]["desembolso"] + $sum[2]["r"]["desembolso"]) ?></div>
          </div>
          <div class="stat">
            <div class="stat__label">Balance</div>
            <div class="stat__value"><?= money(($sum[1]["net"] + $sum[2]["net"])) ?></div>
          </div>
        </div>

        <div class="note">
          Si la tabla <b>cash_movements</b> no existe o está vacía, este resumen se mantiene en 0 sin romper la página.
        </div>
      </div>
    </section>

    <section class="card card--wide">
      <div class="card__head">
        <div class="card__title">Acciones</div>
        <div class="muted">Módulo de Caja</div>
      </div>

      <div class="actions-grid">
        <a class="action-tile" href="../facturacion/index.php">
          <div class="action-tile__ico">🧾</div>
          <div class="action-tile__txt">
            <div class="action-tile__title">Registrar Factura</div>
            <div class="action-tile__sub">Ir al módulo de facturación</div>
          </div>
        </a>

        <a class="action-tile" href="../inventario/entrada.php">
          <div class="action-tile__ico">📥</div>
          <div class="action-tile__txt">
            <div class="action-tile__title">Entrada de Inventario</div>
            <div class="action-tile__sub">Registrar entrada</div>
          </div>
        </a>

        <a class="action-tile" href="../inventario/salida.php">
          <div class="action-tile__ico">📤</div>
          <div class="action-tile__txt">
            <div class="action-tile__title">Salida de Inventario</div>
            <div class="action-tile__sub">Registrar salida</div>
          </div>
        </a>

        <a class="action-tile" href="../patients/index.php">
          <div class="action-tile__ico">🧑‍🤝‍🧑</div>
          <div class="action-tile__txt">
            <div class="action-tile__title">Pacientes</div>
            <div class="action-tile__sub">Consultar / registrar</div>
          </div>
        </a>
      </div>
    </section>

  </main>
</div>

<footer class="footer">
  <div class="footer__inner">© <?= h($year) ?> CEVIMEP. Todos los derechos reservados.</div>
</footer>
</body>
</html>
