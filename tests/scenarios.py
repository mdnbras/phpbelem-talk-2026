"""HTTP integration tests. Verify both responses and persisted PostgreSQL state."""
import concurrent.futures as futures
import json
import os
import socket
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

BASE = os.getenv('BASE_URL', 'http://localhost:8080').rstrip('/')


def request(path, body=None, key=None, headers=None, tenant='demo-a', timeout=10):
    h = {'Authorization': 'Bearer ' + tenant, 'Content-Type': 'application/json'}
    if key:
        h['Idempotency-Key'] = key
    h.update(headers or {})
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(BASE + path, data=data, headers=h)
    try:
        response = urllib.request.urlopen(req, timeout=timeout)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        raw = response.read().decode()
        return response.status, json.loads(raw), raw, response.headers


def case():
    uid = uuid.uuid4().hex
    return {'order_id': 'order-' + uid, 'amount_in_cents': 15000, 'currency': 'BRL'}, uid


def state(body, key, tenant='demo-a'):
    status, result, _, _ = request('/demo/state?' + urllib.parse.urlencode(
        {'order_id': body['order_id'], 'key': key}), tenant=tenant)
    assert status == 200, (status, result)
    return result


def before():
    body, key = case()
    with futures.ThreadPoolExecutor(max_workers=2) as pool:
        jobs = [pool.submit(request, '/unsafe/payments', body, key,
                            {'X-Demo-Barrier': key}) for _ in range(2)]
        results = [job.result() for job in jobs]
    assert [r[0] for r in results] == [201, 201], results
    assert len({r[1]['payment_id'] for r in results}) == 2
    saved = state(body, key)
    assert saved['unsafe'] == 2, saved
    print('ANTES: duas chamadas, dois IDs e dois registros no banco.', saved, flush=True)


def after():
    body, key = case()
    with futures.ThreadPoolExecutor(max_workers=20) as pool:
        results = list(pool.map(lambda _: request('/payments', body, key), range(20)))
    assert all(r[0] == 201 for r in results), results
    assert len({r[2] for r in results}) == 1, 'Replay deve preservar o body serializado'
    assert sum(r[3]['Idempotency-Replayed'] == 'false' for r in results) == 1
    saved = state(body, key)
    assert saved['safe'] == 1 and saved['reservations'] == 1, saved
    print('DEPOIS: 20 chamadas, mesmo body/ID, um registro e uma reserva.', saved, flush=True)


def timeout_case():
    body, key = case()
    try:
        request('/payments', body, key, {'X-Demo-Hook': 'after-commit'}, timeout=0.5)
        raise AssertionError('Cliente deveria atingir timeout')
    except (TimeoutError, socket.timeout):
        pass
    except urllib.error.URLError as error:
        if not isinstance(error.reason, (TimeoutError, socket.timeout)):
            raise
    # Provar que o lançamento existe ANTES de enviar o retry.
    deadline = time.monotonic() + 5
    while True:
        saved = state(body, key)
        if saved['safe'] == 1:
            break
        if time.monotonic() > deadline:
            raise AssertionError(('Commit não observado', saved))
        time.sleep(0.05)
    retry = request('/payments', body, key)
    assert retry[0] == 201 and retry[3]['Idempotency-Replayed'] == 'true', retry
    assert retry[1]['payment_id'] == int(saved['payment_ids'][0])
    assert state(body, key)['safe'] == 1
    print('TIMEOUT: cliente sem resposta, commit observado e retry com o mesmo ID.', flush=True)


def conflict():
    body, key = case()
    assert request('/payments', body, key)[0] == 201
    changed = dict(body, amount_in_cents=17000)
    result = request('/payments', changed, key)
    assert result[0] == 409 and result[1]['error'] == 'idempotency_key_reused', result
    assert state(body, key)['safe'] == 1
    print('CONFLITO: mesma chave e outro valor retorna 409, sem novo registro.', flush=True)


def rollback():
    body, key = case()
    result = request('/payments', body, key, {'X-Demo-Hook': 'before-commit'})
    assert result[0] == 500, result
    saved = state(body, key)
    assert saved['safe'] == 0 and saved['reservations'] == 0, saved
    retry = request('/payments', body, key)
    assert retry[0] == 201 and retry[3]['Idempotency-Replayed'] == 'false'
    assert state(body, key)['safe'] == 1
    print('ROLLBACK: falha antes do commit não deixa pagamento nem reserva.', flush=True)


def contract():
    body, key = case()
    assert request('/payments', body)[0] == 400
    assert request('/payments', dict(body, amount_in_cents=0), key)[0] == 400
    assert state(body, key)['reservations'] == 0
    first = request('/payments', body, key)
    reordered = dict(reversed(list(body.items())))
    replay = request('/payments', reordered, key)
    assert replay[2] == first[2] and replay[3]['Idempotency-Replayed'] == 'true'
    assert request('/payments', body, key, tenant='invalid')[0] == 401
    other = request('/payments', body, key, tenant='demo-b')
    assert other[0] == 201 and other[1]['payment_id'] != first[1]['payment_id']
    assert state(body, key, 'demo-b')['safe'] == 1
    # Documentar o limite: outra chave é outra identidade; não há UNIQUE por pedido.
    assert request('/payments', body, key + '-other')[0] == 201
    assert state(body, key)['safe'] == 2
    print('CONTRATO: validação, JSON reordenado, autorização, tenant isolado e limite por pedido.', flush=True)


def wait_ready():
    for _ in range(60):
        try:
            if request('/health')[0] == 200:
                return
        except (OSError, ValueError):
            pass
        time.sleep(1)
    raise RuntimeError('API não ficou pronta em 60 segundos')


SCENARIOS = {'before': before, 'after': after, 'timeout': timeout_case,
             'conflict': conflict, 'rollback': rollback, 'contract': contract}
if __name__ == '__main__':
    selected = sys.argv[1] if len(sys.argv) > 1 else 'all'
    if selected != 'all' and selected not in SCENARIOS:
        raise SystemExit('Use all ou: ' + ', '.join(SCENARIOS))
    wait_ready()
    for name, run in SCENARIOS.items():
        if selected in ('all', name):
            run()
    print('Cenários selecionados passaram.', flush=True)
