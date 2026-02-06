<?php
// private/caja/caja_lib.php
declare(strict_types=1);

date_default_timezone_set("America/Santo_Domingo");

/**
 * Horarios:
 * Caja 1: 07:00:00 -> 13:00:00
 * Caja 2: 13:00:00 -> 19:00:00
 */

if (!function_exists("caja_get_current_caja_num")) {
  function caja_get_current_caja_num(): int {
    $now = date("H:i:s");
    if ($now >= "07:00:00" && $now < "13:00:00") return 1;
    if ($now >= "13:00:00" && $now < "19:00:00") return 2;
    return 0; // fuera de horario
  }
}

if (!function_exists("caja_shift_times")) {
  function caja_shift_times(int $cajaNum): array {
    if ($cajaNum === 1) return ["07:00:00","13:00:00"];
    return ["13:00:00","19:00:00"];
  }
}

if (!function_exists("caja_dt")) {
  function caja_dt(string $dateYmd, string $timeHis): DateTime {
    return new DateTime($dateYmd . " " . $timeHis, new DateTimeZone("America/Santo_Domingo"));
  }
}

if (!function_exists("caja_end_dt")) {
  function caja_end_dt(string $dateYmd, string $shiftStart, string $shiftEnd): DateTime {
    // (robusto por si en futuro vuelves a cruzar medianoche)
    $start = caja_dt($dateYmd, $shiftStart);
    $end   = caja_dt($dateYmd, $shiftEnd);
    if ($end <= $start) $end->modify('+1 day');
    return $end;
  }
}

if (!function_exists("caja_current_shift_context")) {
  function caja_current_shift_context(): array {
    $now = date("H:i:s");
    $today = date("Y-m-d");

    if ($now >= "07:00:00" && $now < "13:00:00") {
      [$ss,$se] = caja_shift_times(1);
      return ["caja_num"=>1, "date_open"=>$today, "shift_start"=>$ss, "shift_end"=>$se, "in_shift"=>true];
    }

    if ($now >= "13:00:00" && $now < "19:00:00") {
      [$ss,$se] = caja_shift_times(2);
      return ["caja_num"=>2, "date_open"=>$today, "shift_start"=>$ss, "shift_end"=>$se, "in_shift"=>true];
    }

    return [
      "caja_num" => 0,
      "date_open" => $today,
      "shift_start" => "00:00:00",
      "shift_end" => "00:00:00",
      "in_shift" => false,
    ];
  }
}

