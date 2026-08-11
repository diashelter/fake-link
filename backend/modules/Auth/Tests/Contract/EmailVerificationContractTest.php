<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Domain\Enums\TokenKind;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\DTOs\Input\IssueAuthTokenDto;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\RateLimit\HmacRateLimitKeyFactory;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Modules\Auth\Tests\Support\OpenApi\AuthOpenApiCatalog;
use Modules\Auth\Tests\Support\OpenApi\OpenApiDocument;
use Modules\Auth\Tests\Support\OpenApi\OpenApiSchemaAssert;
use Modules\Auth\UseCases\IssueAuthToken;
use Modules\Auth\UseCases\IssueEmailVerificationToken;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
    OpenApiDocument::clearCache();

    $this->knownPassword = 'ValidPass1!xy';
});

afterEach(function () {
    OpenApiDocument::clearCache();
});

function issueVerificationBearerForEmailContract(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Verification),
    )->plainTextToken;
}

function issueSessionBearerForEmailContract(UserModel $user): string
{
    return app(IssueAuthToken::class)->execute(
        new IssueAuthTokenDto(UserId::fromString($user->id), TokenKind::Session),
    )->plainTextToken;
}

function issueEmailVerificationPlaintextForContract(UserModel $user): string
{
    return app(IssueEmailVerificationToken::class)
        ->execute(UserId::fromString($user->id))
        ->plainTextToken;
}

function clearEmailVerificationRateLimitsForContract(UserId $userId): void
{
    $factory = new HmacRateLimitKeyFactory;
    RateLimiter::clear($factory->forEmailVerificationResend($userId));
    RateLimiter::clear($factory->forEmailVerificationVerify($userId));
}

describe('Contract: POST /api/v1/auth/email/verify', function () {
    it('returns 204 No Content on successful verification', function () {
        $user = UserModel::factory()->withPassword($this->knownPassword)->create([
            'email' => 'contract.verify@example.com',
        ]);
        $bearer = issueVerificationBearerForEmailContract($user);
        $emailToken = issueEmailVerificationPlaintextForContract($user);
        clearEmailVerificationRateLimitsForContract(UserId::fromString($user->id));

        $response = $this->postJson('/api/v1/auth/email/verify', [
            'token' => $emailToken,
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $response->assertNoContent();
        expect($response->getContent())->toBe('');
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);
    });

    it('returns 403 INVALID_VERIFICATION_TOKEN with exact OpenAPI message', function () {
        $user = UserModel::factory()->create(['email' => 'contract.verify.bad@example.com']);
        $bearer = issueVerificationBearerForEmailContract($user);
        clearEmailVerificationRateLimitsForContract(UserId::fromString($user->id));

        $response = $this->postJson('/api/v1/auth/email/verify', [
            'token' => 'not-valid',
        ], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        OpenApiSchemaAssert::assertErrorEnvelope(
            $response,
            403,
            AuthOpenApiCatalog::INVALID_VERIFICATION_TOKEN,
            AuthOpenApiCatalog::message(AuthOpenApiCatalog::INVALID_VERIFICATION_TOKEN),
        );
    });
});

describe('Contract: POST /api/v1/auth/email/verification-notification', function () {
    it('returns 202 Accepted on resend for pending verification user', function () {
        Queue::fake();

        $user = UserModel::factory()->create(['email' => 'contract.resend@example.com']);
        $bearer = issueVerificationBearerForEmailContract($user);
        clearEmailVerificationRateLimitsForContract(UserId::fromString($user->id));

        $response = $this->postJson('/api/v1/auth/email/verification-notification', [], [
            'Authorization' => 'Bearer '.$bearer,
        ]);

        $response->assertAccepted();
        OpenApiSchemaAssert::assertPrivateCacheAndRequestId($response);
    });

    it('returns 403 TOKEN_RESTRICTED for session bearer on resend', function () {
        $user = UserModel::factory()->create([
            'email' => 'contract.resend.session@example.com',
        ]);
        $bearer = issueSessionBearerForEmailContract($user);
        clearEmailVerificationRateLimitsForContract(UserId::fromString($user->id));

        $response = $this->postJson('/api/v1/auth/email/verification-notification', [], [
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
