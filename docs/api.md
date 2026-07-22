# Contrato e convenções da API

## 1. Escopo e hosts

- O contrato é design-first, descrito em OpenAPI 3.1 em `docs/openapi.yaml`.
- A API Laravel é pública para usuários convidados e usa o prefixo `/api/v1` no mesmo host da aplicação.
- O Swagger UI público fica em `/docs` no host da aplicação. O `try-it-out` não persiste credenciais entre recargas.
- O host curto é um servidor separado e expõe somente redirect, raiz e `robots.txt`.
- O BFF do frontend não faz parte deste contrato. Rotas do BFF, cookies e comandos internos de operação não são documentados na OpenAPI.
- Não há CORS entre origens: clientes acessam a API no próprio host e integrações server-to-server não dependem de CORS.
- A licença da especificação é MIT.

## 2. Convenções gerais

- JSON em UTF-8 é usado na API. Redirects públicos retornam `302` ou HTML mínimo nos erros.
- Respostas JSON de sucesso possuem `data` e, quando necessário, `meta`; não possuem mensagem de sucesso.
- Todos os erros esperados possuem `code`, `message` e `request_id`. Os códigos e mensagens da API são em inglês.
- Erros de validação acrescentam `errors[field]`, um array de objetos `{code, message}`.
- Toda resposta da API envia `X-Request-ID`. O mesmo valor aparece em `request_id` nas respostas de erro.
- Respostas de autenticação e de recursos privados enviam `Cache-Control: private, no-store`.
- Datas e horas são UTC no formato ISO 8601 estrito com sufixo `Z`, por exemplo `2026-07-16T14:30:00Z`.
- Datas dos filtros de analytics usam `YYYY-MM-DD` e representam dias UTC completos.
- IDs de recursos são ULIDs. Identificadores internos de clique nunca são expostos.
- Recursos privados inexistentes ou pertencentes a outro usuário retornam o mesmo `404`.
- Métodos não suportados retornam `405 METHOD_NOT_ALLOWED`. Corpos acima do limite retornam `413 PAYLOAD_TOO_LARGE`.
- Requests da API e do BFF aceitam no máximo 64 KiB; o host curto não aceita body.
- Falhas temporárias da aplicação retornam `503 SERVICE_UNAVAILABLE`; timeouts de dependências ou gateway retornam `504 GATEWAY_TIMEOUT` quando aplicável.

Exemplo de sucesso:

```json
{
  "data": {
    "id": "01J2Z7FJ8M4W2N6P3Q9R5S1T0V"
  }
}
```

Exemplo de coleção:

```json
{
  "data": [],
  "meta": {
    "next_cursor": null,
    "per_page": 20
  }
}
```

Exemplo de validação:

```json
{
  "code": "VALIDATION_FAILED",
  "message": "The given data was invalid.",
  "request_id": "01K0C2Y7Q3R4S5T6V7W8X9Y0Z1",
  "errors": {
    "destination_url": [
      {
        "code": "INVALID_DESTINATION_URL",
        "message": "The destination URL is not allowed."
      }
    ]
  }
}
```

## 3. Autenticação e conta

### 3.1. Tokens

A API aceita `Authorization: Bearer <token>`. Existem somente dois tipos de token:

- `verification`: emitido no registro ou no login correto de uma conta pendente; dura no máximo 24 horas, expira após 1 hora sem uso e permite consultar o estado do User, reenviar/concluir a verificação e fazer logout.
- `session`: emitido somente por um novo login após a verificação; dura no máximo 7 dias e expira após 24 horas sem uso.

O token completo é mostrado apenas na emissão e armazenado somente como hash pelo Laravel. Não existem listagem de tokens, `device_name`, tokens de integração ou abilities públicas no MVP. Verificar o e-mail não converte o token restrito em sessão: o usuário precisa fazer novo login.

Alteração ou recuperação de senha e `logout-all` revogam todos os tokens da conta. `logout` revoga somente o token atual.

### 3.2. Endpoints

