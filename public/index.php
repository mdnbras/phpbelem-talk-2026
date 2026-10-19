<?php

declare(strict_types=1);
require __DIR__ . '/../src/common.php';
require __DIR__ . '/../src/before.php';
require __DIR__ . '/../src/after.php';
$GLOBALS['requestId'] = bin2hex(random_bytes(8));
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Request-Id: ' . $GLOBALS['requestId']);
$db = null;
try {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'];
    $db = database();
    if ($path === '/health' && $method === 'GET') {
        query($db, 'SELECT 1');
        echo '{"status":"ok"}';
        exit;
    }
    $tenant = tenant();
    if ($path === '/demo/state' && $method === 'GET') {
        // Inspeção restrita ao tenant autenticado; somente no laboratório.
        $order = $_GET['order_id'] ?? '';
        $key = $_GET['key'] ?? '';
        if (!is_string($order) || !is_string($key)) {
            throw new HttpError(400, 'invalid_query');
        }
        $safe = query($db, 'SELECT id FROM payments WHERE tenant_id = ? AND order_id = ? ORDER BY id',
            [$tenant, $order])->fetchAll();
        $unsafe = query($db, 'SELECT id FROM unsafe_payments WHERE tenant_id = ? AND order_id = ? ORDER BY id',
            [$tenant, $order])->fetchAll();
        $reservations = (int)query($db, 'SELECT count(*) FROM idempotency_requests
            WHERE tenant_id = ? AND operation = ? AND idempotency_key = ?',
            [$tenant, 'create-payment:v1', $key])->fetchColumn();
        echo responseBody(['safe' => count($safe), 'unsafe' => count($unsafe),
            'payment_ids' => array_column($safe, 'id'), 'reservations' => $reservations]);
        exit;
    }
    if (!in_array($path, ['/unsafe/payments', '/payments'], true)) {
        throw new HttpError(404, 'not_found');
    }
    if ($method !== 'POST') {
        header('Allow: POST');
        throw new HttpError(405, 'method_not_allowed');
    }
    $input = paymentInput();
    $hook = demoHook();
    if ($path === '/payments') {
        $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $key)) {
            throw new HttpError(400, 'invalid_idempotency_key');
        }
        [$status, $body, $replayed] = after($db, $tenant, $input, $key, $hook);
    } else {
        [$status, $body, $replayed] = before($db, $tenant, $input);
    }
    if ($hook === 'after-commit' && !$replayed && $path === '/payments') {
        // O cliente de teste desiste em 0,5s. O commit já ocorreu; não enviamos body antes da pausa.
        ignore_user_abort(true);
        sleep(3);
    }
    http_response_code($status);
    header('Idempotency-Replayed: ' . ($replayed ? 'true' : 'false'));
    echo $body;
} catch (Throwable $error) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $status = $error instanceof HttpError ? $error->status : 500;
    $code = $error instanceof HttpError ? $error->getMessage() : 'internal_error';
    if ($error instanceof PDOException && in_array((string)$error->getCode(), ['55P03', '40001', '40P01'], true)) {
        $status = 503;
        $code = 'operation_busy';
    }
    if ($status === 503) {
        header('Retry-After: 1');
    }
    logEvent('request_failed', ['status' => $status, 'code' => $code]);
    http_response_code($status);
    echo responseBody(['error' => $code]);
}
