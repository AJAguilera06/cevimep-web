<?php
// private/caja/caja_lib.php
// Caja automática: abre/cierra por horario (Caja 1: 08:00-13:00, Caja 2: 13:00-18:00)
date_default_timezone_set("America/Santo_Domingo");

function caja_get_current_caja_num(): int {
  $now = date("H:i:s");
  if ($now >= "08:00:00" && $now < "13:00:00") return 1;
  if ($now >= "13:00:00" && $now < "18:00:00") return 2;
  // Fuera de horario: deja la última (por defecto 2 si es tarde)
  return ($now >= "18:00:00") ? 2 : 1;
}

function caja_shift_times(int $cajaNum): array {
  return ($cajaNum === 1) ? ["08:00:00","13:00:00"] : ["13:00:00","18:00:00"];
}

/**
 * Cierra automáticamente las sesiones abiertas del día que ya pasaron su hora de cierre.
 */
function caja_auto_close_expired(PDO $pdo, int $branchId, int $userId): void {
  $today = date("Y-m-d");
  $now   = date("H:i:s");

  $st = $pdo->prepare("SELECT id, shift_end FROM cash_sessions
                       WHERE branch_id=? AND date_open=? AND closed_at IS NULL");
  $st->execute([$branchId, $today]);
  $open = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($open as $s) {
    $shiftEnd = (string)($s["shift_end"] ?? "");
    if ($shiftEnd !== "" && $now >= $shiftEnd) {
      $up = $pdo->prepare("UPDATE cash_sessions SET closed_at=NOW(), closed_by=? WHERE id=? AND closed_at IS NULL");
      $up->execute([$userId, (int)$s["id"]]);
    }
  }
}

/**
 * Obtiene (o crea) la sesión del turno actual automáticamente.
 * - Cierra sesiones vencidas.
 * - Si estamos dentro del horario del turno: crea/usa sesión abierta.
 * - Si estamos fuera del horario: usa la última sesión abierta del día si existe; si no, devuelve 0.
 */
function caja_get_or_open_current_session(PDO $pdo, int $branchId, int $userId): int {
  caja_auto_close_expired($pdo, $branchId, $userId);

  $today  = date("Y-m-d");
  $now    = date("H:i:s");
  $cajaNum = caja_get_current_caja_num();
  [$shiftStart, $shiftEnd] = caja_shift_times($cajaNum);

  // Si no estamos dentro del rango, no abrimos nueva; usamos la última abierta del día si existe
  if (!($now >= $shiftStart && $now < $shiftEnd)) {
    $st2 = $pdo->prepare("SELECT id FROM cash_sessions
                          WHERE branch_id=? AND date_open=? AND closed_at IS NULL
                          ORDER BY id DESC LIMIT 1");
    $st2->execute([$branchId, $today]);
    return (int)($st2->fetchColumn() ?? 0);
  }

  // Buscar sesión abierta del turno actual
  $st = $pdo->prepare("SELECT id FROM cash_sessions
                       WHERE branch_id=? AND date_open=? AND caja_num=? AND shift_start=? AND shift_end=? AND closed_at IS NULL
                       ORDER BY id DESC LIMIT 1");
  $st->execute([$branchId, $today, $cajaNum, $shiftStart, $shiftEnd]);
  $sid = (int)($st->fetchColumn() ?? 0);
  if ($sid > 0) return $sid;

  // Crear sesión automáticamente
  $ins = $pdo->prepare("INSERT INTO cash_sessions
                          (branch_id, caja_num, shift_start, shift_end, date_open, opened_at, opened_by)
                        VALUES
                          (?, ?, ?, ?, ?, NOW(), ?)");
  $ins->execute([$branchId, $cajaNum, $shiftStart, $shiftEnd, $today, $userId]);
  return (int)$pdo->lastInsertId();
}

function caja_normalize_metodo_pago(string $mp): string {
  $mp = strtolower(trim($mp));
  return in_array($mp, ["efectivo","tarjeta","transferencia"], true) ? $mp : "efectivo";
}

/**
 * Registra ingreso de Facturación en caja (NO aplica 5% aquí porque ya lo calculas en facturación).
 */
function caja_registrar_ingreso_factura(PDO $pdo, int $branchId, int $userId, int $invoiceId, float $totalFinal, string $metodoPago): void {
  $sessionId = caja_get_or_open_current_session($pdo, $branchId, $userId);

  // Si no hay sesión (caso raro fuera de horario y nada abierto), abrimos igual la del "turno" actual para no bloquear facturación
  if ($sessionId <= 0) {
    $today  = date("Y-m-d");
    $cajaNum = caja_get_current_caja_num();
    [$shiftStart, $shiftEnd] = caja_shift_times($cajaNum);

    $ins = $pdo->prepare("INSERT INTO cash_sessions
                            (branch_id, caja_num, shift_start, shift_end, date_open, opened_at, opened_by)
                          VALUES
                            (?, ?, ?, ?, ?, NOW(), ?)");
    $ins->execute([$branchId, $cajaNum, $shiftStart, $shiftEnd, $today, $userId]);
    $sessionId = (int)$pdo->lastInsertId();
  }

  $metodoPago = caja_normalize_metodo_pago($metodoPago);

  $st = $pdo->prepare("INSERT INTO cash_movements
                        (session_id, type, motivo, metodo_pago, amount, created_by)
                       VALUES
                        (?, 'ingreso', ?, ?, ?, ?)");
  $st->execute([
    $sessionId,
    "Factura #".$invoiceId,
    $metodoPago,
    round($totalFinal, 2),
    $userId
  ]);
}