| Método | Caminho | Auth | Descrição |
| --- | --- | --- | --- |
| `POST` | `/api/v1/auth/register` | Não | Cria usuário pendente e emite token `verification` |
| `POST` | `/api/v1/auth/login` | Não | Emite token `session` ou `verification`, conforme o estado da conta |
| `POST` | `/api/v1/auth/logout` | Bearer | Revoga o token atual |
| `POST` | `/api/v1/auth/logout-all` | `session` | Confirma a senha atual e revoga todos os tokens |
| `POST` | `/api/v1/auth/password/change` | `session` | Altera a senha com confirmação da senha atual |
| `POST` | `/api/v1/auth/password/reset-request` | Não | Solicita recuperação sem revelar se o e-mail existe |
| `POST` | `/api/v1/auth/password/reset` | Não | Redefine a senha com token de uso único |
| `POST` | `/api/v1/auth/email/verification-notification` | `verification` | Reenvia o e-mail de verificação |
| `POST` | `/api/v1/auth/email/verify` | `verification` | Consome o token de uso único e verifica o e-mail |
| `GET` | `/api/v1/me` | `session` ou `verification` | Retorna o usuário atual e seu status |
| `PATCH` | `/api/v1/me` | `session` | Altera somente `name` |

O registro aceita exatamente `name`, `email`, `password`, `password_confirmation` e `accept_terms`; campos adicionais são inválidos e `accept_terms` precisa ser `true`. Falha de autorização do convite e e-mail já cadastrado retornam a mesma resposta `403 REGISTRATION_NOT_ALLOWED`, sem permitir enumeração.

Credenciais incorretas retornam `401 INVALID_CREDENTIALS`. Credenciais corretas de usuário pendente retornam token `verification`. Contas suspensas e contas em exclusão retornam, respectivamente, `403 ACCOUNT_SUSPENDED` e `403 ACCOUNT_PENDING_DELETION`.

Senhas possuem de 12 a 128 caracteres e devem conter pelo menos uma letra ASCII minúscula, uma maiúscula, um dígito e um símbolo ASCII. Tokens de verificação de e-mail são de uso único e válidos por 60 minutos; ao verificar, a API revoga o token restrito e exige novo login. Tokens de recuperação de senha são de uso único e válidos por 30 minutos. Solicitação de recuperação sempre retorna `202`, exista ou não uma conta elegível.

O usuário retornado contém `id`, `name`, `email`, `status`, `email_verified_at`, `terms_version`, `terms_accepted_at`, `created_at` e `updated_at`. Os estados públicos são `pending_verification`, `active`, `suspended` e `deletion_pending`.

## 4. Links

### 4.1. Endpoints

| Método | Caminho | Descrição |
| --- | --- | --- |
| `GET` | `/api/v1/links` | Lista links do proprietário |
| `POST` | `/api/v1/links` | Cria um link |
| `GET` | `/api/v1/links/{link}` | Retorna detalhes e `ETag` |
| `PATCH` | `/api/v1/links/{link}` | Atualiza campos mutáveis com `If-Match` |
| `GET` | `/api/v1/links/{link}/history` | Lista o histórico de destinos |

Não existe `DELETE`. O `slug` é imutável e aliases permanecem reservados global e permanentemente, inclusive após remoção futura do recurso.

### 4.2. Representações

`LinkSummary`, usado na coleção, contém:

- `id`, `slug`, `short_url`, `title` e `slug_source` (`automatic` ou `custom`);
- `is_enabled` e o estado efetivo `status` (`active`, `inactive`, `expired` ou `blocked`);
- `expires_at`, `created_at` e `updated_at`.

O resumo não contém destino, `ETag`, analytics ou versão interna. `LinkDetail` acrescenta somente `destination_url`; o `ETag` opaco é enviado em header.

`blocked` é um estado operacional que prevalece sobre os demais. Um link bloqueado continua editável pelo proprietário, mas nenhum campo público permite remover o bloqueio. Depois dele, `expired` prevalece sobre `inactive`; `active` exige `is_enabled=true`, ausência de bloqueio e expiração futura ou nula.

### 4.3. Listagem e histórico

`GET /links` usa cursor assinado, ordem fixa da criação mais recente para a mais antiga e desempate estável por ID:

- `cursor`: valor opaco da resposta anterior; valor inválido retorna `422 INVALID_CURSOR`.
- `per_page`: padrão 20, mínimo 1 e máximo 100.
- `search`: de 2 a 160 caracteres; procura substring no título e prefixo no slug, sem diferenciar caixa, mas diferenciando acentos.
- `status`: `active`, `inactive`, `expired`, `blocked` ou `all`; padrão `all`.

A paginação retorna somente `meta.next_cursor` e `meta.per_page`.

O histórico também usa cursor, com registros do mais recente para o mais antigo. Cada item contém `destination_url`, `valid_from` e `valid_to`; `valid_to=null` identifica o destino vigente. Não há número de versão público.

### 4.4. Criação e idempotência

`POST /links` aceita exatamente:

```json
{
  "destination_url": "https://example.com/articles/architecture?source=newsletter#intro",
  "custom_alias": "Architecture",
  "title": "Architecture article",
  "expires_at": "2026-12-31T23:59:59Z"
}
```

