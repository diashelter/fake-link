# Guia de Design de Código — PHP Moderno, Laravel e Arquitetura Hexagonal

> Este documento é a fonte de verdade para geração, revisão e manutenção de código PHP/Laravel neste projeto.
>
> Toda implementação criada por pessoas ou por IA DEVE seguir estas regras, salvo quando existir uma decisão arquitetural documentada que justifique uma exceção.

---

## 1. Objetivo

Manter uma base de código:

- modular;
- simples de compreender;
- previsível;
- fortemente tipada;
- orientada a objetos;
- fácil de testar;
- independente de detalhes de infraestrutura;
- preparada para evolução sem abstrações prematuras;
- com Controllers mínimos;
- com validação, normalização e criação de DTOs centralizadas em FormRequests;
- com respostas e erros em um padrão único de API.

---

## 2. Princípios fundamentais

Toda decisão de código DEVE considerar, em conjunto:

- **Object Calisthenics**, sempre que sua aplicação melhorar o design;
- **DRY** — evitar duplicação real de conhecimento;
- **KISS** — escolher a solução mais simples que resolva corretamente o problema;
- **YAGNI** — não implementar abstrações ou funcionalidades baseadas apenas em necessidades futuras hipotéticas;
- **SOLID** — especialmente responsabilidade única, inversão de dependência e segregação de interfaces;
- **Arquitetura Hexagonal** — separar regras de negócio, aplicação e infraestrutura por meio de portas e adaptadores;
- **Alta coesão e baixo acoplamento**;
- **Composição em vez de herança**, salvo quando a herança representar corretamente uma relação do domínio ou uma extensão exigida pelo framework.

Esses princípios não devem ser aplicados de forma mecânica ou dogmática. Quando houver conflito:

1. preservar a regra de dependência da arquitetura;
2. preservar a correção do domínio;
3. priorizar legibilidade e simplicidade;
4. evitar duplicação de conhecimento;
5. documentar exceções relevantes.

---

## 3. Vocabulário normativo

As palavras abaixo indicam o nível da regra:

- **DEVE**: obrigatório;
- **NÃO DEVE**: proibido, exceto mediante justificativa arquitetural documentada;
- **PODE**: permitido conforme necessidade concreta;
- **PREFERIR**: padrão esperado, mas admite exceção simples e justificável.

---

## 4. Premissas técnicas

- PHP moderno com `declare(strict_types=1);`;
- Laravel moderno;
- PSR-12;
- namespaces e autoload PSR-4;
- propriedades tipadas;
- tipos de retorno obrigatórios;
- named arguments quando melhorarem a leitura;
- DTOs preferencialmente `final readonly`;
- Enums nativos para conjuntos fechados de valores;
- injeção de dependências pelo container do Laravel;
- Controllers sem regra de negócio;
- FormRequests sem persistência e sem regra de negócio;
- UseCases para coordenar operações da aplicação;
- Contracts para definir portas de saída e fronteiras de integração;
- API Resources para transformação de saída HTTP;
- exceções de domínio e aplicação independentes de HTTP;
- tratamento de exceções e respostas padronizado na borda da aplicação.

Todo arquivo PHP criado pela aplicação DEVE começar com:

```php
<?php

declare(strict_types=1);
```

Toda classe que não foi projetada para herança DEVE ser `final`.

---

## 5. Arquitetura obrigatória

### 5.1 Modelo arquitetural

O projeto DEVE utilizar arquitetura modular baseada nos princípios de Arquitetura Hexagonal.

Cada módulo representa uma capacidade de negócio e DEVE ser desenvolvido como uma unidade autônoma.

Exemplos de módulos:

- `Identity`;
- `Customers`;
- `Billing`;
- `Support`;
- `Notifications`.

Não organizar o núcleo do sistema apenas por tipos técnicos globais, como:

```text
app/Controllers
app/Services
app/Repositories
app/DTOs
```

Essa organização espalha uma mesma funcionalidade por todo o projeto e reduz a coesão.

### 5.2 Regra de dependência

As dependências DEVEM apontar para dentro:

```text
Infrastructure → UseCases / Contracts → Domain
```

Em termos práticos:

- o **Domain** NÃO DEVE conhecer Laravel, HTTP, banco de dados, filas ou serviços externos;
- os **UseCases** NÃO DEVEM conhecer Controllers, FormRequests, Eloquent Models ou implementações externas;
- os **Contracts** DEVEM declarar apenas portas de saída e fronteiras de integração utilizadas pelos UseCases;
- a **Infrastructure** DEVE implementar Contracts e adaptar tecnologias externas ao módulo;
- o **ServiceProvider** do módulo DEVE conectar Contracts de saída às implementações de infraestrutura; UseCases concretos DEVEM ser resolvidos diretamente pelo container.

### 5.3 Dependências proibidas

```text
Domain          ─X→ Infrastructure
Domain          ─X→ Laravel
UseCases        ─X→ Controllers
UseCases        ─X→ FormRequests
UseCases        ─X→ Eloquent Models
Contracts       ─X→ Implementações de Infrastructure
```

### 5.4 Dependências permitidas

```text
Infrastructure  → Contracts
Infrastructure  → UseCases
Infrastructure  → Domain
UseCases        → Contracts
UseCases        → Domain
Domain          → PHP e objetos do próprio domínio
```

### 5.5 Comunicação entre módulos

Um módulo NÃO DEVE acessar diretamente Models, Repositories ou classes internas de outro módulo.

A comunicação entre módulos DEVE ocorrer por uma destas formas:

1. contrato público do módulo;
2. serviço de aplicação público;
3. evento de domínio ou de integração;
4. mensagem assíncrona;
5. DTO público e estável.

Evitar dependências circulares entre módulos.

---

## 6. Estrutura de pastas

### 6.1 Estrutura raiz

Os módulos DEVEM estar na pasta `modules`, na raiz do projeto:

```text
project-root/
├── app/
├── bootstrap/
├── config/
├── database/
├── modules/
├── public/
├── routes/
├── tests/
└── composer.json
```

A pasta `app` DEVE conter apenas componentes realmente globais do projeto, como o bootstrap HTTP geral, tratamento global de exceções e elementos compartilhados que não pertencem a um módulo específico.

### 6.2 Estrutura padrão de um módulo

Exemplo para o módulo `Identity`:

