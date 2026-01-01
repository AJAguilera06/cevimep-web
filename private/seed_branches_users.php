<?php
require_once __DIR__ . '/../config/db.php';

$plainPassword = 'Cevimep#2025!';

$branches = [
  ['id' => 1, 'name' => 'Moca',         'email' => 'moca@cevimep.com'],
  ['id' => 2, 'name' => 'La Vega',      'email' => 'lavega@cevimep.com'],
  ['id' => 3, 'name' => 'Salcedo',      'email' => 'salcedo@cevimep.com'],
  ['id' => 4, 'name' => 'Santiago',     'email' => 'santiago@cevimep.com'],
  ['id' => 5, 'name' => 'Mao',          'email' => 'mao@cevimep.com'],
  ['id' => 6, 'name' => 'Puerto Plata', 'email' => 'puertoplata@cevimep.com'],
];

$pdo->beginTransaction();
try {
  $stmtBranch = $pdo->prepare("INSERT IGNORE INTO branches (id, name) VALUES (?, ?)");
  foreach ($branches as $b) {
    $stmtBranch->execute([$b['id'], $b['name']]);
  }

  $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
  $stmtIns   = $pdo->prepare("
    INSERT INTO users (full_name, email, password_hash, role, branch_id, is_active)
    VALUES (?, ?, ?, ?, ?, 1)
  ");

  foreach ($branches as $b) {
    $stmtCheck->execute([$b['email']]);
    $exists = $stmtCheck->fetchColumn();

    if ($exists) {
      echo "Ya existe: {$b['email']} (no se crea)\n";
      continue;
    }

    $hash = password_hash($plainPassword, PASSWORD_BCRYPT);
    $fullName = "CEVIMEP {$b['name']}";
    $role = "branch_admin";

    $stmtIns->execute([$fullName, $b['email'], $hash, $role, $b['id']]);
    echo "Creado: {$b['email']} con branch_id={$b['id']}\n";
  }

  $pdo->commit();
  echo "\nLISTO ✅\n";
  echo "Contraseña inicial para todas: {$plainPassword}\n";
} catch (Exception $e) {
  $pdo->rollBack();
  die("Error: " . $e->getMessage());
}
