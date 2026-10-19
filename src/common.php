<?php

declare(strict_types=1);

final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $code)
    {
        parent::__construct($code);
    }
}

function database(): PDO
{
    return new PDO(getenv('DB_DSN'), getenv('DB_USER'), getenv('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function query(PDO $db, string $sql, array $params = []): PDOStatement
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function demoMode(): bool
{
    return getenv('DEMO_MODE') === '1';
}

function tenant(): string
{
    // Identidades fixas somente para laboratório. Nunca aceitar tenant do body.
    if (!demoMode()) {
        throw new HttpError(503, 'demo_disabled');
    }
    return match ($_SERVER['HTTP_AUTHORIZATION'] ?? '') {
        'Bearer demo-a' => 'tenant-a',
        'Bearer demo-b' => 'tenant-b',
        default => throw new HttpError(401, 'unauthorized'),
    };
}

function paymentInput(): array
{
    try {
        $input = json_decode(file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new HttpError(400, 'invalid_json');
    }
    if (!is_array($input) || count($input) !== 3
        || !is_string($input['order_id'] ?? null)
        || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $input['order_id'])
        || !is_int($input['amount_in_cents'] ?? null)
        || $input['amount_in_cents'] < 1 || $input['amount_in_cents'] > 1000000000
        || ($input['currency'] ?? null) !== 'BRL') {
        throw new HttpError(400, 'invalid_payment');
    }
    // Ordem fixa: o hash não depende da ordem dos campos no JSON recebido.
    return ['order_id' => $input['order_id'],
        'amount_in_cents' => $input['amount_in_cents'], 'currency' => $input['currency']];
}

function responseBody(array $payment): string
{
    return json_encode($payment, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function demoHook(): string
{
    $hook = $_SERVER['HTTP_X_DEMO_HOOK'] ?? '';
    if ($hook !== '' && (!demoMode() || !in_array($hook,
        ['before-commit', 'after-commit', 'hold-reservation'], true))) {
        throw new HttpError(400, 'invalid_demo_hook');
    }
    return $hook;
}

function logEvent(string $event, array $fields = []): void
{
    error_log(json_encode(['event' => $event, 'request_id' => $GLOBALS['requestId']] + $fields));
}
