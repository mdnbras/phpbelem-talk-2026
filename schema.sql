CREATE TABLE idempotency_requests (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id text NOT NULL,
    operation text NOT NULL,
    idempotency_key text NOT NULL,
    request_hash text NOT NULL,
    response_status integer,
    response_body text,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tenant_id, operation, idempotency_key)
);
CREATE TABLE payments (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id text NOT NULL,
    order_id text NOT NULL,
    amount_in_cents bigint NOT NULL CHECK (amount_in_cents > 0),
    currency text NOT NULL CHECK (currency = 'BRL'),
    idempotency_request_id bigint NOT NULL UNIQUE REFERENCES idempotency_requests(id)
);
-- Intencionalmente sem unicidade por pedido no exemplo vulnerável.
CREATE TABLE unsafe_payments (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    tenant_id text NOT NULL,
    order_id text NOT NULL,
    amount_in_cents bigint NOT NULL,
    currency text NOT NULL
);
-- Cada worker registra sua chegada APÓS o SELECT do fluxo vulnerável.
CREATE TABLE demo_barrier (
    tenant_id text NOT NULL,
    barrier_id text NOT NULL,
    participant text NOT NULL,
    PRIMARY KEY (tenant_id, barrier_id, participant)
);