```text
modules/
└── Identity/
    ├── Contracts/
    │   ├── Repositories/
    │   │   └── UserRepository.php
    │   └── Services/
    │       └── PasswordHasher.php
    ├── Domain/
    │   ├── Collections/
    │   ├── Entities/
    │   │   └── User.php
    │   ├── Enums/
    │   ├── Events/
    │   ├── Services/
    │   └── ValueObjects/
    │       ├── Email.php
    │       └── UserId.php
    ├── DTOs/
    │   ├── Input/
    │   │   └── RegisterUserDto.php
    │   └── Output/
    │       └── RegisteredUserDto.php
    ├── Exceptions/
    │   ├── UserException.php
    │   └── ExternalIdentityProviderUnavailable.php
    ├── UseCases/
    │   └── RegisterUser.php
    ├── Infrastructure/
    │   ├── Auth/
    │   ├── Hashing/
    │   │   └── LaravelPasswordHasher.php
    │   ├── Http/
    │   │   ├── Controllers/
    │   │   │   └── RegisterUserController.php
    │   │   ├── Requests/
    │   │   │   └── RegisterUserRequest.php
    │   │   ├── Resources/
    │   │   │   └── UserResource.php
    │   │   └── Routes/
    │   │       └── api.php
    │   ├── Persistence/
    │   │   └── Eloquent/
    │   │       ├── Models/
    │   │       │   └── UserModel.php
    │   │       ├── Mappers/
    │   │       │   └── UserMapper.php
    │   │       └── Repositories/
    │   │           └── EloquentUserRepository.php
    │   └── Providers/
    ├── ServiceProviders/
    │   └── IdentityServiceProvider.php
    ├── Tests/
    │   ├── Unit/
    │   ├── Integration/
    │   └── Feature/
    └── README.md
```

Pastas vazias NÃO DEVEM ser criadas apenas para antecipar necessidades futuras.

A estrutura deve crescer conforme responsabilidades reais apareçam.

### 6.3 Autoload dos módulos

O namespace raiz dos módulos DEVE ser `Modules`.

Exemplo de configuração em `composer.json`:

```json
{
  "autoload": {
    "psr-4": {
      "App\\": "app/",
      "Modules\\": "modules/"
    }
  }
}
```

Depois de alterar o autoload, executar:

```bash
composer dump-autoload
```

### 6.4 Nomenclatura do módulo

- nome do módulo em `PascalCase`;
- nome baseado em capacidade de negócio, não em tecnologia;
- namespace igual ao caminho físico;
- evitar nomes genéricos como `Common`, `Core`, `General` e `Utils` sem uma responsabilidade claramente delimitada.

---

## 7. ServiceProvider do módulo

### 7.1 Responsabilidade central

Todo módulo DEVE possuir um `ServiceProvider` principal em:

```text
modules/{Module}/ServiceProviders/{Module}ServiceProvider.php
```

O ServiceProvider é o ponto central de composição do módulo e DEVE ser responsável por:

- registrar bindings entre Contracts de saída e implementações;
- registrar rotas do módulo;
- registrar migrations, traduções, views ou configurações quando necessário;
- registrar observers, listeners e comandos do módulo;
- expor somente integrações públicas intencionais;
- delegar registros extensos para providers internos quando o módulo crescer.

O ServiceProvider NÃO DEVE:

- conter regras de negócio;
- executar casos de uso;
- consultar banco de dados durante o bootstrap;
- construir manualmente grandes grafos de objetos quando o container puder resolvê-los;
- servir como classe utilitária global.

### 7.2 Exemplo

```php
<?php

declare(strict_types=1);

namespace Modules\Identity\ServiceProviders;

use Illuminate\Support\ServiceProvider;
use Modules\Identity\Contracts\Repositories\UserRepository;
use Modules\Identity\Contracts\Services\PasswordHasher;
use Modules\Identity\Infrastructure\Hashing\LaravelPasswordHasher;
use Modules\Identity\Infrastructure\Persistence\Eloquent\Repositories\EloquentUserRepository;

final class IdentityServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public $bindings = [
        UserRepository::class => EloquentUserRepository::class,
        PasswordHasher::class => LaravelPasswordHasher::class,
    ];

    public function register(): void
    {
        // Registrar configurações ou providers internos quando necessário.
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(
            __DIR__ . '/../Infrastructure/Http/Routes/api.php',
        );
    }
}
```

UseCases concretos não precisam de binding quando suas dependências podem ser resolvidas pelo container. O ServiceProvider NÃO DEVE criar uma interface apenas para registrar um caso de uso.

### 7.3 Registro no Laravel

O ServiceProvider principal de cada módulo DEVE ser registrado na lista de providers da aplicação. Em versões modernas do Laravel, utilizar `bootstrap/providers.php`:

```php
<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Modules\Identity\ServiceProviders\IdentityServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
];
```

Quando o projeto utilizar outro mecanismo de descoberta ou registro, manter o mesmo princípio: apenas o provider principal do módulo deve ser conhecido pelo bootstrap global.

### 7.4 Crescimento do módulo

Quando o ServiceProvider principal ficar grande, ele DEVE delegar responsabilidades para providers específicos:

```text
ServiceProviders/
├── IdentityServiceProvider.php
├── IdentityEventServiceProvider.php
└── IdentityRouteServiceProvider.php
```

O `IdentityServiceProvider` continua sendo a entrada central do módulo.

---

## 8. Object Calisthenics adaptado ao projeto

Object Calisthenics DEVE ser utilizado como conjunto de restrições para melhorar o design orientado a objetos, sem sacrificar a clareza ou criar abstrações artificiais.

### 8.1 Um nível de indentação por método

PREFERIR apenas um nível de indentação por método.

Utilizar:

- guard clauses;
- extração de métodos;
- polimorfismo;
- objetos especializados;
- early return.

Evitar:

```php
if ($condition) {
    foreach ($items as $item) {
        if ($item->isValid()) {
            // ...
        }
    }
}
```

### 8.2 Evitar `else`

PREFERIR guard clauses e retornos antecipados.

```php
private function ensureEmailIsAvailable(Email $email): void
{
    if (! $this->users->existsByEmail($email)) {
        return;
    }

    throw UserException::emailAlreadyInUse($email);
}
```

O uso de `else` é permitido quando sua remoção piorar a legibilidade.

### 8.3 Encapsular primitivas relevantes

Primitivas que representam conceitos de negócio DEVEM ser avaliadas como Value Objects.

Exemplos:

- `Email`;
- `Cpf`;
- `Money`;
- `UserId`;
- `PhoneNumber`;
- `Percentage`.

Não criar Value Objects para cada string ou inteiro sem comportamento, validação ou significado próprio. Isso violaria KISS e YAGNI.

### 8.4 Coleções de primeira classe

Coleções que possuem regras ou comportamentos de domínio DEVEM ser encapsuladas.

Exemplo:

```php
final readonly class Roles
{
    /**
     * @param list<Role> $items
     */
    public function __construct(private array $items)
    {
    }

    public function contains(Role $role): bool
    {
        foreach ($this->items as $item) {
            if ($item === $role) {
                return true;
            }
        }

        return false;
    }
}
```

Arrays simples em DTOs de transporte são permitidos quando não existir comportamento de domínio.

### 8.5 Um ponto por linha e Lei de Demeter

Evitar cadeias extensas de chamadas:

```php
$order->customer()->address()->city()->name();
```

PREFERIR expor uma intenção clara:

```php
$order->deliveryCityName();
```

Fluent interfaces do Laravel, Query Builder e estruturas de configuração são exceções aceitáveis na camada de Infrastructure.

### 8.6 Não abreviar nomes

Nomes DEVEM comunicar intenção.

Evitar:

```php
$usrRepo;
$pwdSvc;
$req;
$res;
```

PREFERIR:

