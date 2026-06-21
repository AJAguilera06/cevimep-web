<?php
declare(strict_types=1);

/**
 * CEVIMEP - Protección CSRF
 *
 * Uso:
 *   require_once __DIR__ . '/../csrf.php';
 *   csrf_validate_post(); // antes de procesar cualquier POST
 *   <?= csrf_field() ?> dentro de cada <form method="post">
 */

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' .
        htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') .
        '">';
}

function csrf_validate_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postedToken  = $_POST['csrf_token'] ?? '';

    if (!is_string($sessionToken) || !is_string($postedToken) ||
        $sessionToken === '' || $postedToken === '' ||
        !hash_equals($sessionToken, $postedToken)) {

        http_response_code(419);
        exit('Solicitud inválida o expirada. Vuelve atrás, recarga la página e inténtalo nuevamente.');
    }
}
