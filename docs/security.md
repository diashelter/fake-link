# Segurança e privacidade

## 1. Escopo e postura

Fake Link é um produto invite-only operado com controles de produção. Segurança prioriza proteção de contas e destinos, isolamento entre Users, disponibilidade do redirect, contenção de abuso e minimização de dados antes de persistência ou telemetria.

Os controles deste documento são requisitos do primeiro lançamento, salvo quando marcados como adiados. Não há MFA, lockout persistente, denúncia pública nem provedor externo de reputação nesta fase. Essas ausências são riscos conhecidos, compensados por convite restrito, rate limiting, comandos operacionais e observabilidade.

## 2. Modelo de ameaças

| Ameaça | Consequência | Controles principais | Risco residual |
| --- | --- | --- | --- |
| Roubo de Bearer ou sessão | Controle da conta e dos links | Hash no PostgreSQL, Bearer cifrado no BFF, cookie `__Host-`, TTL absoluto/idle, CSRF e revogação | Cliente direto continua responsável por guardar seu Bearer |
| Session fixation ou lookup de Redis | Reuso de sessão | ID aleatório de 256 bits, rotação no login e chave Redis derivada por HMAC | Compromisso simultâneo do browser e runtime permanece crítico |
| CSRF no BFF | Escrita não autorizada | `SameSite=Lax`, `Origin` exata e double-submit vinculado à sessão por HMAC | Extensões maliciosas e browser comprometido estão fora do controle do servidor |
| Acesso cruzado | Exposição de links ou analytics | Scope pelo User autenticado, Policies e `404` uniforme | Bugs de autorização exigem testes de regressão |
| Sequestro de alias | Redirecionamento de tráfego alheio | Reserva global, transacional e permanente | Alias legitimamente bloqueado não volta ao pool |
| Destino malicioso | Phishing, malware e dano reputacional | URL policy, acesso por convite, rate limits e block operacional | Sem reputação externa e sem denúncia pública |
| SSRF por destino | Acesso a rede interna | Nenhum fetch server-side no produto inicial | Funcionalidade futura de preview exigirá sandbox própria |
| Analytics forjado | Métricas incorretas | Classificação local, human uniques e limites em camadas | Tráfego distribuído sofisticado ainda pode distorcer totais |
| DoS no redirect | Indisponibilidade pública | Cloudflare, Nginx, Redis, fallback, budgets e limites por IP+slug/capacidade | Ataque distribuído depende também da capacidade Cloudflare |
| Poisoning de proxy headers | IP, trace e limites falsificados | Origin restrito à Cloudflare, ranges oficiais, headers substituídos | Ranges devem ser atualizados operacionalmente |
| Vazamento por cache, fila ou telemetria | Exposição de destino, identidade ou credencial | AES-GCM, sanitização antes da fila, allowlist e redação no Collector | Erros de instrumentação exigem testes contínuos |
| Supply-chain compromise | Imagem maliciosa | PR/checks, SBOM, Cosign, digest imutável e scan | Dependência assinada ainda pode conter vulnerabilidade lógica |
| Perda ou corrupção de dados | Indisponibilidade e perda de histórico | Volume cifrado, backup externo cifrado e restore testado | RPO de até 24 horas é aceito |
| Downgrade inicial de HTTPS | Interceptação da primeira navegação HTTP | Redirect imediato para HTTPS e cookies `Secure` | HSTS permanece ausente por decisão explícita |

## 3. Fronteiras de confiança e ingresso

Há dois domínios registráveis, com nomes finais adiados. Cloudflare é o único ingresso HTTP público e conecta ao Nginx origin em `Full (strict)`. O origin aceita apenas ranges oficiais da Cloudflare. PostgreSQL, Redis, Grafana e interfaces administrativas não são públicos; administração e deploy usam Tailscale.

O App host publica interface Next.js, BFF, `/api/v1` e `/docs`. O Short host aceita somente:

