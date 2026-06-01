<?php
// private/caja/caja_lib.php
declare(strict_types=1);

date_default_timezone_set("America/Santo_Domingo");

require_once __DIR__ . "/caja_helpers.php";

if (!function_exists("caja_get_current_caja_num")) {
  function caja_get_current_caja_num(): int {
    [$cajaNum] = caja_get_turno();
    return (int)$cajaNum;
  }
}

/**
 * Registra un ingreso de factura en cash_movements.
 * IMPORTANTE: La caja del turno debe estar abierta manualmente desde /private/caja/index.php.
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

    if ($branch_id <= 0) {
      throw new RuntimeException("Sucursal inválida para registrar ingreso de caja.");
    }

    if ($amount <= 0) return;

    $metodo_pago = strtolower(trim($metodo_pago));
    $allowed = ["efectivo", "tarjeta", "transferencia", "cobertura"];
    if (!in_array($metodo_pago, $allowed, true)) {
      $metodo_pago = "efectivo";
    }

    $caja_sesion_id = (int)caja_get_open_session_id($pdo, $branch_id);
    if ($caja_sesion_id <= 0) {
      throw new RuntimeException("La caja del turno actual está cerrada. Debes abrir caja antes de facturar.");
    }

    $motivo = "Ingreso por factura #{$invoice_id}";

    $sql = "INSERT INTO cash_movements
            (branch_id, caja_sesion_id, type, motivo, metodo_pago, amount, created_by)
            VALUES
            (:branch_id, :caja_sesion_id, 'ingreso', :motivo, :metodo_pago, :amount, :created_by)";

    $st = $pdo->prepare($sql);
    $st->execute([
      ":branch_id" => $branch_id,
      ":caja_sesion_id" => $caja_sesion_id,
      ":motivo" => $motivo,
      ":metodo_pago" => $metodo_pago,
      ":amount" => $amount,
      ":created_by" => $user_id > 0 ? $user_id : null,
    ]);
  }
}
