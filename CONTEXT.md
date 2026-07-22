# Fake Link

Este glossário define a linguagem canônica do domínio de encurtamento e mensuração de links do Fake Link.

## Identidade

**User**:
Pessoa convidada que possui conta individual e pode ser proprietária de `Short Links`.
_Evitar_: Account, Member, Customer

**User Status**:
Condição atual de um `User`: `pending_verification`, `active`, `suspended` ou `deletion_pending`.
_Evitar_: Account status

**Restricted Session**:
Sessão temporária de um `User` que ainda não concluiu a verificação de e-mail.
_Evitar_: Guest session

## Links

**Short Link**:
Recurso pertencente a um único `User` que associa um slug permanente a um destino atual e ao seu histórico.
_Evitar_: Link, Shortened URL

**Slug**:
Identificador global e permanente que compõe o caminho público de um `Short Link`.
_Evitar_: Code, Key

**Custom Alias**:
Slug escolhido pelo `User`, em contraste com um slug gerado automaticamente.
_Evitar_: Custom slug, Vanity URL

**Slug Source**:
Origem do slug de um `Short Link`, classificada como `automatic` ou `custom`.
_Evitar_: Alias type

**Slug Reservation**:
Reserva permanente de um slug, inclusive quando ele deixa de estar associado a um `Short Link`.
_Evitar_: Deleted slug

**Destination**:
URL atual para a qual um `Short Link` válido direciona o visitante.
_Evitar_: Target, Long URL

**Destination Version**:
Registro imutável de um destino que esteve ou está associado a um `Short Link`.
_Evitar_: Destination change

**Effective Status**:
Condição derivada de um `Short Link`: `active`, `inactive`, `expired` ou `blocked`.
_Evitar_: Link state

## Analytics

**Click**:
Evento produzido por um `GET` público que resolve um `Short Link` válido e responde `302`.
_Evitar_: Hit, Visit

**Estimated Unique Click**:
Primeiro `Click` humano estimado para o mesmo visitante em um `Short Link` durante um dia UTC.
_Evitar_: Unique visitor, Unique user

**Traffic Type**:
Classificação de um `Click` como `human`, `bot`, `preview` ou `unknown`.
_Evitar_: Visitor type

**Referrer**:
Host público que representa a origem de um `Click`; ausência ou origem não pública é `direct`.
_Evitar_: Referrer URL, Referral