- `/` e `/robots.txt`;
- `GET /{slug}` e `HEAD /{slug}`;
- respostas de erro necessárias.

Nginx rejeita outros métodos e caminhos antes do Laravel. O cache de edge da Cloudflare é ignorado no Short host para não conservar destinos editáveis ou erros além da política da aplicação.

Nginx remove headers de trace recebidos, gera ou substitui request ID e substitui headers de forwarding. `CF-Connecting-IP` somente é aceito quando a conexão vem de um range oficial da Cloudflare; qualquer valor vindo de outra origem é descartado. Laravel confia exclusivamente no proxy interno configurado.

## 4. Convite, registro e autenticação

### 4.1 Convites e e-mail

- Registro exige correspondência exata com a allowlist de e-mails convidados.
- A allowlist é mantida cifrada com SOPS, não em código, imagem ou log.
- Comparação usa a forma canônica definida para e-mail, sem regras implícitas específicas de provider.
- Resend envia verificação e recuperação somente para fluxos autorizados.
- O domínio de envio deve ter SPF, DKIM e DMARC válidos antes do lançamento.
- Respostas públicas não confirmam se um e-mail existe, está convidado ou possui conta.

### 4.2 Senhas

- Comprimento entre 12 e 128 caracteres.
- Pelo menos um caractere de cada uma das quatro categorias ASCII: maiúscula, minúscula, dígito e símbolo.
- Hash Argon2id calibrado no hardware de produção para alvo de aproximadamente 250 ms e memória mínima de 64 MiB.
- O custo é medido novamente após mudanças de VM, runtime ou concorrência.
- Senha nunca entra em log, trace, evento, métrica, fila ou analytics.

Não há MFA nem lockout persistente inicialmente. Rate limiting, resposta uniforme e acesso invite-only são os controles compensatórios; a decisão deve ser revista se exposição ou população crescerem.

### 4.3 Verificação e recuperação

- Verificação de e-mail é concluída por `POST` explícito, nunca por `GET` com efeito colateral.
- Token de verificação expira em 60 minutos.
- Token de reset expira em 30 minutos.
- Tokens são aleatórios, de uso único e armazenados somente como hash no PostgreSQL.
- Uso, expiração, substituição de senha ou emissão de token mais novo invalida o token conforme o fluxo.
- A confirmação revoga a Restricted Session; acesso completo exige novo login.
- URLs e query strings com tokens não são registradas nem propagadas como referrer.

O modelo inicial tem apenas token de sessão e token de verificação. Abilities para integrações e administração delegada de tokens ficam adiadas até existir caso de uso confirmado.

## 5. Sessão BFF

O Next BFF é o único gateway autenticado do browser oficial. O browser não recebe Bearer e não chama endpoints Laravel privados diretamente.

### 5.1 Armazenamento

1. Após autenticação, Laravel emite Bearer aleatório e persiste somente seu hash.
2. Next cifra o Bearer com AES-256-GCM usando chave fora do Redis.
3. O browser recebe um ID opaco aleatório de 256 bits.
4. O cookie usa prefixo `__Host-`, `HttpOnly`, `Secure`, `SameSite=Lax`, `Path=/` e nenhuma diretiva `Domain`.
5. A chave Redis é `HMAC(session_id)`; o ID bruto não é armazenado como chave pesquisável.
6. O valor Redis contém somente o estado mínimo de sessão e o Bearer autenticado pelo GCM.

A chave AES-GCM de sessão e a chave HMAC de lookup são distintas. Nonces GCM nunca são reutilizados com a mesma chave. O key ID acompanha o envelope criptográfico sem expor material secreto.

### 5.2 Expiração e falha

| Estado | Expiração absoluta | Expiração por inatividade |
| --- | --- | --- |
| User verificado | 7 dias | 24 horas |
| User não verificado | 24 horas | 1 hora |

