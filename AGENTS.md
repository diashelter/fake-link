# AGENTS.md

## Objetivo

Este repositório contém o Fake Link, uma plataforma de encurtamento de URLs com criação de links personalizados, redirecionamento público e analytics privados.

## Stack obrigatória

- Backend em PHP com Laravel API Only.
- Frontend em Next.js.
- Testes backend com PestPHP.
- PostgreSQL como banco principal.
- Redis para cache, filas e dados efêmeros.
- OpenAPI 3.1 com Swagger UI para documentação da API.
- Docker e Docker Compose como ambiente padrão.

## Idioma

- Código, nomes técnicos, classes, métodos, arquivos de código e mensagens internas em inglês.
- Documentação e explicações arquiteturais em português do Brasil.
- Textos da interface do MVP são escritos em português do Brasil, sem i18n inicial.

## Arquitetura

- Backend organizado como monólito modular por domínio.
- Controllers finos; regras de negócio em Actions ou Services.
- Validação HTTP em Form Requests e saída em Resources.
- Frontend organizado por módulos de domínio.
- O redirect não deve depender da persistência síncrona de analytics.
- Redis deve ser usado como cache e broker da fila, com PostgreSQL como fonte de verdade.
- Mudanças no destino de um link devem preservar histórico.
- Slugs são globais, minúsculos, case-insensitive, únicos e permanentemente reservados.
- Nenhum IP ou user-agent bruto deve ser persistido nos eventos de analytics.
- Estimated Unique Clicks existem somente para tráfego humano, por Short Link e dia UTC.
- HSTS foi rejeitado permanentemente; não adicionar o header sem nova decisão explícita.

## Módulos iniciais

Backend:

- `Auth`
- `Links`
- `Redirects`
- `Analytics`
- `Operations`
- `Shared`

Frontend:

- `auth`
- `links`
- `analytics`
- `shared`

## Qualidade

- Priorizar mudanças pequenas, explícitas e testáveis.
- Não criar microsserviços ou abstrações prematuras.
- Cobrir regras de domínio e endpoints críticos com PestPHP.
- Manter `docs/openapi.yaml` sincronizado com a API.
- Atualizar a documentação quando uma decisão arquitetural ou regra de produto mudar.
- Executar desenvolvimento, testes e ferramentas pelo Docker sempre que os serviços estiverem disponíveis.

## Commits

- NUNCA incluir `Co-Authored-By`, `Co-Author-Committer` ou qualquer trailer/rodapé de coautoria de agente de IA na mensagem de commit.

## Segurança

- Aceitar apenas destinos HTTP ou HTTPS válidos.
- Nunca registrar tokens, senhas, IPs brutos ou query strings sensíveis.
- Aplicar rate limiting em autenticação, criação de links, redirect e analytics.
- Armazenar tokens Bearer somente como hash no Laravel. Quando o BFF precisar do
  valor reversível, mantê-lo criptografado no servidor com chave externa ao Redis.
- O frontend oficial deve manter o token no BFF, associado a uma sessão opaca em cookie `HttpOnly`, `Secure` e `SameSite=Lax`.
- O browser oficial deve acessar operações autenticadas somente por Route Handlers conhecidos do BFF.

## Referências

Antes de implementar uma funcionalidade, consultar:

- `docs/product.md`
- `docs/architecture.md`
- `docs/data-model.md`
- `docs/api.md`
- `docs/security.md`
- `docs/testing.md`
- `docs/roadmap.md`
- `docs/decisions.md`
- `CONTEXT.md`
