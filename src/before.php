<?php

declare(strict_types=1);

function before(PDO $db, string $tenant, array $input): array
{
    // O SELECT e o INSERT são operações separadas, sem proteção contra a corrida.
    $existing = query($db,
        'SELECT id FROM unsafe_payments WHERE tenant_id = ? AND order_id = ? LIMIT 1',
        [$tenant, $input['order_id']])->fetchColumn();
    if ($existing !== false) {
        return [200, responseBody(['payment_id' => (int)$existing] + $input), true];
    }

    $barrier = $_SERVER['HTTP_X_DEMO_BARRIER'] ?? '';
    if ($barrier !== '') {
        if (!demoMode() || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $barrier)) {
            throw new HttpError(400, 'invalid_barrier');
        }
        // Autocommit: ambos os workers conseguem observar as chegadas.
        query($db, 'INSERT INTO demo_barrier VALUES (?, ?, ?)',
            [$tenant, $barrier, bin2hex(random_bytes(8))]);
        $deadline = microtime(true) + 5;
        do {
            $arrivals = (int)query($db,
                'SELECT count(*) FROM demo_barrier WHERE tenant_id = ? AND barrier_id = ?',
                [$tenant, $barrier])->fetchColumn();
            if ($arrivals >= 2) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        if ($arrivals < 2) {
            throw new HttpError(503, 'barrier_timeout');
        }
    }

    $id = query($db, 'INSERT INTO unsafe_payments
        (tenant_id, order_id, amount_in_cents, currency) VALUES (?, ?, ?, ?) RETURNING id',
        [$tenant, $input['order_id'], $input['amount_in_cents'], $input['currency']])->fetchColumn();
    logEvent('unsafe_payment_created', ['payment_id' => (int)$id]);
    return [201, responseBody(['payment_id' => (int)$id] + $input), false];
}