O ID é rotacionado no login e em mudanças sensíveis de estado. Perda, flush ou eviction do Redis encerra sessões; não há recuperação por cookie. Rotação da chave BFF também encerra todas as sessões e deve ser tratada como operação comunicável internamente.

Logout sempre expira o cookie no browser e tenta remover a sessão Redis e revogar o Bearer. Se Redis ou Laravel estiver indisponível, a remoção remota é best-effort e gera métrica e alerta, sem fila de reconciliação; registros ou tokens órfãos permanecem sujeitos aos respectivos TTLs, limites de inatividade e expiração absoluta.

### 5.3 CSRF e proxy

Rotas mutáveis exigem simultaneamente:

- `Origin` presente e exatamente igual ao App host HTTPS;
- cookie de sessão válido;
- double-submit CSRF cujo valor é vinculado à sessão por HMAC e comparado em tempo constante.

Ausência, origem `null`, origem divergente ou HMAC inválido são rejeitados. O BFF possui allowlist estática de método e endpoint; parâmetros nunca selecionam URL upstream arbitrária. Respostas privadas do BFF e da API usam `Cache-Control: private, no-store`.

## 6. API Bearer direta

`/api/v1` permanece publicamente acessível para Users convidados e uso no Swagger. Bearers:

- têm entropia criptográfica e aparecem em texto claro somente na emissão;
- são armazenados apenas como hash pelo Laravel;
- são enviados exclusivamente em `Authorization: Bearer` sobre HTTPS;
- são revogáveis no logout e em workflows de segurança;
- atualizam `last_used_at` no máximo uma vez a cada 15 minutos por token.

Swagger `try-it-out` não persiste token em storage, cookie, URL ou reload e não aceita origem cruzada. Exemplos nunca contêm credenciais reais. Clientes diretos são responsáveis por armazenamento seguro do token.

## 7. Autorização e operações

- Toda query privada parte do User autenticado; `user_id` do request nunca determina ownership.
- Policies cobrem Short Link, histórico, analytics e operações relacionadas.
- Recurso pertencente a outro User retorna `404` uniforme quando a existência também é confidencial.
- Alias, ID público ou slug não concedem acesso privado.
- Suspensão de User e bloqueio de Short Link têm efeito na resolução efetiva e invalidam cache após commit.
- Reativação e desbloqueio são comandos separados e auditados, não flags editáveis por endpoint genérico.
- Exclusão de conta é assíncrona, persistida e reconciliada pelo scheduler a partir do PostgreSQL.
- Operações não notificam o User sobre suspensão, bloqueio ou desbloqueio.

Auditoria operacional é append-only, retida por 366 dias e registra ação, ator interno permitido, alvo por ID interno permitido, resultado e timestamp UTC. Não registra e-mail, IP, user-agent, destino, body ou motivo contendo texto livre sensível.

## 8. Destinos e slugs

### 8.1 URL policy

Destinos devem:

- usar exclusivamente `http` ou `https`;
- possuir host sintaticamente válido;
- ter no máximo 2.048 caracteres;
- não conter credenciais `user:password@host`;
- não conter caracteres de controle nem formas ambíguas entre parsers;
- ser normalizados sem alterar query string ou fragment semanticamente relevante;
- ser novamente validados antes de cada persistência de nova versão.

Não há fetch server-side, preview remoto, verificação de disponibilidade ou reputação no desenho inicial. Se uma dessas funções surgir, deverá ser isolada, bloquear loopback, link-local e redes privadas, controlar DNS rebinding, limitar redirects e tamanho, e usar timeout próprio.

O produto não oferece denúncia pública nem integra provedor de reputação. Operators usam comandos de block/unblock e suspend/reactivate. A interface deve alertar para não encurtar reset links, URLs assinadas ou segredos em query string.

Destinos e histórico são cifrados na aplicação com AES-256-GCM antes do PostgreSQL. O armazenamento usa keyring persistente versionado e separado do keyring de cache. Backups recebem uma camada adicional de criptografia.