```php
$userRepository;
$passwordHasher;
$request;
$response;
```

Abreviações amplamente reconhecidas pelo domínio, como `DTO`, `API`, `URL` e `ID`, são permitidas conforme o padrão de nomenclatura adotado.

### 8.7 Manter classes pequenas

Classes e métodos DEVEM ter uma responsabilidade clara.

O tamanho é um sinal, não uma métrica absoluta. Uma classe grande deve ser revisada quanto a:

- múltiplos motivos para mudança;
- baixa coesão;
- dependências excessivas;
- métodos privados sem relação entre si;
- nomes genéricos.

### 8.8 Poucas propriedades de instância

PREFERIR objetos coesos e com poucas dependências.

Uma classe com muitas propriedades DEVE ser revisada, mas não é obrigatório limitar toda classe a duas propriedades.

Exceções comuns:

- DTOs;
- Entities e Aggregates legítimos;
- Eloquent Models;
- Resources;
- ServiceProviders;
- objetos de configuração.

### 8.9 Comportamento em vez de getters e setters

Objetos de domínio DEVEM expor comportamento e intenção, não apenas estado mutável.

Evitar:

```php
$user->setStatus('active');
```

PREFERIR:

```php
$user->activate();
```

DTOs, Resources, mapeadores e integrações de serialização podem expor dados diretamente quando essa for sua responsabilidade.

---

## 9. DRY, KISS e YAGNI

### 9.1 DRY

DRY significa evitar duplicação de conhecimento, não eliminar qualquer repetição visual.

Antes de extrair uma abstração, verificar se os trechos:

- representam a mesma regra;
- mudam pelo mesmo motivo;
- possuem o mesmo significado no domínio.

Não compartilhar código entre módulos apenas porque ele se parece atualmente.

É preferível uma pequena duplicação acidental a uma abstração incorreta que acople módulos independentes.

### 9.2 KISS

A implementação DEVE usar o menor número de conceitos necessários para expressar corretamente o caso de uso.

Evitar:

- factories sem necessidade;
- interfaces para classes sem fronteira arquitetural;
- handlers encadeados para operações triviais;
- eventos usados apenas para esconder chamadas síncronas simples;
- abstrações genéricas de Repository com métodos como `all`, `find`, `save`, `update` para qualquer entidade.

### 9.3 YAGNI

Não criar:

- pastas vazias;
- Contracts sem consumidor;
- implementações alternativas inexistentes;
- eventos “para uso futuro”;
- campos de banco sem requisito atual;
- extensões configuráveis que não foram solicitadas;
- camadas extras apenas para parecer arquitetural.

Arquitetura Hexagonal não significa criar o maior número possível de interfaces. Contracts DEVEM existir nas fronteiras em que a inversão de dependência protege o núcleo da aplicação.

---

## 10. SOLID aplicado ao projeto

### 10.1 Single Responsibility Principle

Cada classe DEVE possuir um motivo principal para mudar.

Exemplos:

- FormRequest valida e transforma a entrada;
- Controller adapta HTTP para um UseCase;
- UseCase coordena uma intenção de negócio;
- Repository abstrai persistência necessária ao UseCase;
- Mapper converte representação de persistência em objeto de domínio;
- Resource transforma saída para HTTP.

### 10.2 Open/Closed Principle

PREFERIR extensão por composição, estratégias, Contracts e polimorfismo quando existirem variações reais.

Não criar mecanismos de extensão sem uma segunda variação concreta ou um requisito explícito.

### 10.3 Liskov Substitution Principle

Implementações de Contracts DEVEM respeitar:

- tipos;
- significado do retorno;
- exceções esperadas;
- invariantes;
- efeitos colaterais documentados.

### 10.4 Interface Segregation Principle

Contracts DEVEM ser pequenos e orientados à necessidade do consumidor.

Evitar interfaces genéricas e extensas.

### 10.5 Dependency Inversion Principle

UseCases DEVEM depender de Contracts para capacidades externas, nunca de implementações de banco, framework ou serviços externos. Eles NÃO DEVEM depender de uma interface criada apenas para representar o próprio UseCase.

Os bindings DEVEM ser configurados no ServiceProvider do módulo.

---

## 11. Fluxo padrão de uma requisição

```text
HTTP Request
    ↓
FormRequest — Infrastructure/Http
    ├── autoriza
    ├── normaliza entradas simples
    ├── valida
    ├── transforma em DTO
    └── padroniza erros de validação
    ↓
Controller — Infrastructure/Http
    ├── recebe o UseCase concreto
    ├── chama toDto()
    ├── executa o UseCase
    └── devolve resposta HTTP
    ↓
UseCase — Application
    ├── coordena a operação
    ├── aplica regras por meio do Domain
    ├── utiliza Output Ports definidos em Contracts
    └── retorna objeto de domínio ou DTO de saída
    ↓
Adapter — Infrastructure
    ├── persiste dados
    ├── acessa serviços externos
    └── converte representações técnicas
    ↓
API Resource — Infrastructure/Http
    └── transforma a saída
    ↓
ApiResponse
    └── aplica o envelope padrão da API
```

---

## 12. Controllers

### 12.1 Responsabilidade

Controllers DEVEM atuar somente como adaptadores HTTP.

Um Controller PODE:

- receber um FormRequest;
- receber parâmetros de rota;
- receber a classe concreta do UseCase por injeção de dependência;
- chamar `toDto()` no FormRequest;
- executar um caso de uso;
- devolver `JsonResponse`, `Response` ou Resource;
- selecionar o status HTTP adequado.

Um Controller NÃO DEVE:

- conter validação manual;
- acessar `$request->all()`;
- acessar `$request->validated()` para montar arrays extensos;
- executar regra de negócio;
- iniciar transações;
- executar consultas;
- acessar Eloquent Models;
- coordenar múltiplos Repositories;
- formatar manualmente erros;
- capturar exceções apenas para convertê-las em HTTP;
- possuir métodos privados com lógica de domínio.

### 12.2 Tamanho esperado

Como regra prática, uma action de Controller deve possuir entre uma e cinco linhas de lógica executável.

### 12.3 Exemplo

```php
<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Http\Controllers;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Infrastructure\Http\Requests\RegisterUserRequest;
use Modules\Identity\Infrastructure\Http\Resources\UserResource;
use Modules\Identity\UseCases\RegisterUser;

final class RegisterUserController
{
    public function __invoke(
        RegisterUserRequest $request,
        RegisterUser $registerUser,
    ): JsonResponse {
        $user = $registerUser->handle($request->toDto());

        return ApiResponse::created(
            data: new UserResource($user),
            message: 'Usuário criado com sucesso.',
        );
    }
}
```

Controllers invocáveis DEVEM ser preferidos quando o Controller representar uma única operação.

Controllers REST com múltiplas actions são permitidos quando permanecerem pequenos e coesos.

---

## 13. FormRequests

### 13.1 Responsabilidade

Todo endpoint que recebe dados de entrada DEVE utilizar um FormRequest dedicado.

O FormRequest DEVE:

