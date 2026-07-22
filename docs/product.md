# Produto, escopo e critérios de aceite

## 1. Visão

Fake Link permite que um `User` crie, organize e acompanhe `Short Links` em um dashboard privado. Cada endereço curto redireciona publicamente para um destino editável e produz analytics privados sem tornar a persistência de métricas uma dependência do redirect.

O produto é um portfólio orientado a produção, operado em modo controlado e somente por convite. O dashboard é a experiência principal; o redirect público e a API continuam demonstráveis. Não há cadastro público nem gestão de tokens para integrações nesta etapa.

## 2. Jornadas do produto

1. Um convidado elegível cria e verifica sua conta, aceita os Terms e entra no dashboard.
2. O `User` cria um `Short Link` automático ou escolhe um `Custom Alias`.
3. O `User` lista, pesquisa e filtra seus links, edita dados permitidos e consulta o histórico de destinos.
4. Um visitante abre a URL curta e recebe o redirect ou uma página de erro apropriada.
5. O proprietário acompanha Overview e Analytics privados do link.

## 3. Autenticação e conta

- O cadastro aceita somente correspondência exata de e-mail presente na allowlist do servidor e apresenta erro genérico quando não puder prosseguir.
- A verificação de e-mail é obrigatória e enviada por Resend.
- O produto oferece forgot password e reset password.
- O cadastro registra a versão aceita dos Terms e o timestamp da aceitação.
- A senha possui de 12 a 128 caracteres e exige letra minúscula, letra maiúscula, dígito e símbolo.
- `User` possui status `pending_verification`, `active`, `suspended` ou `deletion_pending`.
- Antes da verificação, a sessão é restrita e dura no máximo 24 horas, com expiração após 1 hora de inatividade.
- A sessão completa dura no máximo 7 dias, com expiração após 24 horas de inatividade.
- O perfil permite alterar somente o nome.
- Alterar ou redefinir a senha revoga todas as sessões.
- O `User` pode encerrar a sessão atual ou todas as sessões, sem lista de dispositivos.
- Ações operacionais sobre contas não enviam e-mail ao `User`.

## 4. Short Links

- Cada `Short Link` possui exatamente um `User` proprietário.
- O slug é global, permanente, imutável, armazenado em minúsculas e resolvido sem diferenciar caixa.
- O slug automático possui 8 caracteres Base36. Após cinco colisões, a criação falha.
- O `Custom Alias` aceita entrada ASCII de 3 a 48 caracteres, normaliza maiúsculas para minúsculas e respeita uma pequena denylist.
- `slug_source` informa `automatic` ou `custom`.
- O mesmo destino pode originar vários `Short Links`.
- O título é opcional, possui até 160 caracteres, remove espaços externos e converte texto vazio em `null`.
- O destino pode ser alterado; cada alteração cria uma versão imutável e visível no histórico.
- Alias e proprietário não podem ser alterados.
- Não existe exclusão de link. O slug continua reservado mesmo sem o link associado.
- `is_enabled` representa a intenção do `User`; o status efetivo é `active`, `inactive`, `expired` ou `blocked`.
- A expiração é opcional, futura e expressa em UTC.
- Um link `blocked` permanece editável e conserva analytics visíveis ao proprietário.
- Não há quota de links.

## 5. Destinos

Uma URL de destino:

- usa `http` ou `https` e possui no máximo 2.048 caracteres após normalização;
- possui hostname público e não usa localhost, host especial ou literal de IP;
- não aponta para o próprio domínio curto;
- pode usar porta personalizada, mas não contém `userinfo`;
- preserva query e fragment;
- normaliza authority, porta padrão e path sem mudar o significado do destino.

Destinos HTTP são aceitos com alerta visível sobre a ausência de transporte seguro.

## 6. Redirect público

- O redirect usa um domínio curto registrável dedicado, separado do domínio da aplicação.
- `GET /{slug}` válido responde `302 Found` com `Cache-Control: no-store`.
- Somente um `GET` válido gera `Click`; `HEAD` é aceito sem analytics.
- A raiz do domínio curto responde `302` para a landing page.
- Caixa diferente e uma única barra final resolvem o mesmo slug.
- Percent encoding no slug e segmentos extras são rejeitados.
- A query recebida na URL curta é ignorada e não altera o destino.
- Slug nunca reservado responde `404 Not Found`.
- Link `inactive`, `expired`, `blocked` ou reserva órfã responde `410 Gone`.
- Rate limit excedido responde `429 Too Many Requests`.
- Falha operacional ou de descriptografia responde `503 Service Unavailable`.
- Erros usam páginas HTML estáticas e identificadas com a marca, sem dados sensíveis.
- O domínio curto publica `robots.txt` com bloqueio total; páginas do domínio da aplicação usam `noindex`.
- HSTS não é usado, inclusive em produção; o risco de downgrade é aceito.

