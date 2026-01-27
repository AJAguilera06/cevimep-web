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
        return ($cajaNum === 1) ? ["08:00:00","13:00:00"] : ["13:00:00","18:00:00"];
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
<?php
// ... (tu código existente arriba)

// =======================================================
// Facturación -> Caja: registrar ingreso ligado a factura
// =======================================================
if (!function_exists('caja_registrar_ingreso_factura')) {
  /**
   * Registra un ingreso de caja relacionado a una factura.
   * Es "tolerante": si no encuentra la tabla, no rompe la app.
   */
  function caja_registrar_ingreso_factura(PDO $conn, int $branch_id, int $user_id, int $invoice_id, float $amount, string $method): bool
  {
    $amount = round((float)$amount, 2);
    if ($amount <= 0) return true;

    // 1) Detectar tabla de caja (por si tu proyecto la nombró distinto)
    $candidates = [
      'caja_movimientos',
      'caja_movements',
      'cash_movements',
      'caja_transacciones',
      'caja_transactions',
    ];

    $table = null;
    foreach ($candidates as $t) {
      $st = $conn->prepare("SHOW TABLES LIKE ?");
      $st->execute([$t]);
      if ($st->fetchColumn()) { $table = $t; break; }
    }

    // Si no existe ninguna tabla conocida, no rompemos (evita fatal error)
    if (!$table) return true;

    // 2) Obtener columnas reales de la tabla encontrada
    $cols = [];
    $stCols = $conn->query("SHOW COLUMNS FROM `$table`");
    foreach ($stCols->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $cols[] = $row['Field'];
    }
    $has = fn(string $c) => in_array($c, $cols, true);

    // 3) Preparar data compatible con la mayoría de esquemas
    $now = date('Y-m-d H:i:s');
    $desc = "INGRESO POR FACTURA #{$invoice_id}";
    $tipoIngreso = 'INGRESO';

    $data = [];

    // branch
    foreach (['branch_id','sucursal_id','branch','sucursal'] as $c) {
      if ($has($c)) { $data[$c] = $branch_id; break; }
    }

    // user / created_by
    foreach (['created_by','user_id','usuario_id','created_user','hecho_por'] as $c) {
      if ($has($c)) { $data[$c] = $user_id; break; }
    }

    // invoice reference
    foreach (['invoice_id','factura_id','ref_invoice_id','reference_id','ref_id'] as $c) {
      if ($has($c)) { $data[$c] = $invoice_id; break; }
    }

    // amount
    foreach (['amount','monto','total','importe','valor'] as $c) {
      if ($has($c)) { $data[$c] = $amount; break; }
    }

    // type / movement_type
    foreach (['type','movement_type','tipo','movimiento'] as $c) {
      if ($has($c)) { $data[$c] = $tipoIngreso; break; }
    }

    // method
    foreach (['method','payment_method','metodo_pago','forma_pago'] as $c) {
      if ($has($c)) { $data[$c] = $method; break; }
    }

    // description / note
    foreach (['description','descripcion','note','nota','detalle','concepto'] as $c) {
      if ($has($c)) { $data[$c] = $desc; break; }
    }

    // created_at / fecha
    foreach (['created_at','fecha','created_on','date'] as $c) {
      if ($has($c)) { $data[$c] = $now; break; }
    }

    // 4) Insert dinámico (solo con columnas existentes)
    if (!$data) return true;

    $fields = array_keys($data);
    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $sql = "INSERT INTO `$table` (`" . implode('`,`', $fields) . "`) VALUES ($placeholders)";
    $stIns = $conn->prepare($sql);
    return $stIns->execute(array_values($data));
  }
}
