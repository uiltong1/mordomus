# Mordomus

Plataforma multitenant de gestão doméstica: cada residência e seus moradores
no mesmo aplicativo, com inventário de itens, agenda de manutenção, contas com
divisão de cota-parte e avisos por Web Push e e-mail.

O domínio é uma aplicação Laravel única em **monólito modular** (ADR-011): cinco
módulos com contrato próprio de dados, uma só migração, um só deploy. O
frontend é uma SPA React (Vite + React Query + PWA) que fala com a API pela
borda em nginx.

```
painel (React)  ──▶  gateway (nginx :8080)  ──▶  api (Laravel :8080 interno)
                            │                        ├── worker de fila
                            │                        └── scheduler
                            └── rate limit, X-Request-Id, descarte de X-Tenant-ID
```

---

## Subir o projeto

Pré-requisitos: **Docker** com `compose v2`, **make**, **openssl** e **Node 22**
(só para o Playwright, se for rodar as jornadas). Nada de PHP ou Postgres na
máquina — tudo roda em container.

```bash
make up        # segredos + imagem base + ambiente inteiro no ar
make health    # confere gateway, api, postgres e redis
```

`make up` chama `make secrets` (que gera o `.env` e as chaves) e `make base` (a
imagem PHP com nginx e extensões) antes de subir postgres, redis, mailpit, api,
worker, scheduler, gateway e painel. Do zero ao painel aberto o caminho é um
comando e o tempo é quase todo o build da imagem base e o `npm install` do
container do frontend — bem dentro de 30 min.

### O que fica em que porta

| Endereço | O que é |
|---|---|
| <http://localhost:5173> | painel (Vite, recarrega sozinho) |
| <http://localhost:8080/api/v1> | API, servida pelo gateway nginx |
| <http://localhost:8080/api/v1/docs> | documentação da API (Swagger UI sobre o OpenAPI gerado) |
| <http://localhost:8080/api/v1/openapi.yaml> | a especificação em YAML |
| <http://localhost:8080/health> | saúde do ambiente |
| <http://localhost:8080/.well-known/jwks.json> | chave pública do JWT |
| <http://localhost:8025> | Mailpit: os e-mails de notificação caem aqui |

O gateway **não** serve o painel: qualquer caminho que não seja `/api/`, `/health`
ou o JWKS devolve um 404 em JSON. O painel é servido pelo Vite em 5173 e fala
com a API em 8080 por CORS aberto.

### Cenário de demonstração

```bash
make seed-demo   # equivalente a: php artisan db:seed --demo
```

Monta duas casas com moradores, cômodo, inventário, regras de manutenção, contas
com cadência, divisão por peso e agenda já materializada:

| E-mail | Quem é |
|---|---|
| `ana@mordomus.test` | proprietária da Residência Vila Madalena |
| `bruno@mordomus.test` | morador da Residência Vila Madalena |
| `carla@mordomus.test` | moradora da Residência Vila Madalena |
| `diego@mordomus.test` | proprietário do Apartamento Centro |
| `elisa@mordomus.test` | moradora do Apartamento Centro |

Senha de todos: **`senha-demo-123`**. Entrar em 5173 com dois deles e usar o
seletor de residência no topo: a segunda casa existe para provar que trocar de
residência troca a agenda, e não renomeia a mesma.

O seed é idempotente — rodar de novo não duplica nada.

Só o RBAC (roles de sistema e catálogo de capabilities) é `make seed`, e ele roda
sozinho em `migrate --seed`.

---

## Comandos de trabalho

Tudo roda dentro do container, com o mesmo par de flags que o `Makefile` usa.

| Comando | O que faz |
|---|---|
| `make test` | suíte PHPUnit (429 testes, SQLite em memória, sem serviço externo) |
| `make lint` | Pint (estilo) + PHPStan nível 5 com larastan |
| `make contracts` | confere os JSON Schemas de `packages/contracts` contra os eventos publicados |
| `make openapi` | regenera `api/storage/openapi.yaml` a partir das anotações |
| `make smoke` | 18 verificações do módulo Identity contra o gateway de pé |
| `make e2e` | jornadas Playwright (exige o ambiente no ar) |
| `make migrate` / `make seed` / `make seed-demo` | banco |
| `make logs` / `make ps` / `make restart` / `make down` / `make clean` | operação |
| `make shell` | shell dentro do container da API |