1. autorizar a operação;
2. preparar e normalizar valores de entrada simples;
3. validar os dados;
4. fornecer mensagens e atributos legíveis quando necessário;
5. mapear os dados validados para um DTO por meio de `toDto()`;
6. transformar falhas de validação para o padrão único da API.

O FormRequest NÃO DEVE:

- persistir dados;
- executar transações;
- disparar eventos de negócio;
- chamar UseCases;
- chamar Repositories;
- conter regras de negócio complexas;
- retornar um array para o Controller quando existir DTO de entrada;
- criar objetos de infraestrutura dentro do DTO.

### 13.2 Classe base obrigatória

Todos os FormRequests da API DEVEM estender uma classe base única e global.

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class ApiFormRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            ApiResponse::validationError(
                errors: $this->formatValidationErrors($validator),
            ),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function formatValidationErrors(Validator $validator): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = $validator->errors()->toArray();

        return $errors;
    }
}
```

A classe base NÃO DEVE conhecer DTOs de módulos específicos.

### 13.3 Exemplo completo

```php
<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Http\Requests;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;
use Modules\Identity\DTOs\Input\RegisterUserDto;

final class RegisterUserRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->normalizeName($this->input('name')),
            'email' => $this->normalizeEmail($this->input('email')),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ];
    }

    public function toDto(): RegisterUserDto
    {
        return new RegisterUserDto(
            name: $this->string('name')->toString(),
            email: $this->string('email')->toString(),
            plainTextPassword: $this->string('password')->toString(),
        );
    }

    private function normalizeName(mixed $name): mixed
    {
        return is_string($name) ? trim($name) : $name;
    }

    private function normalizeEmail(mixed $email): mixed
    {
        return is_string($email) ? mb_strtolower(trim($email)) : $email;
    }
}
```

### 13.4 Regras para `prepareForValidation()`

PODE realizar apenas normalizações determinísticas e sem efeitos colaterais, como:

- remover espaços externos;
- converter string vazia em `null`;
- normalizar caixa de e-mail;
- remover máscara de documento;
- converter representações booleanas conhecidas;
- padronizar formato simples de telefone.

NÃO DEVE:

- consultar banco;
- chamar API externa;
- aplicar regra de negócio;
- decidir permissões;
- gerar identificadores de domínio;
- persistir dados.

### 13.5 Regras para `toDto()`

O método `toDto()` DEVE:

- possuir tipo de retorno concreto;
- usar apenas dados previamente validados;
- fazer o mapeamento explícito por named arguments;
- converter tipos de transporte quando necessário;
- não consultar banco;
- não executar regra de negócio;
- não persistir dados;
- não retornar array.

### 13.6 Validação de unicidade

Validação de unicidade no FormRequest protege a experiência da API, mas NÃO substitui:

- índice único no banco de dados;
- regra do UseCase;
- tratamento de condição de corrida.

A regra definitiva de consistência pertence ao núcleo da aplicação e à persistência.

---

## 14. DTOs

### 14.1 Objetivo

DTOs transportam dados entre a borda HTTP e os UseCases sem acoplar a aplicação ao Laravel.

DTOs DEVEM:

- ser imutáveis;
- possuir tipos explícitos;
- ter nomes relacionados ao caso de uso;
- evitar dependência de Request, Model, Resource ou objetos HTTP;
- conter apenas dados de transporte e conversões simples.

### 14.2 Exemplo de entrada

```php
<?php

declare(strict_types=1);

namespace Modules\Identity\DTOs\Input;

final readonly class RegisterUserDto
{
    public function __construct(
        public string $name,
        public string $email,
        public string $plainTextPassword,
    ) {
    }
}
```

### 14.3 DTOs e Value Objects

O DTO representa dados da fronteira da aplicação.

O Value Object representa um conceito válido do domínio.

O UseCase PODE converter o DTO em Value Objects:

```php
$email = Email::fromString($input->email);
```

Não obrigar o FormRequest a construir todo o agregado de domínio. A entrada HTTP não deve decidir regras de criação do domínio.

### 14.4 Datas e Enums

Datas DEVEM ser convertidas para objetos imutáveis antes de entrarem no domínio ou na lógica da aplicação.

Enums de domínio DEVEM ser utilizados quando o valor representar um conjunto fechado de estados.

Falhas de conversão que representam entrada inválida DEVEM ser capturadas pela validação do FormRequest sempre que possível.

---

## 15. Contracts: portas de saída e integrações

### 15.1 Objetivo

Contracts definem como os UseCases acessam capacidades externas ao núcleo, como persistência, hashing, mensageria, relógio, transações e fornecedores externos.

Contracts DEVEM existir apenas quando representarem uma fronteira arquitetural real.

UseCases NÃO PRECISAM e NÃO DEVEM implementar Contracts criados apenas para espelhar sua própria assinatura. Não criar `Contracts/UseCases` por padrão.

Controllers e outros adaptadores de entrada DEVEM depender diretamente da classe concreta do UseCase. Essa classe permanece desacoplada de infraestrutura porque suas dependências externas são expressas por Contracts.

Criar um contrato para invocação de UseCase somente quando houver uma necessidade concreta e documentada de polimorfismo externo, múltiplas implementações reais ou publicação de uma API PHP estável entre módulos. Essa é uma exceção, não o padrão.

### 15.2 Output Ports

Output Ports descrevem dependências necessárias ao UseCase.

```php
<?php

declare(strict_types=1);

namespace Modules\Identity\Contracts\Repositories;

use Modules\Identity\Domain\Entities\User;
use Modules\Identity\Domain\ValueObjects\Email;
use Modules\Identity\Domain\ValueObjects\UserId;

interface UserRepository
{
    public function nextIdentity(): UserId;

    public function existsByEmail(Email $email): bool;

    public function save(User $user): void;
}
```

O contrato deve refletir as necessidades do domínio e do caso de uso, não a API do ORM.

Evitar contratos genéricos como:

```php
interface Repository
{
    public function all(): array;
    public function find(int $id): mixed;
    public function create(array $data): mixed;
    public function update(int $id, array $data): mixed;
    public function delete(int $id): bool;
}
```

### 15.3 Contracts de serviços externos

Integrações externas DEVEM ser representadas por intenção:

```php
interface PasswordHasher
{
    public function hash(string $plainTextPassword): string;
}
```

O Contract NÃO DEVE expor classes específicas do fornecedor.

---

## 16. Domain

### 16.1 Independência

O Domain DEVE utilizar apenas PHP e classes do próprio domínio.

Não utilizar no Domain:

- `Illuminate\*`;
- Eloquent;
- Request ou Response;
- Jobs do Laravel;
- facades;
- helpers de infraestrutura;
- Carbon específico do framework quando uma interface padrão ou objeto próprio for suficiente.

### 16.2 Entities

Entities possuem identidade e comportamento.

```php
<?php

declare(strict_types=1);

namespace Modules\Identity\Domain\Entities;

use Modules\Identity\Domain\ValueObjects\Email;
use Modules\Identity\Domain\ValueObjects\UserId;

final class User
{
    private function __construct(
        private readonly UserId $id,
        private string $name,
        private Email $email,
        private readonly string $passwordHash,
        private bool $active,
    ) {
    }

