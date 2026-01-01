<?php
require_once __DIR__ . "/../config/db.php";

echo "<h2>Diagnóstico CEVIMEP</h2>";

$dbNow = $pdo->query("SELECT DATABASE() AS db")->fetch();
echo "<p><b>Base conectada:</b> " . htmlspecialchars($dbNow["db"] ?? "N/A") . "</p>";

$count = $pdo->query("SELECT COUNT(*) AS c FROM users")->fetch();
echo "<p><b>Total usuarios en users:</b> " . (int)$count["c"] . "</p>";

$email = "admin@cevimep.com";
$stmt = $pdo->prepare("SELECT id, full_name, email, password_hash, role FROM users WHERE email = :email LIMIT 1");
$stmt->execute(["email" => $email]);
$user = $stmt->fetch();

if (!$user) {
  echo "<p style='color:red; font-weight:800;'>NO existe el usuario: $email</p>";
  echo "<p>Emails existentes (máx 10):</p>";
  $rows = $pdo->query("SELECT email FROM users LIMIT 10")->fetchAll();
  echo "<pre>" . htmlspecialchars(print_r($rows, true)) . "</pre>";
  exit;
}

echo "<p style='color:green; font-weight:800;'>SÍ existe el usuario: " . htmlspecialchars($user["email"]) . "</p>";
echo "<p><b>Hash guardado (inicio):</b> " . htmlspecialchars(substr($user["password_hash"], 0, 25)) . "...</p>";

$testPass = "Cevimep@123";
$ok = password_verify($testPass, $user["password_hash"]);
echo "<p><b>password_verify('Cevimep@123'):</b> " . ($ok ? "✅ TRUE" : "❌ FALSE") . "</p>";
