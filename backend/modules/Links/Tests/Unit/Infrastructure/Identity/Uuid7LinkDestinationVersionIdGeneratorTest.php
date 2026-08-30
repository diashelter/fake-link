<?php

declare(strict_types=1);

use Modules\Links\Infrastructure\Identity\Uuid7LinkDestinationVersionIdGenerator;

describe('Uuid7LinkDestinationVersionIdGenerator', function () {
    it('generates a valid uuid v7 link destination version id', function () {
        $generator = new Uuid7LinkDestinationVersionIdGenerator;
        $id = $generator->generate();

        expect($id->value())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
    });

    it('generates distinct ids on consecutive calls', function () {
        $generator = new Uuid7LinkDestinationVersionIdGenerator;
        $id1 = $generator->generate();
        $id2 = $generator->generate();

        expect($id1->value())->not->toBe($id2->value());
    });

    it('generates temporally ordered ids', function () {
        $generator = new Uuid7LinkDestinationVersionIdGenerator;
        $id1 = $generator->generate();
        $id2 = $generator->generate();

        expect(strcmp($id1->value(), $id2->value()))->toBeLessThanOrEqual(0);
    });
});