    public static function register(
        UserId $id,
        string $name,
        Email $email,
        string $passwordHash,
    ): self {
        return new self(
            id: $id,
            name: $name,
            email: $email,
            passwordHash: $passwordHash,
            active: true,
        );
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function id(): UserId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): Email
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
```

Getters de leitura podem existir quando necessários para persistência ou apresentação. O domínio deve continuar orientado a comportamento e não a mutação indiscriminada.

### 16.3 Value Objects

```php
<?php

declare(strict_types=1);

namespace Modules\Identity\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class Email
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = mb_strtolower(trim($value));

        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('E-mail inválido.');
        }

        return new self($normalized);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
```

### 16.4 Serviços de domínio

Criar Domain Service apenas quando uma regra:

- pertencer ao domínio;
- envolver mais de uma Entity ou Value Object;
- não pertencer naturalmente a uma única Entity;
- for independente de infraestrutura.

Não usar `Service` como nome genérico para qualquer classe.

---

## 17. UseCases

### 17.1 Responsabilidade

UseCases representam intenções da aplicação.

Exemplos:

- `RegisterUser`;
- `AuthenticateUser`;
- `ResetPassword`;
- `ListCustomers`;
- `OpenSupportTicket`.

Um UseCase DEVE:

- ser uma classe concreta com intenção explícita;
- receber um DTO de entrada ou parâmetros tipados simples;
- coordenar regras de domínio;
- depender de Contracts somente para capacidades externas;
- controlar a transação quando a operação exigir atomicidade;
- retornar Entity, Value Object ou DTO de saída;
- lançar exceções de domínio ou aplicação.

Um UseCase NÃO DEVE:

- implementar um Contract criado apenas para espelhar sua assinatura;
- conhecer HTTP;
- retornar `JsonResponse`;
- receber FormRequest;
- usar Eloquent Model diretamente;
- utilizar facade;
- formatar Resource;
- montar mensagem HTTP.

### 17.2 Exemplo

```php
<?php

declare(strict_types=1);

namespace Modules\Identity\UseCases;

use Modules\Identity\Contracts\Repositories\UserRepository;
use Modules\Identity\Contracts\Services\PasswordHasher;
use Modules\Identity\Domain\Entities\User;
use Modules\Identity\Domain\ValueObjects\Email;
use Modules\Identity\DTOs\Input\RegisterUserDto;
use Modules\Identity\Exceptions\UserException;

final readonly class RegisterUser
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
    ) {
    }

    public function handle(RegisterUserDto $input): User
    {
        $email = Email::fromString($input->email);

        $this->ensureEmailIsAvailable($email);

        $user = User::register(
            id: $this->users->nextIdentity(),
            name: $input->name,
            email: $email,
            passwordHash: $this->passwordHasher->hash(
                $input->plainTextPassword,
            ),
        );

        $this->users->save($user);

        return $user;
    }

    private function ensureEmailIsAvailable(Email $email): void
    {
        if (! $this->users->existsByEmail($email)) {
            return;
        }

        throw UserException::emailAlreadyInUse($email);
    }
}
```

O nome do arquivo e da classe DEVE expressar diretamente a intenção, como `RegisterUser.php`. O sufixo `Handler` não é necessário quando a própria classe representa o UseCase.

### 17.3 Transações

O UseCase define a fronteira transacional, mas não deve depender diretamente da facade `DB`.

Quando necessário, criar um Contract global ou do módulo:

```php
interface TransactionManager
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed;
}
```

A Infrastructure implementa esse Contract com o mecanismo do Laravel.

Não criar TransactionManager para operações simples que fazem apenas uma escrita atômica.

---

## 18. Infrastructure

### 18.1 Responsabilidade

Infrastructure contém adaptadores de entrada e saída, incluindo:

- HTTP;
- Eloquent;
- cache;
- filas;
- e-mail;
- armazenamento;
- autenticação do framework;
- APIs externas;
- implementações de Contracts;
- comandos de console;
- providers técnicos internos.

### 18.2 Eloquent Models

Eloquent Models DEVEM permanecer na Infrastructure.

Eles representam persistência, não o domínio.

Não retornar Eloquent Model para UseCases quando o módulo utilizar Entities de domínio.

### 18.3 Repository de infraestrutura

```php
<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Persistence\Eloquent\Repositories;

use Modules\Identity\Contracts\Repositories\UserRepository;
use Modules\Identity\Domain\Entities\User;
use Modules\Identity\Domain\ValueObjects\Email;
use Modules\Identity\Domain\ValueObjects\UserId;
use Modules\Identity\Infrastructure\Persistence\Eloquent\Mappers\UserMapper;
use Modules\Identity\Infrastructure\Persistence\Eloquent\Models\UserModel;

final readonly class EloquentUserRepository implements UserRepository
{
    public function __construct(private UserMapper $mapper)
    {
    }

    public function nextIdentity(): UserId
    {
        return UserId::generate();
    }

    public function existsByEmail(Email $email): bool
    {
        return UserModel::query()
            ->where('email', $email->value())
            ->exists();
    }

    public function save(User $user): void
    {
        UserModel::query()->updateOrCreate(
            ['id' => $user->id()->value()],
            $this->mapper->toPersistence($user),
        );
    }
}
```

O uso de facades e chamadas estáticas é tolerado dentro de adaptadores de Infrastructure quando for idiomático e testável. PREFERIR injeção quando houver benefício real.

### 18.4 Mappers

Mappers DEVEM concentrar conversões entre:

- Eloquent Model e Entity;
- payload externo e DTO interno;
- representação de banco e Value Object.

Não espalhar conversões de persistência pelo UseCase.

---

## 19. Exceções

### 19.1 Localização

As exceções do módulo DEVEM ficar em:

```text
modules/{Module}/Exceptions
```

Elas DEVEM representar falhas significativas da aplicação ou do domínio e permanecer independentes de HTTP.

### 19.2 Agrupamento por contexto e coesão

Exceções PODEM ser agrupadas em uma única classe quando todas as variações:

- pertencem ao mesmo conceito ou agregado;
- compartilham significado e tratamento semelhantes;
- permanecem fáceis de localizar;
- formam um conjunto pequeno e coeso;
- não exigem hierarquias ou comportamentos muito diferentes.

Cada variação agrupada DEVE ser criada por um método estático nomeado, que expresse claramente a falha. O construtor DEVE permanecer privado para impedir mensagens e códigos inconsistentes.

Exemplo em `modules/Identity/Exceptions/UserException.php`:

```php
<?php

declare(strict_types=1);

namespace Modules\Identity\Exceptions;

use DomainException;
use Modules\Identity\Domain\ValueObjects\Email;
use Modules\Identity\Domain\ValueObjects\UserId;

final class UserException extends DomainException
{
    public const EMAIL_ALREADY_IN_USE = 'EMAIL_ALREADY_IN_USE';
    public const NOT_FOUND = 'USER_NOT_FOUND';