### 8.2 Slugs e redirect

- Slugs automáticos usam CSPRNG.
- Aliases seguem allowlist de caracteres, tamanho e palavras reservadas.
- A restrição única PostgreSQL é a autoridade contra colisão.
- A reserva é global e permanente, mesmo após exclusão.
- `Location` recebe somente destino validado e autenticado pelo armazenamento.
- Redirect usa `302 Found` e `Cache-Control: no-store`; `HEAD` não inclui body.
- Erros não revelam owner, destino, suspensão, bloqueio ou expiração.

## 9. Cache de redirect

Snapshots em Redis usam AES-256-GCM com chave dedicada e payload mínimo. Chave, nonce e tag seguem envelope versionado; falha de autenticação nunca produz redirect.

- Link ativo: TTL base de 5 minutos.
- Ausente ou indisponível: TTL base de 30 segundos.
- Jitter de mais ou menos 10% evita expiração sincronizada.
- TTL nunca ultrapassa a expiração do link.
- Não há stale além do TTL nem distributed lock.
- Invalidação ocorre em listener síncrono best-effort após commit; TTL limita sua falha.

Redis indisponível força consulta PostgreSQL. Um hit decifrável pode atender durante falha do PostgreSQL somente até seu TTL. Miss com PostgreSQL indisponível retorna `503`. Cache corrompido gera métrica, remoção best-effort e fallback PostgreSQL. Falha persistente de decrypt do destino na fonte de verdade retorna `503`.

## 10. Privacidade de analytics

IP, user-agent e referenciador bruto existem somente em memória durante `recordClick`, depois de a resposta de redirect ter sido enviada. Não entram em Redis, PostgreSQL, `failed_jobs`, logs ou traces.

Antes da fila, `Analytics`:

1. canonicaliza o IP;
2. classifica tráfego como `human`, `bot`, `preview` ou `unknown`;
3. deriva categoria ampla de device com Matomo DeviceDetector local;
4. calcula human unique como `HMAC(day_key, link_id + canonical_ip + device)`;
5. reduz qualquer referenciador ao campo sanitizado expressamente permitido;
6. descarta definitivamente os valores brutos.

Somente tráfego `human` participa de uniques. Browser e sistema operacional não são persistidos. Nenhuma chamada externa é feita para classificação. A chave diária é derivada e rotacionada na boundary UTC; dias distintos não devem ser correlacionáveis pelo identificador.

Eventos detalhados ficam em partições diárias por 90 dias; agregados horários ficam 31 dias e diários 366 dias. Publicação perdida depois da resposta é aceita e medida. O comando de rebuild recompõe somente dados que chegaram ao PostgreSQL e ainda estão nas partições detalhadas.

## 11. Rate limiting e abuso

Entradas de IP e e-mail são canonicalizadas e transformadas por HMAC antes de virar chave Redis. Valores brutos não aparecem em chaves, métricas ou logs. Chaves HMAC são separadas por finalidade e rotacionadas em boundary planejada para evitar bypass ou mistura de janelas.

| Superfície | Limite da aplicação | Dimensão |
| --- | --- | --- |
| Registro | 5 por hora | IP |
| Login | 5 por minuto | E-mail + IP |
| Login adicional | 30 por minuto | IP |
| Reenvio de verificação | 3 por hora | Conta/e-mail canônico |
| Solicitação de reset | 3 por hora | E-mail + IP |
| Conclusão de reset | 5 por hora | IP e token/fluxo |
| Verificação de e-mail | 5 por hora | Conta |
| Criação de Short Link | 60 por minuto | Conta |
| Demais escritas privadas | 120 por minuto | Conta |
| Leituras privadas | 300 por minuto | Token |
| Consultas de analytics | 60 por minuto | Conta |
| Redirect | 120 por minuto | IP + slug |