Sem `make`, o mesmo por `docker compose run --rm --no-deps -e WAIT_FOR_DB=0 api <comando>`.

### Frontend

Os binários do Vite e do TypeScript são do Alpine, então `npm` na máquina falha —
use o container:

```bash
docker compose exec frontend npm run typecheck   # tsc -b (app, service worker, config e testes)
docker compose exec frontend npm run lint        # eslint
docker compose exec frontend npm run format      # prettier --write
docker compose exec frontend npm test            # vitest
docker compose exec frontend npm run build       # tsc -b && vite build
```

Ícones do PWA (roda na máquina, precisa de Pillow):

```bash
python3 bin/generate-icons.py
```

### Comandos de console da aplicação

| Comando | Quando roda sozinho |
|---|---|
| `scheduling:materialize` | diário às 02:00 — materializa a agenda da janela futura |
| `scheduling:publish-due` | a cada 15 min — publica `schedule.due` e marca atrasado |
| `financial:project` | a cada 15 min — projeta vencimentos a partir da agenda |
| `contracts:validate` | sob demanda — íntegro do contrato de eventos |
| `db:seed --demo` | sob demanda — cenário de demonstração |

As classes de comando por módulo entram à mão em `bootstrap/app.php`: a
descoberta por diretório do Laravel monta o FQCN a partir do prefixo único de
`app/`, e cada módulo tem o seu.

---

## A API

Base `http://localhost:8080/api/v1`. O formato completo está em
`/api/v1/docs` (Swagger UI) e em `/api/v1/openapi.yaml`, gerado a partir das
anotações `#[OA\...]` que ficam no método de cada controller — **quem documenta é
o código**, e o CI falha se a anotação e o arquivo divergirem (`make openapi`).

### Autenticação

1. `POST /identity/auth/register` ou `POST /identity/auth/login` devolvem
   `access_token` (RS256, 60 min), `refresh_token` (30 dias), `active_tenant` e
   a lista de casas do usuário com as capabilities de cada uma.
2. Toda requisição leva `Authorization: Bearer <access_token>`. A residência ativa
   vem do claim `tid` do token.
3. `POST /identity/auth/refresh` rotaciona o par; usar o refresh token já
   rotacionado derruba a sessão (reuso é sinal de roubo).
4. `POST /identity/auth/switch-tenant` devolve um token novo com o `tid` trocado —
   é o que o seletor de residência do painel usa.

O header `X-Tenant-ID` é **descartado pelo gateway**: escopo de residência nunca
vem do cliente, e sim do token validado.

### Formato das respostas

Lista e detalhe saem em `{"data": ...}`; listas paginadas acrescentam
`{"meta": {"page", "per_page", "total", "last_page"}}` (`per_page` máximo 100).

Erro é sempre o mesmo envelope, venha de onde vier:

```json
{
  "error": {
    "code": "tenant_mismatch",
    "message": "Sem acesso à residência informada.",
    "details": [],
    "request_id": "0f0c1a2b-..."
  }
}
```

`request_id` é o `X-Request-Id` que o cliente mandou (o gateway o gera quando o
cliente não manda) e é o mesmo identificador que aparece no log estruturado.

### Capabilities

Autorização é por capability, não por papel: `owner` nasce com todas, `member`
nasce com `occurrences.complete`, `occurrences.skip`, `bills.pay`,
`splits.view_own` e `notifications.manage`, e cada residência pode conceder ou
recusar uma capability na membership (`membership_grants`). A capability chega no
`GET /identity/me` e o painel esconde o que o morador não pode fazer.

### Mapa das rotas

| Prefixo | O que mora lá |
|---|---|
| `/identity` | contas, sessões, residências, convites, moradores, RBAC |
| `/maintenance` | cômodos, inventário de itens, atalho de regra por item |
| `/scheduling` | regras de recorrência, preview de data, agenda, concluir/pular |
| `/financial` | contas, vencimentos, baixa de pagamento, regras e leitura da divisão |
| `/notification` | preferências, assinaturas de push, histórico de avisos |
| `/health`, `/openapi.yaml`, `/docs` | operação e documentação |

---

## Como o código está organizado

