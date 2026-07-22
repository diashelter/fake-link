# Log de decisões confirmadas

Este documento consolida políticas confirmadas do Fake Link. Durante a fase de definição, ele prevalece sobre detalhes divergentes nos demais documentos temáticos.

## Produto e distribuição

| Tema | Decisão |
| --- | --- |
| Posicionamento | Portfólio orientado a produção, hospedado em modo controlado e somente por convite. |
| Jornada principal | Dashboard privado; redirect público e API permanecem demonstráveis. |
| Cadastro | Não existe cadastro público. Elegibilidade depende de allowlist exata no servidor. |
| Integrações | Gestão de tokens para integrações externas está adiada. |
| Repositório | Público, sob licença MIT. |
| Idioma da UI | Somente pt-BR. |

Ver [ADR 0001](adr/0001-production-minded-invite-only-scope.md).

## Identidade e sessões

| Tema | Decisão |
| --- | --- |
| Entidade canônica | `User`, com status `pending_verification`, `active`, `suspended` ou `deletion_pending`. |
| Registro | Correspondência exata do e-mail na allowlist; falhas de convite usam mensagem genérica. |
| Verificação | E-mail obrigatório via Resend. |
| Recuperação | Forgot password e reset password fazem parte do escopo. |
| Terms | Registrar versão e timestamp de aceitação. |
| Senha | 12 a 128 caracteres, com minúscula, maiúscula, dígito e símbolo. |
| Sessão completa | 7 dias de duração absoluta e 24 horas de inatividade. |
| Sessão restrita | 24 horas de duração absoluta e 1 hora de inatividade para `User` não verificado. |
| Perfil | Somente o nome pode ser alterado. |
| Revogação | Mudança ou reset de senha revoga todas as sessões. Há logout da sessão atual e logout-all, sem lista de dispositivos. |
| Campos removidos | `device_name` não faz parte do produto. |
| Operação | Ações operacionais sobre `User` não disparam e-mail. |

## BFF e credenciais

- O frontend oficial usa o Next.js como BFF.
- O browser mantém apenas uma sessão opaca em cookie `HttpOnly`, `Secure` e `SameSite=Lax`.
- O token Bearer fica no servidor, associado à sessão e criptografado com chave externa ao Redis.
- O BFF encaminha somente operações conhecidas e aplica proteção de origem e CSRF nas mutações.
- Tokens Bearer persistidos pelo Laravel ficam somente em hash.

Ver [ADR 0002](adr/0002-bff-token-architecture.md).

## Short Link e slug

| Tema | Decisão |
| --- | --- |
| Propriedade | Cada `Short Link` tem exatamente um `User` proprietário. |
| Unicidade | Slug global, permanente, minúsculo e sem diferenciação de caixa na entrada. |
| Imutabilidade | Slug, alias e proprietário não mudam; não existe `DELETE` de link. |
| Reserva | Slug nunca é reutilizado, mesmo quando sua reserva fica órfã. |
| Automático | 8 caracteres Base36 e no máximo cinco tentativas de colisão. |
| Personalizado | ASCII, 3 a 48 caracteres, maiúsculas normalizadas e pequena denylist. |
| Origem | `slug_source` é `automatic` ou `custom`. |
| Título | Opcional, até 160 caracteres, com trim e vazio convertido em `null`. |
| Destino | Editável, com histórico visível de versões imutáveis. O mesmo destino pode gerar vários links. |
| Intenção | `is_enabled` registra a intenção do `User`. |
| Status efetivo | `active`, `inactive`, `expired` ou `blocked`, derivado da intenção, expiração e bloqueio operacional. |
| Expiração | Opcional, futura e em UTC. |
| Bloqueio | Link `blocked` continua editável e com analytics privados visíveis. |
| Quota | Não há quota de links. |

Ver [ADR 0003](adr/0003-immutable-global-slugs-versioned-destinations.md).

## Validação de destinos

- Aceitar somente HTTP e HTTPS, com no máximo 2.048 caracteres após normalização.
- Exigir hostname público; rejeitar localhost, nomes especiais e literais de IP.
- Rejeitar o próprio domínio curto e qualquer `userinfo`.
- Aceitar portas personalizadas e remover a representação redundante de portas padrão.
- Normalizar authority e path, preservando query e fragment.
- Exibir alerta para destino HTTP, sem bloqueá-lo.

## Redirect

