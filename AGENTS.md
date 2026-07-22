# AGENTS.md

## Objetivo

Fake Link: plataforma de encurtamento de URLs com links personalizados, redirect público e analytics privados.

## Stack obrigatória

- Backend: PHP Laravel API Only · Frontend: Next.js · Testes backend: PestPHP
- PostgreSQL · Redis · OpenAPI 3.1 + Swagger UI · Docker Compose

## Idioma

- Código e artefatos técnicos em inglês.
- Documentação arquitetural em português do Brasil.
- UI do MVP em português do Brasil, sem i18n inicial.

## Módulos iniciais

Backend: `Auth`, `Links`, `Redirects`, `Analytics`, `Operations`, `Shared`

Frontend: `auth`, `links`, `analytics`, `shared`

## Descoberta progressiva

Não carregue toda a documentação de uma vez. Leia somente os arquivos indicados na tabela abaixo conforme a necessidade da tarefa. Para regras de produto, arquitetura, segurança ou testes, prefira a documentação temática em vez de inferir a partir deste arquivo.

## General Rules

- SEMPRE pergunte antes de instalar ou sugerir nova dependência, biblioteca, pacote ou credenciais.
- Sempre crie migrations com `php artisan make:migration` dentro do container backend.
- Migrations ficam em `backend/database/migrations/`.
- NUNCA rode migrations em produção.
- Ao concluir um plano de implementação, rode lint e testes do escopo afetado via Docker (`make test-backend`, `make test-frontend`, `make lint` quando disponível).
- Use o Context7 MCP para gerar código, configurar bibliotecas de terceiros e consultar documentação atualizada.
- Ao criar ou alterar Docker, observe a pasta `docker/` na raiz e os arquivos `docker-compose*.yml`.
- Execute comandos sempre dentro dos containers Docker, nunca no host local.
- IMPORTANTE: inclua testes E2E para caminhos importantes.
- Implementações que tocam rotas ou controllers exigem testes E2E.
- Cubra todas as regras de domínio com testes (detalhes em `docs/testing.md`).

## Git e commits

- NUNCA faça push direto na `main` do projeto.
- NUNCA faça commits com `Co-authored-by:` preenchido.
- Todos os commits devem ter somente o author do GitHub; sem coautores ou trailers de agente de IA.

## Documentação do projeto

| Quando usar | Caminho | Nota |
| --- | --- | --- |
| Glossário e termos de domínio | `CONTEXT.md` | Linguagem canônica antes de nomear código |
| Escopo, jornadas e critérios de aceite | `docs/product.md` | O que o produto faz e não faz |
| Topologia, módulos e limites arquiteturais | `docs/architecture.md` | Monólito modular, Redis, redirect, hosts |
| Entidades, constraints e retenção | `docs/data-model.md` | Modelo persistente e regras de dados |
| Contratos HTTP e OpenAPI | `docs/api.md`, `docs/openapi.yaml` | Design-first; manter sincronizado |
| Segurança, privacidade e BFF | `docs/security.md` | Tokens, cookies, rate limits, URL policy |
| Estratégia de testes, E2E e gates | `docs/testing.md` | Pest, Playwright, cobertura e CI |
| Fases e prioridades | `docs/roadmap.md` | Sequência de entrega |
| Decisões confirmadas | `docs/decisions.md` | Prevalece sobre docs temáticos divergentes |
| ADRs pontuais | `docs/adr/*.md` | Registros formais de decisão |
| Padrões PHP/Laravel do projeto | `LARAVEL_CODE_DESIGN.md` | Controllers, Actions, Form Requests, DTOs |
| Ambiente Docker | `README.md` | Bootstrap, Makefile, troubleshooting |
