# Mordomus — Plataforma Multitenant de Gestão Doméstica

## Visão Geral
Mordomus é uma plataforma de gestão doméstica multitenant, baseada em arquitetura de monólito modular com Laravel.

## Arquitetura
- **Monólito Modular:** `api/` contendo 5 módulos (`identity`, `maintenance`, `scheduling`, `financial`, `notification`).
- **Banco de Dados:** PostgreSQL (banco único `mordomus`).
- **Front-end:** PWA em React + TypeScript + Vite.
- **Infraestrutura:** Docker Compose (8 containers).

## Módulos
1. **Identity:** Gestão de usuários, tenants (residências), convites e RBAC.
2. **Maintenance:** Gestão de cômodos e inventário de ativos.
3. **Scheduling:** Motor de triggers e agendamento recorrente.
4. **Financial:** Contas (fixas/variáveis) e motor de split de despesas.
5. **Notification:** Worker de eventos (Web Push e e-mail).

## Padrões de Código
- **Commits:** Conventional Commits (imperativo, minúsculo, sem ponto final).
- **Inversão de Dependência:** Obrigatória via interfaces (`Interface` sufixo) injetadas no construtor.
- **Validação:** Exclusiva em FormRequests.
- **Regras de Negócio:** Exclusiva em Services.
- **Logging:** Estruturado (JSON) via `Log::<nível>`.
- **Documentação:** OpenAPI (`#[OA\...]`) no controller.

## Execução
- **Ambiente:** `make up` (sobe 8 containers).
- **Testes:** `make test` (backend) · `npm test` (frontend).
- **Saúde:** `make health`.
- **OpenAPI:** `make openapi`.

## Referências
Documentação técnica detalhada encontra-se em `docs/SDD.md` e `docs/TECHSPEC.md`. As tarefas executadas estão em `docs/TAREFAS.md`.
