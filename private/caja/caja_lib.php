<?php
// private/caja/caja_lib.php
// Caja automática: abre/cierra por horario (Caja 1: 08:00-13:00, Caja 2: 13:00-18:00)

date_default_timezone_set("America/Santo_Domingo");

if (!function_exists('caja_get_current_caja_num')) {
  function caja_get_current_caja_num(): int {
    $now = date("H:i:s");
    if ($now >= "08:00:00" && $now < "13:00:00") return 1;
    if ($now >= "13:00:00" && $now < "18:00:00") return 2;
    return ($now >= "18:00:00") ? 2 : 1;
  }
}

if (!function_exists('caja_shift_times')) {
  function caja_shift_times(int $cajaNum): array {
    return ($cajaNum === 1) ? ["08:00:00","13:00:00"] : ["13:00:00","18:00:00"];
  }
}

if (!function_exists('caja_auto_close_expired')) {
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
        $up = $pdo->prepare("UPDATE cash_sessions
                             SET closed_at=NOW(), closed_by=?
                             WHERE id=? AND closed_at IS NULL");
        $up->execute([$userId, (int)$s["id"]]);
      }
    }
  }
}

if (!function_exists('caja_get_or_open_current_session')) {
  /**
   * Devuelve ID de sesión activa del turno; si no existe, la crea (si está en horario).
   * Si está fuera de horario: devuelve la última sesión abierta del día si existe; si no, 0.
   */
  function caja_get_or_open_current_session(PDO $pdo, int $branchId, int $userId): int {
    caja_auto_close_expired($pdo, $branchId, $userId);

    $today   = date("Y-m-d");
    $now     = date("H:i:s");
    $cajaNum = caja_get_current_caja_num();
    [$shiftStart, $shiftEnd] = caja_shift_times($cajaNum);

    // Fuera de horario: no abrir nueva
    if (!($now >= $shiftStart && $now < $shiftEnd)) {
      $st2 = $pdo->prepare("SELECT id FROM cash_sessions
                            WHERE branch_id=? AND date_open=? AND closed_at IS NULL
                            ORDER BY id DESC LIMIT 1");
      $st2->execute([$branchId, $today]);
      return (int)($st2->fetchColumn() ?: 0);
    }

    // Buscar sesión abierta del turno
    $st = $pdo->prepare("SELECT id FROM cash_sessions
                         WHERE branch_id=? AND date_open=? AND caja_num=? AND shift_start=? AND shift_end=? AND closed_at IS NULL
                         ORDER BY id DESC LIMIT 1");
    $st->execute([$branchId, $today, $cajaNum, $shiftStart, $shiftEnd]);
    $sid = (int)($st->fetchColumn() ?: 0);
    if ($sid > 0) return $sid;

    // Crear sesión
    $ins = $pdo->prepare("INSERT INTO cash_sessions
                          (branch_id, caja_num, shift_start, shift_end, date_open, opened_at, opened_by)
                          VALUES (?, ?, ?, ?, ?, NOW(), ?)");
    $ins->execute([$branchId, $cajaNum, $shiftStart, $shiftEnd, $today, $userId]);
    return (int)$pdo->lastInsertId();
  }
}
