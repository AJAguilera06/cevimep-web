<?php
declare(strict_types=1);

/**
 * CEVIMEP | Caja (UI tipo dashboard.php)
 * - Sidebar + Topbar + contenido en cards
 * - Footer fijo
 * - Corrige error: incluye caja_lib.php (función caja_get_or_open_current_session)
 */

require_once __DIR__ . "/../_guard.php";
require_once __DIR__ . "/caja_lib.php"; // ✅ IMPORTANTE: aquí estaba el problema

if (!isset($_SESSION["user"])) { header("Location: ../../public/login.php"); exit; }

$user = $_SESSION["user"];
$year = date("Y");

$isAdmin  = (($user["role"] ?? "") === "admin");
$branchId = (int)($user["branch_id"] ?? 0);
$userId   = (int)($user["id"] ?? 0);

if (!$isAdmin && $branchId <= 0) { header("Location: ../../public/logout.php"); exit; }

date_default_timezone_set("America/Santo_Domingo");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtMoney($n){ return number_format((float)$n, 2, ".", ","); }

$today = date("Y-m-d");

// ✅ Auto cerrar vencidas y abrir sesión actual (sin botones)
$activeSessionId = caja_get_or_open_current_session($pdo, $branchId, $userId);

// Nombre sucursal (si existe branches)
$branchName = "Sucursal #".$branchId;
try {
  $stB = $pdo->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stB->execute([$branchId]);
  $bn = $stB->fetchColumn();
  if ($bn) $branchName = (string)$bn;
} catch (Throwable $e) {}

/**
 * Helpers internos para métricas/estado (NO rompen si faltan tablas)
 */
function caja_fetch_active_session(PDO $pdo, int $sessionId): ?array {
  try {
    $st = $pdo->prepare("SELECT * FROM cash_sessions WHERE id=? LIMIT 1");
    $st->execute([$sessionId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

function caja_fetch_today_totals(PDO $pdo, int $branchId, string $date): array {
  // Intento 1: tabla cash_movements (si existe)
  try {
    $st = $pdo->prepare("
      SELECT
        SUM(CASE WHEN type='in'  THEN amount ELSE 0 END) AS total_in,
        SUM(CASE WHEN type='out' THEN amount ELSE 0 END) AS total_out
      FROM cash_movements
      WHERE branch_id=? AND DATE(created_at)=?
    ");
    $st->execute([$branchId, $date]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
      "in"  => (float)($r["total_in"] ?? 0),
      "out" => (float)($r["total_out"] ?? 0),
    ];
  } catch (Throwable $e) {}

  // Fallback: sin tabla, no explota
  return ["in"=>0.0, "out"=>0.0];
}

$sessionRow = caja_fetch_active_session($pdo, (int)$activeSessionId);
$totals = caja_fetch_today_totals($pdo, $branchId, $today);

$sessionStatus = "Abierta";
$shiftLabel    = "Turno actual";
$openedAt      = "";
$cajaNum       = null;

if ($sessionRow) {
  $openedAt = (string)($sessionRow["opened_at"] ?? "");
  $cajaNum  = $sessionRow["caja_num"] ?? null;
  $shiftLabel = ($cajaNum ? ("Caja ".$cajaNum) : "Caja");
  if (!empty($sessionRow["closed_at"])) $sessionStatus = "Cerrada";
}

// Enlaces activos en menú
$current = "caja";
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>CEVIMEP | Caja</title>

  <!-- ✅ misma lógica del proyecto (assets sin /public) -->
  <link rel="stylesheet" href="/assets/css/caja.css?v=3">

</head>
<body>

<header class="topbar">
  <div class="topbar__inner">
    <div class="topbar__left">
      <div class="brand">
        <span class="brand__dot"></span>
        <span class="brand__text">CEVIMEP</span>
      </div>
    </div>

    <div class="topbar__center">
      <div class="topbar__title">
        Caja <span class="muted">|</span> <span class="muted"><?= h($branchName) ?></span>
      </div>
    </div>

    <div class="topbar__right">
      <a class="topbar__link" href="../../public/logout.php">Salir</a>
    </div>
  </div>
</header>

<div class="layout">
  <aside class="sidebar">
    <div class="sidebar__title">Menú</div>

    <nav class="menu">
      <a class="<?= ($current==='dashboard'?'active':'') ?>" href="../dashboard.php"><span class="ico">🏠</span> Panel</a>
      <a class="<?= ($current==='pacientes'?'active':'') ?>" href="../patients/index.php"><span class="ico">🧑‍🤝‍🧑</span> Pacientes</a>
      <a href="#" onclick="return false;" class="disabled"><span class="ico">📅</span> Citas</a>
      <a class="<?= ($current==='facturacion'?'active':'') ?>" href="../facturacion/index.php"><span class="ico">🧾</span> Facturación</a>
      <a class="<?= ($current==='inventario'?'active':'') ?>" href="../inventario/index.php"><span class="ico">📦</span> Inventario</a>
      <a class="<?= ($current==='caja'?'active':'') ?>" href="./index.php"><span class="ico">💵</span> Caja</a>
    </nav>

    <div class="sidebar__footer">
      <div class="chip">
        <div class="chip__label">Sucursal</div>
        <div class="chip__value"><?= h($branchName) ?></div>
      </div>
    </div>
  </aside>

  <main class="content">
    <section class="page-head">
      <h1 class="page-title">Caja</h1>
      <div class="page-subtitle">
        <?= h($branchName) ?> · <?= h($today) ?>
      </div>
    </section>

    <section class="grid">
      <div class="card">
        <div class="card__head">
          <div class="card__title">Estado de Caja</div>
          <div class="badge <?= ($sessionStatus==='Abierta'?'badge--ok':'badge--warn') ?>">
            <?= h($sessionStatus) ?>
          </div>
        </div>

        <div class="kv">
          <div class="kv__row">
            <div class="kv__k">Sesión activa</div>
            <div class="kv__v">#<?= h((string)$activeSessionId) ?></div>
          </div>
          <div class="kv__row">
            <div class="kv__k">Turno</div>
            <div class="kv__v"><?= h($shiftLabel) ?></div>
          </div>
          <div class="kv__row">
            <div class="kv__k">Apertura</div>
            <div class="kv__v"><?= $openedAt ? h($openedAt) : "<span class='muted'>N/D</span>" ?></div>
          </div>
        </div>

        <div class="card__actions">
          <a class="btn btn--primary" href="../facturacion/index.php">Ir a Facturación</a>
          <a class="btn btn--ghost" href="../dashboard.php">Volver al Panel</a>
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
            <div class="stat__value"><?= fmtMoney($totals["in"]) ?></div>
          </div>
          <div class="stat">
            <div class="stat__label">Salidas</div>
            <div class="stat__value"><?= fmtMoney($totals["out"]) ?></div>
          </div>
          <div class="stat">
            <div class="stat__label">Balance</div>
            <div class="stat__value"><?= fmtMoney(($totals["in"] - $totals["out"])) ?></div>
          </div>
        </div>

        <div class="note">
          Si no tienes aún la tabla de movimientos de caja, este resumen se mantiene en 0 sin romper la página.
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
  <div class="footer__inner">
    © <?= h($year) ?> CEVIMEP. Todos los derechos reservados.
  </div>
</footer>

</body>
</html>
