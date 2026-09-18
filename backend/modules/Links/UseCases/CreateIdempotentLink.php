<?php

declare(strict_types=1);

namespace Modules\Links\UseCases;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Auth\Domain\ValueObjects\UserId;
use Modules\Links\Contracts\Repositories\IdempotencyKeyRepository;
use Modules\Links\Contracts\Services\IdempotencySnapshotCipher;
use Modules\Links\Contracts\Services\TransactionManager;
use Modules\Links\Domain\Services\CanonicalCreateLinkCommand;
use Modules\Links\Domain\ValueObjects\IdempotencyKey;
use Modules\Links\DTOs\Input\CreateLinkInput;
use Modules\Links\DTOs\Output\EncryptedIdempotencySnapshot;
use Modules\Links\DTOs\Output\IdempotentCreateLinkResult;
use Modules\Links\Exceptions\IdempotencyKeyReused;
use Modules\Links\Exceptions\IdempotencySnapshotDecryptionFailed;
use Modules\Links\Infrastructure\Http\Responses\LinkCreationSnapshotFactory;

/**
 * Creates a short link under an Idempotency-Key, or replays the stored 201 snapshot.
 *
 * Owns the single transaction that covers key reservation, CreateLink writes,
 * and encrypted snapshot persistence.
 */
final readonly class CreateIdempotentLink
{
    private const TTL = 'PT24H';

    public function __construct(
        private TransactionManager $transactions,
        private CreateLink $createLink,
        private IdempotencyKeyRepository $idempotencyKeys,
        private CanonicalCreateLinkCommand $canonical,
        private IdempotencySnapshotCipher $snapshots,
        private LinkCreationSnapshotFactory $snapshotFactory,
    ) {}

    /**
     * @throws IdempotencyKeyReused
     * @throws IdempotencySnapshotDecryptionFailed
     */
    public function execute(
        UserId $ownerId,
        CreateLinkInput $input,
        IdempotencyKey $idempotencyKey,
    ): IdempotentCreateLinkResult {
        $keyHash = $this->canonical->hashKey($ownerId, $idempotencyKey);
        $fingerprint = $this->canonical->fingerprint($ownerId, $input);

        return $this->transactions->run(
            function () use ($ownerId, $input, $keyHash, $fingerprint): IdempotentCreateLinkResult {
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

                $existing = $this->idempotencyKeys->findNonExpired($ownerId, $keyHash, $now);

                if ($existing !== null) {
                    return $this->replayOrConflict(
                        $existing->requestFingerprint,
                        $fingerprint,
                        $existing->responseSnapshot,
                        $existing->keyId,
                    );
                }

                // Allow the same key after TTL even if the pruner has not run yet.
                $this->idempotencyKeys->deleteExpiredForKey($ownerId, $keyHash, $now);

                $expiresAt = $now->add(new DateInterval(self::TTL));

                try {
                    $this->idempotencyKeys->reserve(
                        $ownerId,
                        $keyHash,
                        $fingerprint,
                        $now,
                        $expiresAt,
                    );
                } catch (UniqueConstraintViolationException) {
                    $raced = $this->idempotencyKeys->findNonExpired($ownerId, $keyHash, $now);
                    if ($raced === null) {
                        throw IdempotencyKeyReused::reused();
                    }

                    return $this->replayOrConflict(
                        $raced->requestFingerprint,
                        $fingerprint,
                        $raced->responseSnapshot,
                        $raced->keyId,
                    );
                }

                $created = $this->createLink->execute($ownerId, $input);
                $snapshot = $this->snapshotFactory->fromCreated($created);
                $encrypted = $this->snapshots->encrypt($snapshot);

                $this->idempotencyKeys->complete(
                    $ownerId,
                    $keyHash,
                    $encrypted->ciphertext,
                    $encrypted->keyId,
                );

                return new IdempotentCreateLinkResult(
                    replayed: false,
                    snapshot: $snapshot,
                    created: $created,
                );
            },
        );
    }

    /**
     * @throws IdempotencyKeyReused
     * @throws IdempotencySnapshotDecryptionFailed
     */
    private function replayOrConflict(
        string $storedFingerprint,
        string $requestFingerprint,
        ?string $responseSnapshot,
        ?string $keyId,
    ): IdempotentCreateLinkResult {
        if (! hash_equals($storedFingerprint, $requestFingerprint)) {
            throw IdempotencyKeyReused::reused();
        }

        if ($responseSnapshot === null || $keyId === null) {
            // Reserved but incomplete — should not be visible committed; treat as conflict.
            throw IdempotencyKeyReused::reused();
        }

        $snapshot = $this->snapshots->decrypt(
            new EncryptedIdempotencySnapshot($responseSnapshot, $keyId),
        );

        return new IdempotentCreateLinkResult(
            replayed: true,
            snapshot: $snapshot,
            created: null,
        );
    }
}
