<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Modules\Auth\Infrastructure\Identity\Uuid7UserIdGenerator;
use Modules\Auth\Infrastructure\Jobs\SendEmailVerificationJob;
use Modules\Auth\Infrastructure\Mail\EmailVerificationMail;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Mappers\UserMapper;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Auth\Infrastructure\Persistence\Eloquent\Repositories\EloquentUserRepository;
use Modules\Auth\Tests\Support\DatabaseSafetyGuard;
use Tests\TestCase;
use Throwable;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    DatabaseSafetyGuard::assertIsolated((string) config('database.connections.pgsql.database'));
});

describe('SendEmailVerificationJob', function () {
    it('sends mail to the user with the configured verification URL and ciphertext-only job payload', function () {
        Mail::fake();

        config([
            'auth.email_verification.frontend_base_url' => 'https://app.example.test',
            'auth.email_verification.path' => '/verify-email',
        ]);

        $user = UserModel::factory()->create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
        ]);

        $sentinel = 'email-token-sentinel-'.bin2hex(random_bytes(8));
        $job = new SendEmailVerificationJob(
            userId: $user->id,
            encryptedToken: Crypt::encryptString($sentinel),
        );

        expect($job->encryptedToken)->not->toBe($sentinel)
            ->and($job->encryptedToken)->not->toContain($sentinel);

        $job->handle(new EloquentUserRepository(
            userIdGenerator: new Uuid7UserIdGenerator,
            userMapper: new UserMapper,
        ));

        Mail::assertSent(EmailVerificationMail::class, function (EmailVerificationMail $mail) use ($user, $sentinel): bool {
            $expectedUrl = 'https://app.example.test/verify-email?token='.rawurlencode($sentinel);

            return $mail->hasTo($user->email)
                && $mail->recipientName === 'Ana Silva'
                && $mail->verificationUrl === $expectedUrl
                && $mail->envelope()->subject === 'Confirme seu e-mail — Fake Link'
                && ! str_contains($mail->envelope()->subject, $sentinel);
        });
    });

    it('does not expose the plaintext token on the job instance properties beyond ciphertext', function () {
        $sentinel = 'plain-token-must-not-leak';
        $job = new SendEmailVerificationJob(
            userId: '01901234-5678-7abc-89ab-cdef01234567',
            encryptedToken: Crypt::encryptString($sentinel),
        );

        $serialized = serialize($job);

        expect($serialized)->not->toContain($sentinel)
            ->and($job->encryptedToken)->not->toBe($sentinel);
    });

    it('fails decrypt without including plaintext in the exception message', function () {
        $sentinel = 'decrypt-fail-sentinel-token';
        $job = new SendEmailVerificationJob(
            userId: '01901234-5678-7abc-89ab-cdef01234567',
            encryptedToken: 'not-valid-ciphertext',
        );

        try {
            $job->handle(new EloquentUserRepository(
                userIdGenerator: new Uuid7UserIdGenerator,
                userMapper: new UserMapper,
            ));
            expect(false)->toBeTrue();
        } catch (Throwable $exception) {
            expect($exception->getMessage())->not->toContain($sentinel)
                ->and($exception->getMessage())->toBe('Unable to decrypt email verification token payload.');
        }
    });
});