```
api/                     monólito modular (Laravel)
  app/Modules/<Módulo>/  Contracts · Repositories · Services · Exceptions · Http · Models · Console
  app/Console/Commands/  comandos transversais (db:seed --demo, contracts:validate)
  app/Support/           utilitários fora de qualquer módulo
  routes/modules/        um arquivo de rotas por módulo
  database/seeders/      RBAC e cenário de demonstração
frontend/                SPA React (PWA)
e2e/                     jornadas Playwright
packages/php-common/     TenantContext, escopo global fail-closed, paginação
packages/contracts/      JSON Schema dos eventos publicados entre módulos
gateway/                 nginx de borda (proxy, rate limit, JWKS)
```

### Regras que o código sustenta

- **Escopo de residência é fail-closed.** `TenantGlobalScope` devolve zero linhas
  sem `TenantContext`, e toda escrita em tabela de residência precisa do contexto
  ou do `tenant_id` explícito. Nenhuma consulta "sem filtro" escapa disso.
- **Cada módulo é dono das suas tabelas** e só elas. A regra de posse de tabela do
  PHPStan (`api/phpstan/Rules/CrossModuleTableRule.php`) reprova `DB::table()`
  de tabela que é de outro módulo: leitura de longe é permitida, escrita de longe
  não tem dono.
- **Data é do Scheduling.** Nenhum módulo calcula a próxima data de manutenção
  por conta própria: o Financial persiste o `schedule_id` que o Scheduling
  materializou, e o painel sempre pergunta a data ao backend (inclusive no
  preview, antes de salvar).
- **Evento é contrato.** O nome do evento é uma constante `EventName` no módulo
  que publica e um arquivo em `packages/contracts`. `make contracts` reprova
  evento sem schema e schema sem evento; a suíte valida o payload real contra o
  schema, então envelope quebrado não passa.
- **Filas nomeadas por módulo**, com o dono do consumo explícito:
  `mordomus:scheduling:occurrences`, `mordomus:notification:events`,
  `mordomus:notification:push`, `mordomus:notification:emails`.

### CI

`.github/workflows/ci.yml` roda três jobs em todo push e pull request:

| Job | Portões |
|---|---|
| `backend` | Pint · PHPStan (nível 5 + regra de posse) · contratos de evento · OpenAPI sem divergência · PHPUnit |
| `frontend` | `tsc -b` · ESLint · Prettier · Vitest |
| `e2e` | ambiente completo no compose + jornadas Playwright via gateway |

PR vermelho bloqueia merge: qualquer portão em falha derruba o job.

O `phpstan-baseline.neon` registra o que o PHPStan já encontrou no código
existente; entrada que deixa de bater é erro de build (o arquivo não vira dívida
anônima), e a regra de posse de tabela nunca entra nele — vale a partir de agora.

### E2E

Duas jornadas atravessam o gateway de verdade (sem mock):

| Jornada | O que prova |
|---|---|
| `convite-agenda-conclusao-notificacao` | convite → o morador entra na casa pelo link → a dona agenda a manutenção pela tela do cômodo → o morador conclui pela agenda → o aviso dele chega |
| `conta-split` | conta com vencimento mensal → divisão por peso entre três moradores → cota que fecha em centavos → baixa do vencimento |

Onde não existe tela, a jornada usa a API pelo mesmo gateway — o convite é ato do
dono e hoje mora só no backend, e o sino in-app só enche com o service worker
ligado, que o ambiente de desenvolvimento não liga.

```bash
make up && make e2e                 # contra o ambiente local
cd e2e && npm run browsers && npm test -- --ui   # com interface
```

---

## Decisões que valem saber

| # | Decisão |
|---|---|
| ADR-001 | PWA + Web Push como canal, com adapter para FCM depois — o escopo de UI é web |
| ADR-003 | O módulo Scheduling é o dono de `TriggerConfig` e do cálculo de data |
| ADR-006 | ULID `char(26)` como chave, paginação offset, soft delete por `archived_at`, API em `/api/v1` |
| ADR-007 | RBAC por capability (roles → capabilities + override por membership), não por papel |
| ADR-009 | Contrato de evento em JSON Schema (`packages/contracts`), validado pelo consumidor e por E2E via gateway |
| ADR-010 | E-mail em Mailpit/SMTP local; domínio e provedor de produção são placeholder até o deploy |
| ADR-011 | Monólito modular: cinco módulos, um banco, sem fronteira de rede entre eles |

O detalhamento completo (invariantes R1–R7, DDL, algoritmos e a quebra em
tarefas) está em `docs/SDD.md`, `docs/TECHSPEC.md` e `docs/TAREFAS.md` — material
de trabalho interno, fora do versionamento.
