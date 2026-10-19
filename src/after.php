<?php

declare(strict_types=1);

function after(PDO $db, string $tenant, array $input, string $key, string $hook): array
{
    $operation = 'create-payment:v1';
    $hash = hash('sha256', responseBody(['version' => 1] + $input));
    $db->beginTransaction();
    try {
        $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $db->exec("SET LOCAL lock_timeout = '2s'");
        $reservation = query($db, 'INSERT INTO idempotency_requests
            (tenant_id, operation, idempotency_key, request_hash) VALUES (?, ?, ?, ?)
            ON CONFLICT (tenant_id, operation, idempotency_key) DO NOTHING RETURNING id',
            [$tenant, $operation, $key, $hash])->fetchColumn();

        if ($reservation === false) {
            // Outro comando = novo snapshot em READ COMMITTED.
            $saved = query($db, 'SELECT request_hash, response_status, response_body
                FROM idempotency_requests WHERE tenant_id = ? AND operation = ? AND idempotency_key = ?',
                [$tenant, $operation, $key])->fetch();
            if (!$saved || $saved['response_body'] === null) {
                throw new HttpError(503, 'result_unavailable');
            }
            if (!hash_equals($saved['request_hash'], $hash)) {
                throw new HttpError(409, 'idempotency_key_reused');
            }
            $db->commit();
            logEvent('payment_replayed');
            return [(int)$saved['response_status'], $saved['response_body'], true];
        }
        if ($hook === 'hold-reservation') {
            // Hook exclusivo de teste: o concorrente estoura lock_timeout de 2s.
            sleep(4);
        }
        $id = query($db, 'INSERT INTO payments
            (tenant_id, order_id, amount_in_cents, currency, idempotency_request_id)
            VALUES (?, ?, ?, ?, ?) RETURNING id',
            [$tenant, $input['order_id'], $input['amount_in_cents'], $input['currency'], $reservation])
            ->fetchColumn();
        $body = responseBody(['payment_id' => (int)$id] + $input);
        query($db, 'UPDATE idempotency_requests SET response_status = 201, response_body = ? WHERE id = ?',
            [$body, $reservation]);
        if ($hook === 'before-commit') {
            throw new HttpError(500, 'injected_before_commit');
        }
        $db->commit();
        logEvent('payment_committed', ['payment_id' => (int)$id]);
        return [201, $body, false];
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
