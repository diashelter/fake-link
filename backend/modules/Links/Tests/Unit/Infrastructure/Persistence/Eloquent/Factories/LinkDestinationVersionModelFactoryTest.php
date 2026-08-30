<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Modules\Links\Infrastructure\Persistence\Eloquent\Models\LinkDestinationVersionModel;
use Tests\TestCase;

uses(TestCase::class);

describe('LinkDestinationVersionModelFactory', function () {
    it('definition produces required attribute keys', function () {
        $model = LinkDestinationVersionModel::factory()->make();

        expect($model)->toBeInstanceOf(LinkDestinationVersionModel::class)
            ->and($model->id)->not->toBeEmpty()
            ->and($model->short_link_id)->not->toBeEmpty()
            ->and($model->destination_url)->not->toBeEmpty()
            ->and($model->key_id)->toBe('testing-key-1');
    });

    it('withShortLinkId overrides the short_link_id attribute', function () {
        $id = 'aaaaaaaa-bbbb-7ccc-8ddd-eeeeeeeeeeee';
        $model = LinkDestinationVersionModel::factory()->withShortLinkId($id)->make();

        expect($model->short_link_id)->toBe($id);
    });

    it('closed sets a non-null valid_to', function () {
        $model = LinkDestinationVersionModel::factory()->closed()->make();

        expect($model->valid_to)->not->toBeNull();
    });

    it('casts valid_from and valid_to as Carbon instances when set', function () {
        $model = LinkDestinationVersionModel::factory()->closed()->make();

        expect($model->valid_from)->toBeInstanceOf(Carbon::class)
            ->and($model->valid_to)->toBeInstanceOf(Carbon::class);
    });
});
