<?php
// private/caja/caja_lib.php
date_default_timezone_set("America/Santo_Domingo");

if (!function_exists("caja_get_current_caja_num")) {
    function caja_get_current_caja_num(): int {
        $now = date("H:i:s");
        if ($now >= "08:00:00" && $now < "13:00:00") return 1;
        if ($now >= "13:00:00" && $now < "18:00:00") return 2;
        return ($now >= "18:00:00") ? 2 : 1;
    }
}

if (!function_exists("caja_shift_times")) {
    function caja_shift_times(int $cajaNum): array {
    // Caja 1 normal
    if ($cajaNum === 1) return ["08:00:00","13:00:00"];
    // Caja 2 se queda abierta hasta 11:59:59 PM para facturar de noche sin romper
    return ["13:00:00","23:59:59"];
}

}

if (!function_exists("caja_auto_close_expired")) {
    function caja_auto_close_expired(PDO $pdo, int $branchId, int $userId): void {
        $today = date("Y-m-d");
        $now   = date("H:i:s");

        $st = $pdo->prepare("SELECT id, shift_end
                             FROM cash_sessions
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

if (!function_exists("caja_get_or_open_current_session")) {
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

        // Fuera de horario => no abrir nueva
        if (!($now >= $shiftStart && $now < $shiftEnd)) {
            $st2 = $pdo->prepare("SELECT id
                                  FROM cash_sessions
                                  WHERE branch_id=? AND date_open=? AND closed_at IS NULL
                                  ORDER BY id DESC LIMIT 1");
            $st2->execute([$branchId, $today]);
            return (int)($st2->fetchColumn() ?: 0);
        }

        // Buscar sesión abierta del turno
        $st = $pdo->prepare("SELECT id
                             FROM cash_sessions
                             WHERE branch_id=? AND date_open=? AND caja_num=?
                               AND shift_start=? AND shift_end=? AND closed_at IS NULL
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

// =======================================================
// Facturación -> Caja: registrar ingreso ligado a factura
// =======================================================
if (!function_exists('caja_registrar_ingreso_factura')) {
  function caja_registrar_ingreso_factura(PDO $conn, int $branch_id, int $user_id, int $invoice_id, float $amount, string $method): bool
  {
    $amount = round((float)$amount, 2);
    if ($amount <= 0) return true;

    // ✅ Asegurar sesión abierta
    $session_id = 0;
    if (function_exists('caja_get_or_open_current_session')) {
      $session_id = (int)caja_get_or_open_current_session($conn, $branch_id, $user_id);
    }

    // ✅ Prioriza cash_movements (lo más probable en tu sistema)
    $candidates = ['cash_movements','caja_movimientos','caja_movements','caja_transacciones','caja_transactions'];

    // Buscar tabla existente (information_schema)
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
    if (!$table) return true; // no romper facturación

    // Columnas de la tabla
    $stC = $conn->prepare("
      SELECT column_name
      FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ?
    ");
    $stC->execute([$table]);
    $cols = $stC->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $has = fn(string $c) => in_array($c, $cols, true);

    $now = date('Y-m-d H:i:s');
    $desc = "INGRESO POR FACTURA #{$invoice_id}";
    $tipo = 'INGRESO';

    $data = [];

    // session (si existe columna)
    foreach (['session_id','cash_session_id','caja_session_id'] as $c) {
      if ($has($c) && $session_id > 0) { $data[$c] = $session_id; break; }
    }

    // branch
    foreach (['branch_id','sucursal_id'] as $c) {
      if ($has($c)) { $data[$c] = $branch_id; break; }
    }

    // user
    foreach (['user_id','created_by','usuario_id'] as $c) {
      if ($has($c)) { $data[$c] = $user_id; break; }
    }

    // invoice
    foreach (['invoice_id','factura_id'] as $c) {
      if ($has($c)) { $data[$c] = $invoice_id; break; }
    }

    // type
    foreach (['type','tipo','movement_type'] as $c) {
      if ($has($c)) { $data[$c] = $tipo; break; }
    }

    // method
    foreach (['method','payment_method','metodo_pago','forma_pago'] as $c) {
      if ($has($c)) { $data[$c] = $method; break; }
    }

    // amount
    foreach (['amount','monto','total','importe','valor'] as $c) {
      if ($has($c)) { $data[$c] = $amount; break; }
    }

    // description
    foreach (['description','descripcion','note','nota','detalle','concepto'] as $c) {
      if ($has($c)) { $data[$c] = $desc; break; }
    }

    // created_at
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
