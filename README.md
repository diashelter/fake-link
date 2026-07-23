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
- [Guia de design PHP/Laravel](LARAVEL_CODE_DESIGN.md)

## Escopo resumido

- Cadastro restrito por allowlist de e-mail, verificação obrigatória e recuperação de senha.
- Criação de `Short Links` automáticos ou personalizados, sem reutilização de slugs.
- Destino editável com histórico imutável e estados efetivos explícitos.
- Redirect público resiliente em domínio curto dedicado.
- Analytics privados com minimização de dados e retenção limitada.
- Interface em pt-BR, responsiva e acessível.

## Ambiente Docker

A interface operacional do ambiente local é o `Makefile` na raiz (`make help` lista os targets).

### Pré-requisitos

- Docker Engine e Docker Compose v2
- Portas livres `80` e `443` (ou ajuste `NGINX_HTTPS_PORT` no `.env`)
- Em desenvolvimento, confiança na CA local (ver `make trust-ca`)

### Bootstrap

```bash
cp .env.example .env
make up
```

`make up` valida variáveis, gera certificados de desenvolvimento, sobe a stack e aplica migrations.

### URLs locais

| Host | Uso |
| --- | --- |
| `https://app.localhost` | App / BFF / API (`/api/v1`) |
| `https://app.localhost/health` | Readiness do frontend |
| `https://app.localhost/docs` | Swagger UI (`make up-docs`, profile `docs`) |
| `https://go.localhost` | Short host (redirect) |
| `https://go.localhost/health` | Readiness do backend via Nginx |

### Confiança na CA de desenvolvimento

```bash
make trust-ca
```

O script gera os certificados em `docker/nginx/certs/` e imprime o comando de importação por SO. Em CI, os smokes usam `curl -k`.

### Targets úteis

| Target | Ação |
| --- | --- |
| `make help` | Lista targets |
| `make up` / `make down` | Sobe / derruba a stack |
| `make up-docs` | Stack + Swagger UI |
| `make smoke` | Health + rotas Nginx |
| `make lint` | Gates de qualidade backend (Pint, Larastan, PHPMD, Pest Arch, Pest) via Docker |
| `make test-backend-coverage` | Pest com cobertura PCOV no container backend |
| `make test` | Pest, Vitest, compose gates e smoke |
| `make test-backend` / `make test-frontend` | Suites isoladas |
| `make logs` / `make ps` | Observabilidade operacional |

CI backend: workflow [`.github/workflows/backend-quality.yml`](.github/workflows/backend-quality.yml) (PR e push em `main`) executa os mesmos targets via Docker Compose — sem PHP/Composer no host do runner.

Perfis Compose: `test` (CI isolado), `docs`, `benchmark`, `observability`. Produção usa `docker-compose.prod.yml`. Build multiarch: `docker/scripts/build-multiarch.sh`.

### Seeds determinísticos

Seeds e fixtures determinísticos ficam restritos a **local** e **CI**. Não rodar seed de demo em produção; dados de produção vêm de fluxos reais ou jobs controlados.

### Troubleshooting

| Sintoma | O que verificar |
| --- | --- |
| `curl` rejeita HTTPS | Importar a CA (`make trust-ca`) ou usar `-k` só em CI |
| Porta 443 em uso | Alterar `NGINX_HTTPS_PORT` ou liberar a porta |
| `.env` incompleto | `cp .env.example .env` e rodar `docker/scripts/validate-env.sh` |
| Serviço `unhealthy` | `make ps` e `make logs`; Postgres/Redis precisam ficar healthy antes do backend |
| `/docs` 502 | Subir com `make up-docs` (profile `docs`) |
| Conflito com stack de teste | Profile `test` usa `COMPOSE_PROJECT_NAME=fake_link_test` e não publica portas de datastore |

## Licença

Repositório público distribuído sob a [licença MIT](LICENSE).
