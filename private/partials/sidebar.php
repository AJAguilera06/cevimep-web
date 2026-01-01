<?php
// USO:
// En cada página define:
//   $active = "panel" | "pacientes" | "citas" | "facturacion" | "caja" | "inventario" | "estadistica";
//   $base   = "" (si estás en /private)  o  "../" (si estás en /private/patients o /private/inventario)
// y luego haces include del sidebar.

$active = $active ?? "";
$base   = $base ?? "";

function acls($key, $active) {
  return ($key === $active) ? "active" : "";
}
?>
<div class="title">Menú</div>

<nav class="menu">
  <!-- ✅ ORDEN FIJO (SIEMPRE) -->
  <a class="<?php echo acls('panel',$active); ?>" href="<?php echo $base; ?>dashboard.php">
    <span class="ico">🏠</span> Panel
  </a>

  <a class="<?php echo acls('pacientes',$active); ?>" href="<?php echo $base; ?>patients/index.php">
    <span class="ico">🧑‍🤝‍🧑</span> Pacientes
  </a>

  <a class="<?php echo acls('citas',$active); ?>" href="#" onclick="return false;" style="opacity:.55; cursor:not-allowed;">
    <span class="ico">📅</span> Citas
  </a>

  <a class="<?php echo acls('facturacion',$active); ?>" href="<?php echo $base; ?>facturacion/index.php">
    <span class="ico">🧾</span> Facturación
  </a>

  <!-- ✅ CAJA HABILITADA -->
  <a class="<?php echo acls('caja',$active); ?>" href="<?php echo $base; ?>caja/index.php">
    <span class="ico">💳</span> Caja
  </a>

  <a class="<?php echo acls('inventario',$active); ?>" href="<?php echo $base; ?>inventario/index.php">
    <span class="ico">📦</span> Inventario
  </a>

  <!-- ✅ ESTADÍSTICA (ANTES "COMING SOON") -->
  <a class="<?php echo acls('estadistica',$active); ?>" href="<?php echo $base; ?>estadistica/index.php">
    <span class="ico">📊</span> Estadística
  </a>
</nav>