    private function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function emailAlreadyInUse(Email $email): self
    {
        return new self(
            errorCode: self::EMAIL_ALREADY_IN_USE,
            message: sprintf(
                'O e-mail %s já está em uso.',
                $email->value(),
            ),
        );
    }

    public static function notFound(UserId $userId): self
    {
        return new self(
            errorCode: self::NOT_FOUND,
            message: sprintf(
                'O usuário %s não foi encontrado.',
                $userId->value(),
            ),
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
```

Uso:

```php
throw UserException::emailAlreadyInUse($email);
throw UserException::notFound($userId);
```

Métodos estáticos de criação NÃO DEVEM ser genéricos como `create()`, `make()` ou `fromMessage()`. Usar nomes que representem a condição, como `emailAlreadyInUse()` e `notFound()`.

### 19.3 Quando usar arquivos únicos

Uma exceção DEVE possuir sua própria classe e arquivo quando:

- não pertence claramente a um grupo coeso;
- exige tratamento, dados ou comportamento específico;
- representa uma fronteira ou falha distinta;
- o agrupamento produziria uma classe genérica com muitos métodos;
- sua evolução independente é provável por uma necessidade atual.

Exemplo em `modules/Identity/Exceptions/ExternalIdentityProviderUnavailable.php`:

```php
<?php

declare(strict_types=1);

namespace Modules\Identity\Exceptions;

use RuntimeException;

final class ExternalIdentityProviderUnavailable extends RuntimeException
{
}
```

Uso:

```php
throw new ExternalIdentityProviderUnavailable(
    'O provedor externo de identidade está indisponível.',
);
```

Não criar arquivos separados para pequenas variações do mesmo contexto quando uma classe coesa com métodos estáticos expressivos for mais simples. Também NÃO criar classes agrupadoras genéricas como `ModuleException`, `BusinessException` ou `ApplicationException` contendo falhas sem relação direta.

### 19.4 Independência de HTTP

Exceções de domínio e aplicação NÃO DEVEM carregar:

- status HTTP;
- JsonResponse;
- Resource;
- Request;
- headers HTTP.

Um código estável da aplicação, como `EMAIL_ALREADY_IN_USE`, PODE existir na exceção para permitir sua tradução na borda. A tradução para status e resposta HTTP deve ocorrer no tratamento global ou no adaptador HTTP.

### 19.5 Não usar exceções para fluxo normal

Exceções DEVEM representar situações excepcionais ou violações de regra.

Resultados esperados de busca podem ser representados por `null`, Result Object ou DTO específico quando isso tornar o fluxo mais claro.

---

## 20. Resposta padrão da API

### 20.1 Sucesso

```json
{
  "success": true,
  "message": "Usuário criado com sucesso.",
  "data": {}
}
```

### 20.2 Erro de validação

```json
{
  "success": false,
  "message": "Os dados informados são inválidos.",
  "error": {
    "code": "VALIDATION_ERROR",
    "details": {
      "email": ["O campo e-mail é obrigatório."]
    }
  }
}
```

### 20.3 Erro de regra de negócio

```json
{
  "success": false,
  "message": "O e-mail informado já está em uso.",
  "error": {
    "code": "EMAIL_ALREADY_IN_USE",
    "details": null
  }
}
```

### 20.4 Regras do envelope

- `success` DEVE sempre ser booleano;
- `message` DEVE ser adequada ao consumidor da API;
- `data` DEVE existir em respostas de sucesso quando houver conteúdo;
- `error.code` DEVE ser estável e legível por máquina;
- `error.details` PODE conter detalhes seguros;
- stack traces e informações internas NÃO DEVEM ser expostos em produção.

### 20.5 Classe central de resposta

```php
<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'Operação realizada com sucesso.',
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => self::resolveData($data),
        ], $status);
    }

    public static function created(
        mixed $data = null,
        string $message = 'Recurso criado com sucesso.',
    ): JsonResponse {
        return self::success(
            data: $data,
            message: $message,
            status: Response::HTTP_CREATED,
        );
    }

    /**
     * @param array<string, list<string>> $errors
     */
    public static function validationError(array $errors): JsonResponse
    {
        return self::error(
            message: 'Os dados informados são inválidos.',
            code: 'VALIDATION_ERROR',
            details: $errors,
            status: Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function error(
        string $message,
        string $code,
        mixed $details = null,
        int $status = Response::HTTP_BAD_REQUEST,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => [
                'code' => $code,
                'details' => $details,
            ],
        ], $status);
    }

    private static function resolveData(mixed $data): mixed
    {
        if ($data instanceof Responsable) {
            return $data->toResponse(request())->getData(true);
        }

        return $data;
    }
}
```

A implementação pode ser ajustada ao projeto, mas o envelope público DEVE permanecer consistente.

---

## 21. API Resources

Resources pertencem ao adaptador HTTP e DEVEM ficar dentro da Infrastructure do módulo.

```php
<?php

declare(strict_types=1);

namespace Modules\Identity\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Identity\Domain\Entities\User;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id()->value(),
            'name' => $this->resource->name(),
            'email' => $this->resource->email()->value(),
            'active' => $this->resource->isActive(),
        ];
    }
}
```

Resources NÃO DEVEM:

- executar regra de negócio;
- consultar Repositories;
- disparar UseCases;
- alterar estado;
- expor campos sensíveis.

---

## 22. Tratamento centralizado de exceções

A borda HTTP DEVE converter exceções conhecidas para o padrão da API.

Para uma exceção agrupada, o código estável da aplicação PODE determinar a tradução HTTP:

```php
$exceptions->render(function (UserException $exception): JsonResponse {
    $status = match ($exception->errorCode()) {
        UserException::EMAIL_ALREADY_IN_USE => Response::HTTP_CONFLICT,
        UserException::NOT_FOUND => Response::HTTP_NOT_FOUND,
        default => Response::HTTP_UNPROCESSABLE_ENTITY,
    };

    return ApiResponse::error(
        message: $exception->getMessage(),
        code: $exception->errorCode(),
        status: $status,
    );
});
```

Exceções de arquivo único DEVEM possuir uma tradução própria quando exigirem tratamento específico.

Controllers NÃO DEVEM repetir `try/catch` para exceções que possuem tradução global.

Erros inesperados DEVEM:

- ser registrados com contexto seguro;
- retornar mensagem genérica em produção;
- preservar rastreabilidade por identificador de erro quando o projeto adotar esse mecanismo.

---

## 23. Rotas do módulo

Rotas DEVEM ficar junto ao adaptador HTTP do módulo:

```text
modules/Identity/Infrastructure/Http/Routes/api.php
```

Exemplo:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Identity\Infrastructure\Http\Controllers\RegisterUserController;

Route::prefix('api/identity')
    ->name('api.identity.')
    ->group(function (): void {
        Route::post('/users', RegisterUserController::class)
            ->name('users.store');
    });
```

Rotas DEVEM:

- usar nomes consistentes;
- aplicar middleware na borda;
- não conter regra de negócio;
- apontar para Controllers do próprio módulo.

---

## 24. Consultas e listagens

Leituras complexas PODEM utilizar uma abordagem otimizada e separada do modelo de escrita.

