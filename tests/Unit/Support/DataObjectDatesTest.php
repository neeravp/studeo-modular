<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Studeo\Modular\Support\DataObject;
use Studeo\Modular\Support\OutputData;
use Studeo\Modular\Tests\TestCase;

uses(TestCase::class);

final readonly class TestEventData extends OutputData
{
    public function __construct(
        public CarbonImmutable $publishedAt,
        public ?DateTimeImmutable $closedAt = null,
    ) {}
}

class TestEventModel extends Model
{
    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
        'closed_at' => 'datetime',
    ];
}

test('it serializes dates in ATOM format', function () {
    $dto = TestEventData::from([
        'published_at' => '2026-01-02T03:04:05+00:00',
        'closed_at' => '2026-02-03T04:05:06+00:00',
    ]);

    expect($dto->toArray())->toBe([
        'published_at' => '2026-01-02T03:04:05+00:00',
        'closed_at' => '2026-02-03T04:05:06+00:00',
    ]);
});

test('it hydrates dates by their declared class', function () {
    $dto = TestEventData::from([
        'published_at' => '2026-01-02T03:04:05+00:00',
        'closed_at' => '2026-02-03T04:05:06+00:00',
    ]);

    expect($dto->publishedAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($dto->closedAt)->toBeInstanceOf(DateTimeImmutable::class);
});

test('it hydrates dates from a model as UTC and serializes them as ATOM', function () {
    $model = new TestEventModel;
    $model->setRawAttributes([
        'published_at' => '2026-01-02 03:04:05',
        'closed_at' => '2026-02-03 04:05:06',
    ]);

    $dto = TestEventData::from($model);

    expect($dto->publishedAt->getOffset())->toBe(0)
        ->and($dto->closedAt->getOffset())->toBe(0)
        ->and($dto->toArray())->toBe([
            'published_at' => '2026-01-02T03:04:05+00:00',
            'closed_at' => '2026-02-03T04:05:06+00:00',
        ]);
});

test('data objects no longer expose Livewire hydration', function () {
    expect(method_exists(DataObject::class, 'toLivewire'))->toBeFalse()
        ->and(method_exists(DataObject::class, 'fromLivewire'))->toBeFalse();
});