Os nomes exatos dos domínios serão definidos antes do lançamento.

## 7. Analytics

- Analytics são privados e acessíveis somente pelo proprietário do `Short Link`.
- O produto apresenta totais, série temporal, dispositivos e principais referrers, agrupando o restante em `other`.
- Tráfego é classificado como `human`, `bot`, `preview` ou `unknown`, e todas as classes são retidas.
- Estimated Unique Clicks existem somente para tráfego `human`, por `Short Link` e dia UTC; para outras classes o valor é `null`.
- A identificação estimada combina o link, o IP canônico e o dispositivo sem persistir IP ou user-agent brutos.
- A dimensão de cliente mostra somente dispositivo, sem browser ou sistema operacional.
- Referrer conserva somente host público; origem privada, ausente ou inválida é `direct`.
- Breakdowns apresentam apenas totais, não Estimated Unique Clicks.
- Eventos detalhados permanecem por 90 dias, agregados por 366 dias e resolução horária por 31 dias.
- O período padrão é de 30 dias. Granularidade horária aceita até 31 dias e diária até 366 dias.
- Datas futuras são inválidas.
- O dashboard atualiza analytics por polling a cada 60 segundos.
- Não há analytics públicos nem exportação.

## 8. Interface

- Landing page estática e concisa.
- Fluxos de cadastro, verificação, login, forgot password e reset password.
- Lista e criação de links.
- Detalhe do link com abas Overview, Analytics e History.
- Interface somente em pt-BR e tema somente claro.
- Layout funcional a partir de 360 px e compatível com as duas versões mais recentes dos browsers suportados.
- Conformidade com WCAG 2.2 AA.
- Filtros da lista e de analytics são representados na query da URL.

## 9. Critérios de aceite

### Conta

- E-mail fora da allowlist não cria conta nem revela a condição do convite.
- Conta não verificada acessa somente o fluxo permitido pela sessão restrita.
- Sessões respeitam os limites absoluto e de inatividade aplicáveis.
- Mudança ou reset de senha invalida todas as sessões existentes.
- Aceitação dos Terms é auditável por versão e timestamp.

### Link e destino

- Criações concorrentes nunca atribuem o mesmo slug a dois links.
- Slug reservado nunca volta a ser disponibilizado.
- Entrada em caixa diferente resolve para o slug canônico em minúsculas.
- Alterar destino preserva todas as versões anteriores e não altera slug nem analytics.
- URLs privadas, especiais, com IP literal, `userinfo` ou domínio curto próprio são rejeitadas.
- `is_enabled`, expiração e bloqueio produzem um dos quatro status efetivos previstos.

### Redirect

- Um link válido responde `302` e não depende da persistência síncrona de analytics.
- `HEAD` nunca gera `Click`.
- Slugs ausentes e indisponíveis produzem os códigos definidos sem revelar proprietário ou destino.
- Query recebida, segmentos extras e percent encoding não modificam a resolução.

### Analytics e privacidade

- Somente o proprietário consulta analytics e histórico.
- Nenhum evento, fila ou log persiste IP ou user-agent bruto.
- Unicidade estimada é calculada apenas para tráfego `human` no dia UTC.
- Filtros e retenções impedem períodos futuros ou fora da resolução disponível.
- Falha de analytics pode perder métricas, mas não impedir um redirect válido.

### Qualidade

- Fluxos críticos possuem testes automatizados e contrato OpenAPI sincronizado.
- O ambiente completo é reproduzível com Docker Compose.
- A interface atende responsividade, acessibilidade e suporte de browsers definidos.
- A referência de 1 milhão de redirects por dia é validada como benchmark, não como SLA ou tráfego prometido.

## 10. Fora do escopo

- Cadastro público e gestão de tokens para integrações externas.
- QR Codes e domínios personalizados por `User`.
- Teams, billing e geolocation.
- Links protegidos por senha, limites de cliques e A/B testing.
- Operações em batch, exportação e analytics públicos.
- Denúncia pública de abuso, MFA, página pública de status e feature flags.

## 11. Bloqueios para lançamento

Antes da publicação controlada devem ser definidos os domínios exatos, o provedor da VM e as versões exatas da stack. Terms e Privacy também precisam de revisão jurídica.