`destination_url` é obrigatório. `custom_alias`, `title` e `expires_at` são opcionais; os dois últimos podem ser nulos. A expiração não nula precisa estar no futuro. Alias em maiúsculas é aceito e normalizado para minúsculas antes de validação, reserva e comparação.

`Idempotency-Key` é opcional, possui de 16 a 128 caracteres, segue `[A-Za-z0-9._:-]+` e fica retido por 24 horas no escopo da conta. Repetir a chave com o mesmo comando normalizado reproduz exatamente o status `201`, corpo e headers originais. Repeti-la com outro comando retorna `409 IDEMPOTENCY_KEY_REUSED`. Alias indisponível retorna `409 ALIAS_UNAVAILABLE`.

A criação retorna `Location`, um `ETag` forte e opaco e `LinkDetail`.

### 4.5. Atualização e concorrência

`PATCH /links/{link}` aceita um ou mais entre `destination_url`, `title`, `is_enabled` e `expires_at`. `If-Match` é obrigatório: ausência retorna `428 IF_MATCH_REQUIRED` e valor obsoleto retorna `412 ETAG_MISMATCH`.

Quando o destino muda, o histórico anterior é encerrado e uma nova entrada é criada na mesma transação. Uma atualização semanticamente idêntica é um no-op: não altera `updated_at`, não cria histórico e retorna o mesmo `ETag`. O hash forte do `ETag` é opaco e considera também o estado efetivo, para que bloqueio ou mudança temporal relevante invalide pré-condições sem expor uma versão interna.

### 4.6. Destinos e aliases

URLs de destino:

- possuem no máximo 2.048 caracteres e usam somente HTTP ou HTTPS;
- exigem hostname público e rejeitam IP literal, host local, nomes especiais e o próprio host curto;
- rejeitam `userinfo` e aceitam apenas portas sintaticamente válidas;
- preservam query string e fragmento após normalização segura.

Essas regras dependem de parsing e classificação do host; a OpenAPI documenta as restrições que JSON Schema consegue representar e descreve as demais.

Aliases personalizados têm de 3 a 48 caracteres, usam letras ASCII, números e hífen, começam e terminam com letra ou número e não possuem hífens consecutivos. A normalização para minúsculas ocorre antes da comparação com aliases reservados.

## 5. Redirect público

O host curto não usa `/api/v1`:

| Método | Caminho | Comportamento |
| --- | --- | --- |
| `GET` | `/{slug}` | Retorna `302`, `Location` e `Cache-Control: no-store`; publica analytics de forma assíncrona |
| `HEAD` | `/{slug}` | Retorna os mesmos headers do `GET`, sem corpo e sem gerar analytics |
| `GET` | `/` | Retorna `302` para a landing page da aplicação, sem analytics |
| `GET` | `/robots.txt` | Retorna conteúdo estático com `User-agent: *` e `Disallow: /` |

O parâmetro aceita de 3 a 48 letras ASCII, números ou hífen. O servidor normaliza letras maiúsculas para minúsculas. Uma única barra final opcional é aceita com a mesma semântica; a OpenAPI descreve essa equivalência porque não consegue declarar dois templates que diferem apenas pela barra final de forma portável. Percent-encoding no slug e segmentos extras são rejeitados. A query string recebida é ignorada e nunca é anexada ao destino.

Respostas de erro são HTML mínimo, sem destino ou dados do proprietário, mas incluem código, mensagem e request ID estáveis no conteúdo e `X-Request-ID` no header:

- slug desconhecido ou nunca reservado: `404 SLUG_NOT_FOUND`;
- link inativo, expirado, bloqueado ou reserva órfã: `410 LINK_UNAVAILABLE`;
- limite excedido: `429 RATE_LIMIT_EXCEEDED`;
- falha operacional, falha de decriptação ou cache miss sem PostgreSQL disponível: `503 REDIRECT_UNAVAILABLE`.

Outros métodos retornam `405 METHOD_NOT_ALLOWED`. HSTS permanece ausente permanentemente. Todas as páginas do host da aplicação devem usar `noindex`; isso é uma responsabilidade do frontend e não uma rota da API.

## 6. Analytics privados

Somente o proprietário pode consultar:

| Método | Caminho | Descrição |
| --- | --- | --- |
| `GET` | `/api/v1/links/{link}/analytics/summary` | Totais do período |
| `GET` | `/api/v1/links/{link}/analytics/timeseries` | Série temporal densa |
| `GET` | `/api/v1/links/{link}/analytics/referrers` | Principais hosts referenciadores |
| `GET` | `/api/v1/links/{link}/analytics/devices` | Totais por categoria de dispositivo |

