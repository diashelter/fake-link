# Fake Link

Fake Link é um encurtador de URLs com criação e gestão de `Short Links`, redirect público e analytics privados. É um projeto de portfólio orientado a produção, hospedado em modo controlado e somente por convite; o dashboard é a jornada principal, enquanto o redirect e a API permanecem demonstráveis.

## Estado atual

O repositório está na fase de definição concluída. Comportamento, escopo, contrato OpenAPI e decisões estruturais estão sincronizados; o próximo marco é implementar a fundação descrita no roadmap.

Em caso de divergência durante essa fase, [decisions.md](docs/decisions.md) registra a política confirmada, os ADRs explicam os trade-offs duráveis e [product.md](docs/product.md) define o comportamento esperado.

## Stack prevista

- Backend: PHP com Laravel API Only
- Frontend e BFF: Next.js
- Testes backend: PestPHP
- Persistência: PostgreSQL
- Cache, filas e dados efêmeros: Redis
- Contrato: OpenAPI 3.1 com Swagger UI
- Ambiente: Docker e Docker Compose

## Documentação

- [Glossário de domínio](CONTEXT.md)
- [Produto, escopo e critérios de aceite](docs/product.md)
- [Log consolidado de decisões](docs/decisions.md)
- [Decisões arquiteturais](docs/adr/)
- [Arquitetura](docs/architecture.md)
- [Modelo de domínio e dados](docs/data-model.md)
- [Contrato e convenções da API](docs/api.md)
- [Especificação OpenAPI](docs/openapi.yaml)
- [Segurança e privacidade](docs/security.md)
- [Estratégia de testes](docs/testing.md)
- [Roadmap](docs/roadmap.md)

## Escopo resumido

- Cadastro restrito por allowlist de e-mail, verificação obrigatória e recuperação de senha.
- Criação de `Short Links` automáticos ou personalizados, sem reutilização de slugs.
- Destino editável com histórico imutável e estados efetivos explícitos.
- Redirect público resiliente em domínio curto dedicado.
- Analytics privados com minimização de dados e retenção limitada.
- Interface em pt-BR, responsiva e acessível.

## Licença

Repositório público distribuído sob a [licença MIT](LICENSE).