| Tema | Decisão |
| --- | --- |
| Domínios | Domínio curto registrável dedicado e aplicação em outro domínio; nomes exatos serão definidos no deploy. |
| Sucesso | `GET` válido responde `302` com `Cache-Control: no-store` e gera um `Click`. |
| HEAD | Permitido, com a mesma resolução e sem analytics. |
| Raiz | `302` para a landing page. |
| Canonicalização | Caixa diferente e barra final resolvem; percent encoding e segmentos extras são rejeitados. |
| Query de entrada | Ignorada e não anexada ao destino. |
| Ausência | `404` somente para slug nunca reservado. |
| Indisponibilidade | `410` para link `inactive`, `expired`, `blocked` ou reserva órfã. |
| Proteção | `429` para rate limit. |
| Falha operacional | `503` para indisponibilidade operacional ou falha de descriptografia. |
| Erros | HTML estático com marca e sem detalhes sensíveis. |
| Indexação | Domínio curto bloqueia todos os robots; domínio da aplicação usa `noindex`. |
| HSTS | Não será usado; o risco permanente de downgrade foi aceito. |

Ver [ADR 0006](adr/0006-no-hsts.md).

## Analytics e privacidade

| Tema | Decisão |
| --- | --- |
| Acesso | Privado e restrito ao proprietário do `Short Link`. |
| Disponibilidade | Best effort; falha de coleta ou persistência não bloqueia redirect válido. |
| Unicidade | Estimativa por `Short Link` e dia UTC via HMAC de link, IP canônico e dispositivo. |
| Dados brutos | IP e user-agent nunca são persistidos, inclusive em fila, falhas e logs. |
| Tráfego | `human`, `bot`, `preview` e `unknown` são retidos. |
| Unique | Calculado somente para `human`; nas demais classes é `null`. |
| Cliente | Somente dimensão de dispositivo; sem browser ou sistema operacional. |
| Referrer | Somente host público; privado, ausente ou inválido vira `direct`. |
| Breakdowns | Apenas totais. Referrers exibem principais valores e agrupam o restante em `other`. |
| Retenção | Detalhes por 90 dias, agregados por 366 dias e resolução horária por 31 dias. |
| Intervalo | Padrão de 30 dias; hora até 31 dias e dia até 366 dias; datas futuras são inválidas. |
| Atualização | Polling do dashboard a cada 60 segundos. |
| Exclusões | Sem exportação e sem analytics públicos. |

Ver [ADR 0004](adr/0004-best-effort-private-analytics.md).

## Interface

- Landing page estática e concisa.
- Fluxos de auth, lista e criação de links.
- Detalhe com abas Overview, Analytics e History.
- Tema somente claro e layout responsivo a partir de 360 px.
- WCAG 2.2 AA e suporte às duas versões mais recentes dos browsers definidos.
- Filtros da lista e de analytics persistem na query da URL.

## Arquitetura e operação

- Monólito modular com Laravel API Only e Next.js, executado por Docker Compose.
- PostgreSQL é a fonte de verdade; Redis atende cache, filas e dados efêmeros.
- O redirect não depende da persistência síncrona de analytics.
- Deploy inicial em uma única VM, com containers separados por responsabilidade e evolução guiada por métricas.
- OpenAPI 3.1 e Swagger UI documentam a API demonstrável.
- A referência de 1 milhão de redirects por dia é benchmark de capacidade, não SLA; o cenário nominal usa 120 RPS por 15 minutos.

Ver [ADR 0005](adr/0005-single-vm-modular-monolith.md).

## Fora do escopo confirmado

- Cadastro público, tokens de integração e MFA.
- QR Codes e domínios personalizados por `User`.
- Teams, billing e geolocation.
- Links protegidos por senha, limites por cliques e A/B testing.
- Batch, exportação e analytics públicos.
- Denúncia pública de abuso, página pública de status e feature flags.

## Estratégia de documentação

- Manter documentos temáticos atuais para produto, arquitetura, dados, API, segurança e testes.
- Usar este log como registro conciso e abrangente de políticas confirmadas.
- Criar ADR apenas para trade-offs difíceis de reverter e surpreendentes sem contexto.
- Manter `CONTEXT.md` exclusivamente como glossário, sem decisões de implementação.

## Bloqueios adiados para o lançamento

Estes itens não estão esquecidos e devem ser resolvidos antes da publicação controlada:

- Nomes exatos do domínio curto e do domínio da aplicação.
- Provedor da VM.
- Versões exatas e suportadas da stack.
- Terms e Privacy revisados juridicamente.