Parâmetros comuns:

- `from` e `to`: datas UTC inclusivas. O padrão é os últimos 30 dias, incluindo hoje.
- `traffic_type`: `human`, `bot`, `preview`, `unknown` ou `all`; padrão `human`.
- O período não pode incluir data futura, `from` não pode superar `to` e o máximo é 366 dias.

`estimated_unique_clicks` é uma estimativa diária disponível somente para `traffic_type=human`. Em resumo e em cada bucket da série, o valor é inteiro para `human` e `null` para `bot`, `preview`, `unknown` ou `all`. Nenhum breakdown contém métricas de únicos.

A série aceita `granularity=hour` para até 31 dias ou `granularity=day` para até 366 dias. A resposta é densa e inclui buckets zerados em todo o intervalo inclusivo.

Referenciadores retornam somente `total_clicks`. `limit` tem padrão 10 e máximo 100; `direct` compete normalmente no ranking e `other` fecha o total fora dos itens retornados. Nunca são retornados path ou query do referenciador.

Dispositivos retornam somente `total_clicks`, sem `limit`, e incluem sempre as cinco categorias `desktop`, `mobile`, `tablet`, `other` e `unknown`, mesmo quando zeradas. Browser, sistema operacional, exportações e relatórios públicos não fazem parte do MVP.

## 7. Status HTTP e códigos estáveis

| Status | Uso típico |
| --- | --- |
| `200` | Consulta ou atualização concluída |
| `201` | Link ou usuário criado |
| `202` | Solicitação assíncrona aceita sem revelar existência da conta |
| `204` | Operação concluída sem corpo |
| `302` | Redirect público |
| `400` | JSON ou requisição malformada |
| `401` | Credenciais inválidas ou Bearer ausente, inválido ou expirado |
| `403` | Estado da conta ou tipo de token impede a operação |
| `404` | Recurso ausente ou de outro proprietário |
| `405` | Método não suportado |
| `409` | Alias indisponível ou chave idempotente reutilizada com outro comando |
| `410` | Link público indisponível |
| `412` | `If-Match` obsoleto |
| `413` | Corpo acima do limite |
| `422` | Validação, período ou cursor inválido |
| `428` | `If-Match` ausente |
| `429` | Rate limit excedido; inclui `Retry-After` |
| `500` | Erro inesperado sem detalhes sensíveis |
| `503` | Serviço ou dependência temporariamente indisponível |
| `504` | Timeout de gateway ou dependência |

A OpenAPI mantém os códigos específicos por resposta. Códigos gerais incluem `MALFORMED_REQUEST`, `UNAUTHENTICATED`, `TOKEN_RESTRICTED`, `RESOURCE_NOT_FOUND`, `METHOD_NOT_ALLOWED`, `PAYLOAD_TOO_LARGE`, `VALIDATION_FAILED`, `RATE_LIMIT_EXCEEDED`, `INTERNAL_ERROR`, `SERVICE_UNAVAILABLE` e `GATEWAY_TIMEOUT`.

## 8. Rate limiting inicial

| Grupo | Limite |
| --- | --- |
| Registro | 5 por hora por IP |
| Login | 5 por minuto por combinação de e-mail normalizado e IP |
| Login adicional | 30 por minuto por IP |
| Solicitação de recuperação | 3 por hora por combinação de e-mail normalizado e IP |
| Reset de senha | 5 por hora por IP e token do fluxo |
| Reenvio de verificação | 3 por hora por conta |
| Verificação de e-mail | 5 por hora por conta |
| Criação de links | 60 por minuto por conta |
| Outras escritas privadas | 120 por minuto por conta |
| Leituras privadas | 300 por minuto por token |
| Analytics | 60 por minuto por conta |
| Redirect | 120 por minuto por combinação de IP e slug na aplicação, além da proteção do Nginx |

Os valores são configuração operacional inicial. Toda resposta `429` inclui `Retry-After` e o erro estável correspondente.

## 9. Versionamento e depreciação

- Mudanças compatíveis podem entrar em `/api/v1`; alterações incompatíveis exigem nova versão principal.
- Campos novos são opcionais até uma nova versão; clientes devem tolerar campos desconhecidos em respostas.
- Uma versão ou endpoint público permanece disponível por pelo menos 90 dias após o anúncio de depreciação.
- Durante esse período, respostas afetadas informam `Deprecation` e `Sunset` com a data planejada.
- A especificação OpenAPI é validada no CI e comparada para detectar breaking changes.
