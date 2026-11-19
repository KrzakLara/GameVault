<?php

use App\Enums\FailureCode;
use App\Enums\OrderStatus;
use App\Services\Outbox\Outbox;

it('allows exactly the documented order transitions', function () {
    $allowed = [];
    foreach (OrderStatus::cases() as $from) {
        foreach (OrderStatus::cases() as $to) {
            if ($from->canTransitionTo($to)) {
                $allowed[] = "{$from->value}->{$to->value}";
            }
        }
    }

    expect($allowed)->toBe([
        'pending->processing', 'pending->failed',
        'processing->delivered', 'processing->failed',
        'failed->pending', 'failed->cancelled',
    ]);
});

it('treats delivered and cancelled as terminal and only pending/processing as in flight', function () {
    expect(collect(OrderStatus::cases())->filter->isTerminal()->map->value->values()->all())->toBe(['delivered', 'cancelled'])
        ->and(collect(OrderStatus::cases())->filter->isInFlight()->map->value->values()->all())->toBe(['pending', 'processing']);
});

it('never offers a retry for a broken key, and never shows internals to customers', function () {
    expect(FailureCode::KeyIntegrity->isRetryable())->toBeFalse()
        ->and(FailureCode::OutOfStock->isRetryable())->toBeTrue();

    foreach (FailureCode::cases() as $code) {
        expect($code->message())->not->toContain($code->value)->not->toContain('exception');
    }
});

it('accepts identifier-only outbox payloads', function (array $payload) {
    Outbox::assertSafePayload($payload);
    expect(true)->toBeTrue();
})->with([
    'order id' => [['order_id' => 42]],
    'several ids' => [['order_id' => 1, 'user_id' => 2, 'status' => 'delivered']],
    'empty' => [[]],
]);

it('rejects outbox payloads that could carry secrets or personal data', function (array $payload) {
    expect(fn () => Outbox::assertSafePayload($payload))->toThrow(InvalidArgumentException::class);
})->with([
    'key code' => [['code' => 'DEMO-1']],
    'e-mail' => [['email' => 'a@b.c']],
    'token' => [['reset_token' => 'x']],
    'nested' => [['order_id' => [1]]],
    'object' => [['order_id' => new stdClass]],
    'numeric key' => [['DEMO-1']],
]);