Opções permitidas:

- Query UseCase;
- Read Model;
- Projection;
- Query Repository específico;
- DTO de saída direto para listagens.

Mesmo em consultas, Controllers NÃO DEVEM acessar Query Builder ou Eloquent diretamente.

Não implementar CQRS completo sem uma necessidade concreta.

---

## 25. Segurança

Todo código gerado DEVE considerar:

- autorização no FormRequest, Policy ou Gate;
- mass assignment;
- exposição de campos sensíveis;
- SQL injection;
- validação de arquivos;
- limites de tamanho;
- rate limiting;
- proteção de credenciais;
- logs sem senhas, tokens ou dados sensíveis;
- tratamento seguro de exceções;
- índice único e consistência no banco;
- idempotência quando necessária.

Senhas em texto puro NÃO DEVEM sair da fronteira necessária do caso de uso e NÃO DEVEM ser armazenadas ou registradas em logs.

---

## 26. Testes

### 26.1 Estratégia por camada

#### Domain — testes unitários

Testar:

- invariantes;
- Value Objects;
- comportamento de Entities;
- Domain Services;
- coleções de primeira classe.

Sem banco e sem boot do Laravel.

#### UseCases — testes unitários

Testar:

- coordenação do fluxo;
- regras de aplicação;
- interações com Contracts;
- exceções esperadas;
- resultados retornados.

Utilizar fakes ou mocks das portas de saída.

#### Infrastructure — testes de integração

Testar:

- Repositories Eloquent;
- Mappers;
- integrações externas;
- ServiceProvider e bindings relevantes;
- transações.

#### HTTP — testes de Feature

Testar:

- validação;
- autorização;
- status HTTP;
- envelope da API;
- serialização de Resource;
- tradução de exceções.

### 26.2 Estrutura

Os testes específicos do módulo DEVEM, preferencialmente, ficar em:

```text
modules/{Module}/Tests
```

Testes globais de integração entre módulos PODEM ficar em `tests/`.

### 26.3 Teste do `toDto()`

Cada FormRequest relevante DEVE ter cobertura que confirme:

- normalização de entrada;
- tipos mapeados;
- campos opcionais;
- conversão correta para o DTO esperado.

### 26.4 Teste arquitetural

O projeto DEVE considerar testes automatizados que impeçam dependências proibidas, por exemplo:

- Domain importando `Illuminate`;
- UseCases importando Eloquent Models;
- um módulo acessando Infrastructure interna de outro módulo;
- Controllers acessando banco diretamente.

---

## 27. Convenções de nomenclatura

### 27.1 Módulos

```text
Identity
Customers
Billing
Support
```

### 27.2 Controllers

Para uma única ação:

```text
RegisterUserController
DeactivateUserController
OpenTicketController
```

Para recurso REST coeso:

```text
UserController
TicketController
```

### 27.3 FormRequests

```text
RegisterUserRequest
UpdateUserRequest
OpenTicketRequest
```

### 27.4 DTOs

```text
RegisterUserDto
UpdateUserDto
ListUsersQueryDto
RegisteredUserDto
```

### 27.5 UseCases

```text
RegisterUser
AuthenticateUser
OpenTicket
```

UseCases concretos DEVEM usar nomes de intenção. Não adicionar o sufixo `Handler` sem uma razão concreta.

### 27.6 Contracts de persistência

```text
UserRepository
TicketRepository
```

### 27.7 Implementações de infraestrutura

```text
EloquentUserRepository
RedisTokenStore
LaravelPasswordHasher
StripePaymentGateway
```

### 27.9 Métodos

Métodos DEVEM expressar intenção:

```text
handle
register
activate
deactivate
existsByEmail
findById
save
```

Evitar nomes vagos:

```text
process
executeData
doAction
manage
handleStuff
```

`handle` é permitido como operação pública padronizada de UseCases quando o nome da classe já comunica a intenção completa.

---

## 28. Regras de estilo

- métodos curtos e focados;
- guard clauses;
- evitar `else` quando o retorno antecipado for mais claro;
- evitar comentários que apenas traduzem o código;
- usar comentários para explicar decisões e restrições não óbvias;
- não usar números ou strings mágicas quando representarem conceito estável;
- evitar parâmetros booleanos que alteram radicalmente o comportamento;
- preferir Enums ou métodos distintos;
- evitar métodos com muitos parâmetros; considerar DTO ou objeto de comando;
- evitar herança para reutilização acidental;
- usar composição;
- evitar estado global;
- não utilizar facades no Domain ou nos UseCases;
- não utilizar helpers globais do Laravel no Domain;
- não utilizar arrays sem estrutura como contrato entre camadas;
- propriedades públicas mutáveis são proibidas em objetos de domínio;
- aplicar `final` por padrão;
- aplicar `readonly` quando o objeto for realmente imutável.

---

## 29. Padrões proibidos

### 29.1 Validação no Controller

```php
$request->validate([...]);
```

### 29.2 Entrada sem DTO

```php
$useCase->handle($request->all());
```

### 29.3 Regra de negócio no Controller

```php
if (UserModel::query()->where('email', $email)->exists()) {
    // ...
}
```

### 29.4 Persistência no FormRequest

```php
public function toDto(): RegisterUserDto
{
    UserModel::query()->create($this->validated());
}
```

### 29.5 Eloquent no UseCase

```php
final class RegisterUser
{
    public function handle(RegisterUserDto $input): UserModel
    {
        return UserModel::query()->create([...]);
    }
}
```

### 29.6 Infraestrutura no Domain

```php
use Illuminate\Support\Facades\Hash;
```

### 29.7 Repository genérico

```php
interface BaseRepository
{
    public function all(): array;
    public function find(int $id): mixed;
    public function create(array $data): mixed;
}
```

### 29.8 Service genérico sem responsabilidade

```php
final class UserService
{
    // dezenas de operações sem uma intenção única
}
```

### 29.9 Acesso interno entre módulos

```php
use Modules\Billing\Infrastructure\Persistence\Eloquent\Models\InvoiceModel;
```

### 29.10 Captura genérica no Controller

```php
try {
    // ...
} catch (Throwable $exception) {
    return response()->json([...]);
}
```

### 29.11 Service Locator

```php
$repository = app(UserRepository::class);
```

Dependências DEVEM ser injetadas explicitamente.

---

## 30. Exemplo completo de fluxo

### 30.1 Entrada HTTP

```text
POST /api/identity/users
```

### 30.2 Sequência

```text
RegisterUserRequest
    → valida e normaliza
    → cria RegisterUserDto

RegisterUserController
    → recebe o UseCase concreto RegisterUser
    → chama handle(dto)

RegisterUser
    → cria Email
    → verifica UserRepository
    → usa PasswordHasher
    → cria User
    → salva pelo UserRepository

EloquentUserRepository
    → mapeia User para persistência
    → grava com Eloquent

UserResource
    → transforma User para API

ApiResponse
    → aplica envelope e status 201
```

### 30.3 Composição

