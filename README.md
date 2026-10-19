# Demo antes e depois

PHP 8.4 + PDO, PostgreSQL 18, PHP-FPM com 24 workers e Nginx. Sem Composer. Pagamentos são registros locais, sem gateway ou dinheiro real.

## Rodar

Requisito: Docker com Compose v2 (Linux, macOS ou Docker Desktop no Windows).

Na pasta `demo`:

```sh
docker compose up -d --build
docker compose run --rm tests
```

API: http://localhost:8080. A porta fica vinculada somente ao localhost. O teste espera a API ficar pronta e falha com saída diferente de zero se alguma condição não for atendida.

## Arquivos para mostrar no palco

| Antes | Depois |
|---|---|
| [src/before.php](src/before.php) | [src/after.php](src/after.php) |
| Consulta por pedido, depois insere | Tenta reservar a chave com UNIQUE |
| Duas chamadas podem consultar antes de inserir | Uma transação confirma reserva, pagamento e resposta |
| O mesmo pedido pode ganhar dois registros | Retry com mesma chave/conteúdo devolve a resposta salva |

O DDL está em [schema.sql](schema.sql). O roteamento, validação e hooks ficam separados para deixar a comparação fácil de ler. As duas versões usam tabelas distintas no mesmo banco.

## Roteiro de terminal

```sh
docker compose run --rm tests python /tests/scenarios.py before
docker compose run --rm tests python /tests/scenarios.py after
docker compose run --rm tests python /tests/scenarios.py timeout
docker compose run --rm tests python /tests/scenarios.py conflict
```

- `before`: duas chamadas chegam à barreira depois de seus SELECTs. Ambas inserem. O teste exige dois IDs e dois registros.
- `after`: 20 chamadas concorrentes com a mesma chave. Exige um lançamento, uma reserva, um body idêntico e 19 replays.
- `timeout`: o servidor confirma e demora três segundos antes de responder. O cliente desiste em meio segundo. O teste observa o registro ANTES do retry e exige replay do ID existente.
- `conflict`: mesma chave com outro valor retorna 409, mantendo um lançamento.
- `rollback`: falha controlada antes do commit, seguida de retry bem-sucedido.
- `contract`: validação, ordem dos campos JSON, autorização, isolamento por tenant e comportamento de chaves diferentes para o mesmo pedido.

Todos usam IDs novos, então podem ser repetidos sem apagar dados. Os números mostrados são lidos das respostas e da inspeção do banco, não valores fixos da animação dos slides.

## Uma requisição manual

Exemplo para Bash. No PowerShell, use `curl.exe` com as regras de aspas do seu terminal, ou execute os cenários pelo Compose acima.

```sh
curl -i http://localhost:8080/payments \
  -H 'Authorization: Bearer demo-a' \
  -H 'Content-Type: application/json' \
  -H 'Idempotency-Key: compra-001' \
  -d '{"order_id":"pedido-001","amount_in_cents":15000,"currency":"BRL"}'
```

Repita: status 201 e body são iguais. O header `Idempotency-Replayed` muda para `true`. Troque somente o valor para 17000: 409.

Antes da implementação, envie o mesmo corpo para `/unsafe/payments`. A chave não protege esse endpoint. Para reproduzir a corrida com precisão, use o cenário `before`.

## Inspecionar

```sh
docker compose exec db psql -U talk -d talk -c 'TABLE unsafe_payments;'
docker compose exec db psql -U talk -d talk -c 'TABLE payments;'
docker compose exec db psql -U talk -d talk -c 'TABLE idempotency_requests;'
docker compose logs -f app
```

`GET /demo/state?order_id=...&key=...`, autenticado, retorna contagens e IDs do próprio tenant. É o caminho usado pelo teste para observar os registros persistidos. Não é uma API de produção.

## Hooks de laboratório

Todos dependem de `DEMO_MODE=1`. As credenciais fixas `demo-a` e `demo-b` mapeiam para tenants diferentes. O cliente não escolhe tenant pelo body. Desativar DEMO_MODE desativa as operações protegidas por essa autenticação de laboratório.

| Header | Uso |
|---|---|
| X-Demo-Barrier: identificador-novo | Apenas `/unsafe/payments`; espera duas chegadas após SELECT, com limite de 5s |
| X-Demo-Hook: before-commit | Apenas fluxo protegido; lança erro e faz rollback |
| X-Demo-Hook: after-commit | Atrasa emissão HTTP após commit; cliente pode atingir timeout |
| X-Demo-Hook: hold-reservation | Mantém reserva por 4s para experimentos manuais de lock timeout |

O hook de resposta atrasada simula ausência de resposta para o cliente; não encerra o processo nem simula queda durante o commit. A barreira precisa de identificador novo por bateria; o script gera isso automaticamente.

## Limites

Não há gateway, webhook, limpeza automática de chaves nem proteção de “um pagamento por pedido”. Duas chaves diferentes são operações independentes, como demonstra o teste de contrato. Para um produto real, definir essa invariante separadamente.

A implementação salva e devolve o body serializado, compara hash de campos normalizados e faz o SELECT após conflito em comando separado sob READ COMMITTED. Erros transitórios de lock/deadlock/serialização retornam 503 com Retry-After. Quem repete deve manter a chave. A reserva não é confirmada incompleta pelo fluxo implementado.

Hooks, credenciais e endpoint vulnerável são exclusivos deste laboratório. Não publicar este Compose como serviço de pagamentos.

## Encerrar

```sh
docker compose down
```

Para apagar **somente o banco descartável desta demo** e reaplicar o schema inicial:

```sh
docker compose down -v
docker compose up -d --build
```

## Verificação

A workflow [PHP payment demo](../.github/workflows/demo.yml) executa sintaxe PHP e a suíte HTTP com PostgreSQL real. Consulte o resultado da execução na aba Actions. A inclusão da workflow não significa que uma execução já passou.
