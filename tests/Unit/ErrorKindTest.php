<?php

use App\Enums\ErrorKind;

test('only provably safe outbound outcomes report retryable', function () {
    expect(ErrorKind::RateLimited->isRetryable())->toBeTrue()
        ->and(ErrorKind::MediaProcessing->isRetryable())->toBeTrue()
        ->and(ErrorKind::Network->isRetryable())->toBeFalse()
        ->and(ErrorKind::ServerError->isRetryable())->toBeFalse();
});

test('ambiguous transport outcomes require publish reconciliation', function () {
    expect(ErrorKind::Network->requiresPublishReconciliation())->toBeTrue()
        ->and(ErrorKind::ServerError->requiresPublishReconciliation())->toBeTrue()
        ->and(ErrorKind::RateLimited->requiresPublishReconciliation())->toBeFalse();
});

test('terminal kinds report not retryable', function () {
    expect(ErrorKind::AuthExpired->isRetryable())->toBeFalse()
        ->and(ErrorKind::Validation->isRetryable())->toBeFalse()
        ->and(ErrorKind::DuplicateContent->isRetryable())->toBeFalse()
        ->and(ErrorKind::Unsupported->isRetryable())->toBeFalse()
        ->and(ErrorKind::Unknown->isRetryable())->toBeFalse();
});

test('cases carry the wire values', function () {
    expect(ErrorKind::RateLimited->value)->toBe('rate_limited')
        ->and(ErrorKind::AuthExpired->value)->toBe('auth_expired')
        ->and(ErrorKind::ServerError->value)->toBe('server_error');
});
