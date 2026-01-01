<?php
require_once __DIR__ . "/../config/db.php";

$email = "admin@cevimep.com";
$newPassword = "Cevimep@123";
$newHash = password_hash($newPassword, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("UPDATE users SET password_hash = :h WHERE email = :e");
$stmt->execute(["h" => $newHash, "e" => $email]);

echo "DB: " . ($pdo->query("SELECT DATABASE() AS db")->fetch()["db"] ?? "N/A") . "<br>";
echo "Filas afectadas: " . $stmt->rowCount() . "<br>";
echo "Listo. Password actualizado para $email a $newPassword";
