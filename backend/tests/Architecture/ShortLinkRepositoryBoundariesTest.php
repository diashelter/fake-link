<?php

declare(strict_types=1);

use Modules\Links\Contracts\Repositories\ShortLinkRepository;
use Modules\Links\Infrastructure\Persistence\Eloquent\Repositories\EloquentShortLinkRepository;

/*
|--------------------------------------------------------------------------
| Short link repository immutability boundaries (link-creation T7)
|--------------------------------------------------------------------------
|
| Discrimination sensor — the mutation each rule is built to kill:
|
| R1 A mutant that adds updateSlug / updateUserId / changeOwner / reassign
|    (or a generic update) to the ShortLinkRepository port is killed —
|    slug and owner are immutable after insert.
|
| R2 The same for the Eloquent adapter — no update path may appear on the
|    implementation even if the port stays clean.
|
*/

arch('ShortLinkRepository has no update methods for slug or owner')
    ->expect(ShortLinkRepository::class)
    ->not->toHaveMethod('update')
    ->not->toHaveMethod('updateSlug')
    ->not->toHaveMethod('updateUserId')
    ->not->toHaveMethod('changeOwner')
    ->not->toHaveMethod('reassign');

arch('EloquentShortLinkRepository has no update methods for slug or owner')
    ->expect(EloquentShortLinkRepository::class)
    ->not->toHaveMethod('update')
    ->not->toHaveMethod('updateSlug')
    ->not->toHaveMethod('updateUserId')
    ->not->toHaveMethod('changeOwner')
    ->not->toHaveMethod('reassign');