Nginx acrescenta limite por IP e proteção de capacidade global. Não há limite global por slug, pois permitiria derrubar um link popular com tráfego distribuído. Cloudflare fornece a camada externa, mas regras de aplicação continuam obrigatórias.

Exceder limite produz resposta uniforme e `Retry-After` quando aplicável, sem revelar conta, convite ou existência de link. Rate limits são controles de abuso, não substitutos de capacidade e alertas.

## 12. Transporte, browser e headers

- HTTPS é obrigatório em produção e desenvolvimento local.
- Requests da API e do BFF aceitam no máximo 64 KiB; o Short host não aceita body.
- Produção usa Cloudflare `Full (strict)` e certificado Let's Encrypt DNS-01 no origin.
- Cookies nunca perdem `Secure` ou `HttpOnly` para funcionar localmente.
- CORS é same-origin only; não há origem terceira permitida com credenciais.
- Não são carregados scripts de terceiros.
- CSP restringe scripts, conexões, frames, forms e bases aos recursos necessários.
- `X-Content-Type-Options: nosniff` é obrigatório.
- `Referrer-Policy` deve impedir vazamento de paths e query strings sensíveis.
- `Permissions-Policy` desabilita capacidades não usadas.
- Respostas privadas da API e BFF usam `private, no-store`.
- Todo o App host e todas as respostas HTML do Short host declaram `noindex`; o Short host também publica `robots.txt` com `Disallow: /`.

### 12.1 Exceção permanente de HSTS

O header `Strict-Transport-Security` deve permanecer explicitamente ausente em todos os hosts e ambientes de produção. Não há plano de habilitá-lo após estabilização.

Risco aceito: na primeira visita, um atacante em posição de rede pode impedir o upgrade HTTP e executar downgrade. Redirect para HTTPS, TLS estrito no origin e cookies `Secure` reduzem exposição posterior, mas não eliminam esse cenário. Testes de lançamento e monitoramento de headers devem verificar ausência de HSTS para evitar mudança acidental dessa decisão.

## 13. Telemetria e logs

Uma allowlist positiva define atributos permitidos. O OpenTelemetry Collector aplica redação adicional antes de Prometheus, Tempo e Loki.

É proibido registrar ou anexar a métricas/traces:

- URL ou query string;
- IP ou user-agent;
- token, cookie, senha ou header de autenticação;
- e-mail;
- destino de Short Link;
- request ou response body;
- referenciador bruto.

Logs e traces podem conter request ID substituído pelo Nginx, route template, status, duração e IDs internos expressamente aprovados. Métricas nunca usam IDs, slug, e-mail, request ID ou qualquer valor de alta cardinalidade como label.

Tail sampling conserva todos os erros e requests lentos, 10% dos sucessos privados e 1% dos redirects bem-sucedidos. A retenção é 30 dias para métricas e logs e 7 dias para traces. Grafana só é acessível por túnel SSH sobre Tailscale. Better Stack recebe apenas sinal de uptime; não há status page pública.

## 14. Segredos, criptografia e supply chain

- SOPS com age protege segredos versionados; recovery usa vault e GitHub como caminhos separados.
- Segredos são injetados no runtime e nunca entram em imagem, Git, SBOM, log, trace ou argumento de build.
- Chaves têm finalidade única: dados persistentes, cache, sessão BFF, lookup HMAC, analytics HMAC e rate limit HMAC não compartilham material.
- AES-256-GCM usa keyrings versionados para dados persistentes e cache.
- Rotação preserva somente as chaves anteriores necessárias à janela de migração.
- Rotação BFF encerra sessões; rotação HMAC ocorre em boundary documentada.
- Nonces GCM são únicos por chave e autenticação sempre é validada antes do uso.
- PostgreSQL e Redis usam credenciais distintas com mínimo privilégio.

