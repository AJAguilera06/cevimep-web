<?php
// private/caja/caja_lib.php
declare(strict_types=1);

date_default_timezone_set("America/Santo_Domingo");

require_once __DIR__ . "/caja_helpers.php";

/**
 * Devuelve el número de caja según horario RD:
 * Caja 1: 07:00 AM - 12:59 PM
 * Caja 2: 01:00 PM - 11:59 PM
 */
if (!function_exists("caja_get_current_caja_num")) {
  function caja_get_current_caja_num(): int {
    $now = date("H:i:s");
    if ($now >= "07:00:00" && $now <= "12:59:59") {
      return 1;
    }
    return 2;
  }
}

/**
 * Horarios por caja
 */
if (!function_exists("caja_shift_times")) {
  function caja_shift_times(int $cajaNum): array {
    if ($cajaNum === 1) return ["07:00:00", "12:59:59"];
    if ($cajaNum === 2) return ["13:00:00", "23:59:59"];
    return ["00:00:00", "00:00:00"];
  }
}

/**
 * Registrar ingreso por factura
 */
if (!function_exists("caja_registrar_ingreso_factura")) {
  function caja_registrar_ingreso_factura(
    PDO $pdo,
    int $branch_id,
    int $user_id,
    int $invoice_id,
    float $amount,
    string $metodo_pago
  ): void {

    if ($branch_id <= 0) return;
    if ($amount <= 0) return;

    $metodo_pago = strtolower(trim($metodo_pago));

    $allowed = [
      "efectivo",
      "tarjeta",
      "transferencia",
      "cobertura"
    ];

    if (!in_array($metodo_pago, $allowed, true)) {
      $metodo_pago = "efectivo";
    }

    // Buscar sesión de caja abierta
    $caja_sesion_id = (int)caja_get_or_create_session_id($pdo, $branch_id);

    if ($caja_sesion_id <= 0) {
      throw new RuntimeException(
        "Debe abrir una caja antes de registrar facturas o ingresos."
      );
    }

    // Obtener código real de factura
    $stInv = $pdo->prepare("
      SELECT invoice_code
      FROM invoices
      WHERE id = ?
      LIMIT 1
    ");

    $stInv->execute([$invoice_id]);

    $invoice_code = $stInv->fetchColumn();

    if (!$invoice_code) {
      $invoice_code = "#" . $invoice_id;
    }

    // Motivo con formato nuevo
    $motivo = "Ingreso por factura {$invoice_code}";

    $sql = "
      INSERT INTO cash_movements
      (
        branch_id,
        caja_sesion_id,
        type,
        motivo,
        metodo_pago,
        amount,
        created_by
      )
      VALUES
      (
        :branch_id,
        :caja_sesion_id,
        'ingreso',
        :motivo,
        :metodo_pago,
        :amount,
        :created_by
      )
    ";

    $st = $pdo->prepare($sql);

    $st->execute([
      ":branch_id"      => $branch_id,
      ":caja_sesion_id" => $caja_sesion_id,
      ":motivo"         => $motivo,
      ":metodo_pago"    => $metodo_pago,
      ":amount"         => $amount,
      ":created_by"     => $user_id > 0 ? $user_id : null,
    ]);
  }
}