```text
IdentityServiceProvider
    UserRepository        → EloquentUserRepository
    PasswordHasher        → LaravelPasswordHasher

Container do Laravel
    RegisterUser          → resolvido diretamente por autowiring
```

---

## 31. Checklist para geração de código por IA

Antes de gerar ou alterar qualquer funcionalidade, verificar:

### 31.1 Módulo

- [ ] A funcionalidade pertence a qual módulo?
- [ ] O módulo está em `modules/{Module}`?
- [ ] O namespace acompanha o caminho físico?
- [ ] O código foi colocado na camada correta?
- [ ] A mudança evita dependência circular entre módulos?

### 31.2 Arquitetura

- [ ] Domain está livre de Laravel e Infrastructure?
- [ ] UseCase depende de Domain, DTOs e Contracts de saída, sem conhecer Infrastructure?
- [ ] Implementações técnicas estão em Infrastructure?
- [ ] Os bindings necessários para Contracts de saída foram registrados no ServiceProvider do módulo?
- [ ] Contracts representam fronteiras reais, sem abstração prematura?

### 31.3 Entrada

- [ ] Existe FormRequest dedicado?
- [ ] Ele estende `ApiFormRequest`?
- [ ] Possui `authorize()`?
- [ ] Possui `rules()`?
- [ ] Possui `toDto()` com retorno concreto?
- [ ] Normalizações simples estão em `prepareForValidation()`?
- [ ] O Controller não usa `$request->all()`?

### 31.4 Controller

- [ ] É mínimo?
- [ ] Injeta diretamente o UseCase concreto?
- [ ] Não acessa Model ou Repository?
- [ ] Não possui regra de negócio?
- [ ] Retorna resposta HTTP padronizada?

### 31.5 UseCase

- [ ] Representa uma intenção clara?
- [ ] Recebe DTO ou tipos explícitos?
- [ ] Não conhece HTTP, Eloquent ou FormRequest?
- [ ] Usa Contracts apenas para dependências externas e fronteiras reais?
- [ ] Não implementa interface criada apenas para representar o próprio UseCase?
- [ ] A transação está na fronteira correta?

### 31.6 Domain

- [ ] Invariantes estão protegidas?
- [ ] Primitivas relevantes foram avaliadas como Value Objects?
- [ ] Objetos expõem comportamento, não apenas setters?
- [ ] Coleções com regras foram encapsuladas?

### 31.7 Infrastructure

- [ ] Eloquent Model está na Infrastructure?
- [ ] Repository implementa um Contract orientado ao caso de uso?
- [ ] Conversões estão concentradas em Mapper quando necessário?
- [ ] Recursos externos não vazam tipos para o núcleo?

### 31.8 Exceções

- [ ] Exceções do mesmo conceito foram agrupadas apenas quando há coesão real?
- [ ] Variações agrupadas usam métodos estáticos expressivos?
- [ ] Exceções distintas possuem classes e arquivos próprios?
- [ ] Não existe uma classe genérica acumulando falhas sem relação?
- [ ] Status HTTP permanece fora das exceções?

### 31.9 Qualidade

- [ ] `declare(strict_types=1);` está presente?
- [ ] Parâmetros e retornos estão tipados?
- [ ] A classe pode ser `final`?
- [ ] O objeto pode ser `readonly`?
- [ ] DRY foi aplicado a conhecimento real?
- [ ] A solução respeita KISS?
- [ ] Nenhuma abstração viola YAGNI?
- [ ] SOLID foi considerado?
- [ ] Object Calisthenics foi aplicado onde melhora o design?
- [ ] Não existem nomes genéricos ou abreviados?
- [ ] Existem testes na camada adequada?

---

## 32. Instrução operacional para IA

Ao gerar código para este projeto, a IA DEVE:

1. identificar o módulo de negócio correto;
2. manter todo código específico dentro de `modules/{Module}`;
3. preservar a separação entre Domain, UseCases, Contracts e Infrastructure;
4. criar ou atualizar o ServiceProvider do módulo para realizar a composição;
5. criar FormRequest para toda entrada HTTP;
6. implementar `toDto()` no FormRequest;
7. manter Controllers mínimos;
8. criar UseCase concreto com intenção explícita, sem Contract próprio por padrão;
9. depender de Contracts somente nas fronteiras externas e portas de saída;
10. agrupar Exceptions apenas por contexto e coesão, usando métodos estáticos expressivos;
11. manter Exceptions distintas em classes e arquivos próprios;
12. manter Eloquent, HTTP, filas e serviços externos na Infrastructure;
13. utilizar o padrão único de respostas e erros da API;
14. aplicar Object Calisthenics, DRY, KISS, YAGNI e SOLID sem dogmatismo;
15. gerar apenas arquivos necessários para a funcionalidade atual;
16. não inventar abstrações, campos, eventos ou requisitos;
17. incluir testes relevantes;
18. listar arquivos criados e alterados ao final da resposta;
19. explicar qualquer exceção arquitetural adotada.

Quando houver ambiguidade pequena, a IA DEVE escolher a opção mais simples e consistente com este guia.

Quando uma decisão puder alterar contrato público, persistência, segurança ou comunicação entre módulos, a IA DEVE tornar a suposição explícita.

---

## 33. Template resumido

### FormRequest

```php
final class ExampleRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function toDto(): ExampleDto
    {
        return new ExampleDto();
    }
}
```

### DTO

```php
final readonly class ExampleDto
{
    public function __construct(
        public string $value,
    ) {
    }
}
```

### UseCase

```php
final readonly class PerformExample
{
    public function __construct(
        private ExampleRepository $examples,
    ) {
    }

    public function handle(ExampleDto $input): ExampleResult
    {
        // Coordenar o caso de uso.
    }
}
```

### Controller

```php
final class ExampleController
{
    public function __invoke(
        ExampleRequest $request,
        PerformExample $performExample,
    ): JsonResponse {
        $result = $performExample->handle($request->toDto());

        return ApiResponse::success(
            data: new ExampleResource($result),
        );
    }
}
```

### Exceção agrupada

```php
final class ExampleException extends DomainException
{
    private function __construct(
        private readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function duplicated(string $value): self
    {
        return new self(
            errorCode: 'EXAMPLE_DUPLICATED',
            message: sprintf('O valor %s já existe.', $value),
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
```

### ServiceProvider

```php
final class ExampleServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public $bindings = [
        ExampleRepository::class => EloquentExampleRepository::class,
    ];
}
```

---

## 34. Decisão central do projeto

A regra central deste projeto é:

> O núcleo de cada módulo expressa o negócio em PHP puro. UseCases são classes concretas, enquanto Laravel e demais tecnologias são adaptadores externos conectados por Contracts de saída no ServiceProvider do módulo.

Consequentemente:

- Controllers são finos;
- FormRequests validam, normalizam e criam DTOs;
- UseCases coordenam intenções da aplicação;
- Domain protege regras e invariantes;
- Contracts definem portas de saída e fronteiras de integração;
- Infrastructure implementa adaptadores;
- ServiceProviders realizam a composição;
- módulos não acessam detalhes internos uns dos outros;
- simplicidade e clareza prevalecem sobre abstração excessiva.