GitHub Actions constrói imagens `amd64/arm64`, gera SBOM, assina com Cosign e publica tags SemVer imutáveis no GHCR. Deploy verifica assinatura e digest. Pull request e checks são obrigatórios. Dependências e imagens passam por scan, mas findings exigem triagem humana e prazo de correção proporcional ao risco.

## 15. Dados, backup e exclusão

- SSD da VM deve ter criptografia do provider habilitada.
- `pg_dump` custom diário é cifrado antes de Cloudflare R2.
- A credencial da VM permite escrita, não exclusão.
- Retenção: 14 diários e 3 mensais.
- Restore é testado antes do lançamento e trimestralmente.
- RPO é 24 horas e RTO é 4 horas.
- Redis e backends OpenTelemetry não são restaurados por backup.

Exclusão de conta é assíncrona e reconciliada pelo scheduler. Remove os dados de domínio do User, mas mantém a reserva mínima do slug sem owner ou destino e a auditoria opaca por 366 dias. Eventos e agregados seguem suas retenções próprias; o produto não promete rebuild de dados já expirados ou nunca publicados.

## 16. Aspectos legais e comunicação

Antes de usar o produto, o User aceita uma versão identificada dos Termos de Uso e recebe acesso à Política de Privacidade vinculada no mesmo fluxo. Devem ser persistidos versão dos Termos, timestamp UTC e User, sem dados de rede desnecessários.

Lançamento exige revisão humana jurídica dos dois documentos, incluindo finalidade de analytics, retenções, exclusão, risco de links maliciosos, subprocessadores e mecanismo de contato. Documentação técnica não substitui aconselhamento jurídico.

Não há notificação ao User para suspensão, reativação, block ou unblock. Não há fluxo público de denúncia nem status page pública. Incidentes que exijam comunicação são decididos pelo runbook e pelas obrigações legais aplicáveis.

## 17. Resposta a incidentes e runbooks

Runbooks devem cobrir, no mínimo:

- revogar Bearers e encerrar todas as sessões BFF;
- rotacionar cada keyring e cada chave HMAC sem confundir finalidades;
- bloquear/desbloquear Short Link e suspender/reativar User;
- responder a abuso sem registrar destino ou identidade em texto livre;
- atualizar ranges oficiais da Cloudflare e validar trust de proxy;
- tratar corrupção de cache e falha persistente de decrypt;
- drenar, inspecionar e reproduzir `failed_jobs` sanitizados;
- recuperar PostgreSQL do R2 dentro do RTO;
- executar exclusão e analytics rebuild com reconciliação;
- fazer rollback por digest assinado em até 60 segundos de interrupção;
- investigar por request ID e route template sem elevar coleta de dados proibidos.

Se um segredo aparecer em log, trace, imagem ou repositório, ele é considerado comprometido: conter acesso, revogar/rotacionar, preservar evidências sanitizadas e verificar todos os destinos onde o artefato foi replicado.

## 18. Gate de lançamento

O lançamento fica bloqueado até haver evidência de:

- invite allowlist exata protegida por SOPS;
- SPF, DKIM e DMARC válidos no Resend;
- calibração Argon2id no host de referência;
- testes de autorização cross-User e respostas uniformes;
- teste de CSRF, cookie, expiração absoluta/idle e perda do Redis;
- teste de SSRF por ausência de fetch e suite de URL parser;
- rate limits nas dimensões documentadas, usando somente HMAC no Redis;
- Short host restrito a caminhos e métodos permitidos, sem edge cache;
- headers de segurança, `noindex`, `robots.txt` e ausência intencional de HSTS;
- teste automatizado de telemetria sem URL, query, IP, UA, token, e-mail, destino ou body;
- restore pré-lançamento, rotação de chaves e rollback assinados exercitados;
- benchmark com OpenTelemetry completo ativo e metas aprovadas;
- Termos de Uso e Política de Privacidade versionados e revisados por pessoa habilitada.