if (!function_exists("caja_auto_close_expired")) {
  function caja_auto_close_expired(PDO $pdo, int $branchId, int $userId): void {
    $nowDT = new DateTime('now', new DateTimeZone('America/Santo_Domingo'));
    $minDate = date("Y-m-d", strtotime("-7 day"));

    $st = $pdo->prepare("SELECT id, date_open, shift_start, shift_end
                         FROM cash_sessions
                         WHERE branch_id=? AND closed_at IS NULL AND date_open >= ?");
    $st->execute([$branchId, $minDate]);
    $open = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($open as $s) {
      $dateOpen   = (string)($s["date_open"] ?? "");
      $shiftStart = (string)($s["shift_start"] ?? "");
      $shiftEnd   = (string)($s["shift_end"] ?? "");
      if ($dateOpen === "" || $shiftStart === "" || $shiftEnd === "") continue;

      $endDT = caja_end_dt($dateOpen, $shiftStart, $shiftEnd);
      if ($nowDT >= $endDT) {
        $up = $pdo->prepare("UPDATE cash_sessions
                             SET closed_at=NOW(), closed_by=?
                             WHERE id=? AND closed_at IS NULL");
        $up->execute([$userId, (int)$s["id"]]);
      }
    }
  }
}

if (!function_exists("caja_get_or_open_current_session")) {
  function caja_get_or_open_current_session(PDO $pdo, int $branchId, int $userId): int {
    caja_auto_close_expired($pdo, $branchId, $userId);

    $ctx = caja_current_shift_context();
    $cajaNum    = (int)$ctx["caja_num"];
    $dateOpen   = (string)$ctx["date_open"];
    $shiftStart = (string)$ctx["shift_start"];
    $shiftEnd   = (string)$ctx["shift_end"];
    $inShift    = (bool)$ctx["in_shift"];

    // Fuera de horario: no abrir nueva
    if (!$inShift || $cajaNum === 0) {
      $st2 = $pdo->prepare("SELECT id
                            FROM cash_sessions
                            WHERE branch_id=? AND closed_at IS NULL
                            ORDER BY id DESC LIMIT 1");
      $st2->execute([$branchId]);
      return (int)($st2->fetchColumn() ?: 0);
    }

    // Buscar sesión abierta exacta por turno
    $st = $pdo->prepare("SELECT id
                         FROM cash_sessions
                         WHERE branch_id=? AND date_open=? AND caja_num=?
                           AND shift_start=? AND shift_end=? AND closed_at IS NULL
                         ORDER BY id DESC LIMIT 1");
    $st->execute([$branchId, $dateOpen, $cajaNum, $shiftStart, $shiftEnd]);
    $sid = (int)($st->fetchColumn() ?: 0);
    if ($sid > 0) return $sid;

    // Crear sesión
    $ins = $pdo->prepare("INSERT INTO cash_sessions
                          (branch_id, caja_num, shift_start, shift_end, date_open, opened_at, opened_by)
                          VALUES (?, ?, ?, ?, ?, NOW(), ?)");
    $ins->execute([$branchId, $cajaNum, $shiftStart, $shiftEnd, $dateOpen, $userId]);

    return (int)$pdo->lastInsertId();
  }
}

/**
 * Registrar ingresos desde Facturación hacia Caja.
 * (Si ya lo usas, esto mantiene compatibilidad.)
 */
if (!function_exists('caja_registrar_ingreso_factura')) {
  function caja_registrar_ingreso_factura(PDO $conn, int $branch_id, int $user_id, int $invoice_id, float $amount, string $method): bool
  {
    $method = strtolower(trim($method));
    if ($method === 'efectivo' || $method === 'cash') $method = 'efectivo';
    if ($method === 'tarjeta' || $method === 'card') $method = 'tarjeta';
    if ($method === 'transferencia' || $method === 'transfer') $method = 'transferencia';
    if ($method === 'cobertura' || $method === 'seguro' || $method === 'ars' || $method === 'insurance') $method = 'cobertura';

    $amount = round((float)$amount, 2);
    if ($amount <= 0) return true;

    $session_id = (int)caja_get_or_open_current_session($conn, $branch_id, $user_id);
    if ($session_id <= 0) return true;

    // Tabla objetivo (intenta varias por compatibilidad)
    $candidates = ['cash_movements','caja_movimientos','caja_movements','caja_transacciones','caja_transactions'];

    $table = null;
    $stT = $conn->prepare("
      SELECT table_name
      FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = ?
      LIMIT 1
    ");
    foreach ($candidates as $t) {
      $stT->execute([$t]);
      if ($stT->fetchColumn()) { $table = $t; break; }
    }
    if (!$table) return true;

    $stC = $conn->prepare("
      SELECT column_name
      FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ?
    ");
    $stC->execute([$table]);
    $cols = $stC->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $has = fn(string $c) => in_array($c, $cols, true);

    $now = date('Y-m-d H:i:s');
    $desc = "Factura #{$invoice_id}";
    $tipo = 'ingreso';

    $data = [];

    foreach (['session_id','cash_session_id','caja_session_id'] as $c) {
      if ($has($c)) { $data[$c] = $session_id; break; }
    }
    foreach (['branch_id','sucursal_id'] as $c) {
      if ($has($c)) { $data[$c] = $branch_id; break; }
    }
    foreach (['user_id','created_by','usuario_id'] as $c) {
      if ($has($c)) { $data[$c] = $user_id; break; }
    }
    foreach (['invoice_id','factura_id'] as $c) {
      if ($has($c)) { $data[$c] = $invoice_id; break; }
    }
    foreach (['type','tipo','movement_type'] as $c) {
      if ($has($c)) { $data[$c] = $tipo; break; }
    }
    foreach (['method','payment_method','metodo_pago','forma_pago'] as $c) {
      if ($has($c)) { $data[$c] = $method; break; }
    }
    foreach (['amount','monto','total','importe','valor'] as $c) {
      if ($has($c)) { $data[$c] = $amount; break; }
    }
    foreach (['motivo','description','descripcion','note','nota','detalle','concepto'] as $c) {
      if ($has($c)) { $data[$c] = $desc; break; }
    }
    foreach (['created_at','fecha','date','created_on'] as $c) {
      if ($has($c)) { $data[$c] = $now; break; }
    }

    if (!$data) return true;

    $fields = array_keys($data);
    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $sql = "INSERT INTO `$table` (`" . implode('`,`', $fields) . "`) VALUES ($placeholders)";
    $st = $conn->prepare($sql);
    return $st->execute(array_values($data));
  }
}
