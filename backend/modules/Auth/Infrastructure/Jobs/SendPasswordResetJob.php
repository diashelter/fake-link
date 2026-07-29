<?php

declare(strict_types=1);

namespace Modules\Auth\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Modules\Auth\Contracts\Repositories\UserRepository;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Auth\Infrastructure\Mail\PasswordResetMail;
use RuntimeException;
use Throwable;

final class SendPasswordResetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $userId,
        public readonly string $encryptedToken,
    ) {}

    public function handle(UserRepository $userRepository): void
    {
        try {
            $plainText = Crypt::decryptString($this->encryptedToken);
        } catch (Throwable) {
            throw new RuntimeException('Unable to decrypt password reset token payload.');
        }

        $user = $userRepository->findById(UserId::fromString($this->userId));

        if ($user === null) {
            throw new RuntimeException('Password reset recipient not found.');
        }

        $baseUrl = rtrim((string) config('auth.password_reset.frontend_base_url'), '/');
        $path = (string) config('auth.password_reset.frontend_path');
        $resetUrl = $baseUrl.$path.'?token='.rawurlencode($plainText);

        Mail::to($user->email()->value())->send(
            new PasswordResetMail(
                recipientName: $user->name(),
                resetUrl: $resetUrl,
            ),
        );
    }
}
