<?php
require_once __DIR__ . "/../config/db.php";

/**
 * PON AQUÍ LA CONTRASEÑA QUE QUIERES PARA TODAS LAS SUCURSALES
 */
$newPassword = "Cevimep#2025!";

$emails = [
  "moca@cevimep.com",
  "lavega@cevimep.com",
  "salcedo@cevimep.com",
  "santiago@cevimep.com",
  "mao@cevimep.com",
  "puertoplata@cevimep.com",
];

$hash = password_hash($newPassword, PASSWORD_BCRYPT);

$stmt = $pdo->prepare("UPDATE users SET password_hash = :hash, is_active = 1 WHERE email = :email");

foreach ($emails as $email) {
  $stmt->execute(["hash" => $hash, "email" => $email]);
  echo "OK -> $email\n";
}

echo "\nLISTO ✅ Password nuevo para todas: $newPassword\n";
