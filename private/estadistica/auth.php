<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION["user"])) {
  echo json_encode(["ok"=>false,"msg"=>"No autorizado"]);
  exit;
}

define("ESTADISTICA_PASSWORD", "Admin123");

$pass = trim($_POST["password"] ?? "");

if ($pass === "" || $pass !== ESTADISTICA_PASSWORD) {
  echo json_encode(["ok"=>false,"msg"=>"Contraseña incorrecta"]);
  exit;
}

$_SESSION["estadistica_ok"] = true;

echo json_encode(["ok"=>true]);