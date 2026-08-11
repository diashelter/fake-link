<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Infrastructure\Jobs\SendPasswordResetJob;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\Tests\Support\OpenApi\AuthOpenApiCatalog;
use Modules\Auth\Tests\Support\OpenApi\OpenApiDocument;
use Modules\Auth\Tests\Support\OpenApi\OpenApiSchemaAssert;
use Modules\Auth\UseCases\IssueAuthToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    OpenApiDocument::clearCache();

    $this->clientIp = '203.0.113.'.random_int(1, 254);
    $this->knownPassword = 'ValidPass1!xy';
    $this->newPassword = 'BrandNewPass1!x';
});

afterEach(function () {
    OpenApiDocument::clearCache();
});

function issueSessionBearerForPasswordContract(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    )->plainTextToken;
}

function issueVerificationBearerForPasswordContract(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Verification),
    )->plainTextToken;
}

function clearPasswordResetRequestLimitForContract(string $ip, string $email): void
{
    RateLimiter::clear((new HmacRateLimitKeyFactory)->forPasswordResetRequest($ip, $email));
}

function clearPasswordResetCompleteLimitForContract(string $ip, string $token): void
{
    RateLimiter::clear((new HmacRateLimitKeyFactory)->forPasswordResetComplete($ip, hash('sha256', $token)));
}

function clearPrivateAuthWriteLimitForPasswordContract(UserId $userId): void
{
    RateLimiter::clear((new HmacRateLimitKeyFactory)->forPrivateAuthWrite($userId));
}

describe('Contract: POST /api/v1/auth/password/reset-request', function () {
    it('returns 202 Accepted envelope with private cache headers', function () {
        Queue::fake();

        UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'contract.reset.request@example.com',
        ]);
        clearPasswordResetRequestLimitForContract($this->clientIp, 'contract.reset.request@example.com');

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset-request', [
                'email' => 'contract.reset.request@example.com',
            ]);

        $response->assertAccepted();
        expect($response->getContent())->toBe('');
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);
    });
});

describe('Contract: POST /api/v1/auth/password/reset', function () {
    it('returns 204 No Content on successful password reset', function () {
        Queue::fake();

        UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'contract.reset.ok@example.com',
        ]);
        clearPasswordResetRequestLimitForContract($this->clientIp, 'contract.reset.ok@example.com');

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset-request', [
                'email' => 'contract.reset.ok@example.com',
            ])
            ->assertAccepted();

        $job = null;
        Queue::assertPushed(SendPasswordResetJob::class, function (SendPasswordResetJob $pushed) use (&$job): bool {
            $job = $pushed;

            return true;
        });

        expect($job)->not->toBeNull();
        $plainToken = Crypt::decryptString($job->encryptedToken);
        clearPasswordResetCompleteLimitForContract($this->clientIp, $plainToken);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset', [
                'email' => 'contract.reset.ok@example.com',
                'token' => $plainToken,
                'password' => $this->newPassword,
                'password_confirmation' => $this->newPassword,
            ]);

        $response->assertNoContent();
        expect($response->getContent())->toBe('');
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);
    });

    it('returns 422 PASSWORD_REUSED with OpenAPI field message', function () {
        Queue::fake();

        UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'contract.reset.reused@example.com',
        ]);
        clearPasswordResetRequestLimitForContract($this->clientIp, 'contract.reset.reused@example.com');

        $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset-request', [
                'email' => 'contract.reset.reused@example.com',
            ])
            ->assertAccepted();

        $job = null;
        Queue::assertPushed(SendPasswordResetJob::class, function (SendPasswordResetJob $pushed) use (&$job): bool {
            $job = $pushed;

            return true;
        });

        expect($job)->not->toBeNull();
        $plainToken = Crypt::decryptString($job->encryptedToken);
        clearPasswordResetCompleteLimitForContract($this->clientIp, $plainToken);

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/reset', [
                'email' => 'contract.reset.reused@example.com',
                'token' => $plainToken,
                'password' => $this->knownPassword,
                'password_confirmation' => $this->knownPassword,
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('code', AuthOpenApiCatalog::VALIDATION_FAILED)
            ->assertJsonPath('errors.password.0.code', AuthOpenApiCatalog::PASSWORD_REUSED)
            ->assertJsonPath(
                'errors.password.0.message',
                AuthOpenApiCatalog::message(AuthOpenApiCatalog::PASSWORD_REUSED),
            );

        $payload = $response->json();
        expect($payload)->toBeArray();
        OpenApiSchemaAssert::assertMatchesSchema(
            $payload,
            OpenApiDocument::load()->schema('ValidationErrorResponse'),
        );
    });
});

describe('Contract: POST /api/v1/auth/password/change', function () {
    it('returns 204 No Content on successful password change', function () {
        $user = UserModel::factory()->active()->withPassword($this->knownPassword)->create([
            'email' => 'contract.change.ok@example.com',
        ]);
        $bearer = issueSessionBearerForPasswordContract($user);
        clearPrivateAuthWriteLimitForPasswordContract(UserId::fromString($user->id));

        $response = $this->withServerVariables(['REMOTE_ADDR' => $this->clientIp])
            ->postJson('/api/v1/auth/password/change', [
                'current_password' => $this->knownPassword,
                'password' => $this->newPassword,
                'password_confirmation' => $this->newPassword,
            ], [
                'Authorization' => 'Bearer '.$bearer,
            ]);

        $response->assertNoContent();
        expect($response->getContent())->toBe('');
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);
    });

    it('returns 403 TOKEN_RESTRICTED for verification bearer', function () {
        $user = UserModel::factory()->create([
            'email' => 'contract.change.verify@example.com',
        ]);
        $bearer = issueVerificationBearerForPasswordContract($user);
        clearPrivateAuthWriteLimitForPasswordContract(UserId::fromString($user->id));

        $response = $this->postJson('/api/v1/auth/password/change', [
            'current_password' => $this->knownPassword,
            'password' => $this->newPassword,
            'password_confirmation' => $this->newPassword,
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        OpenApiSchemaAssert::assertErrorEnvelope(
            $response,
            403,
            AuthOpenApiCatalog::TOKEN_RESTRICTED,
            AuthOpenApiCatalog::message(AuthOpenApiCatalog::TOKEN_RESTRICTED),
        );
    });
});
