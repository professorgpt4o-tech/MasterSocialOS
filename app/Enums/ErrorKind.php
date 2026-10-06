<?php

declare(strict_types=1);

namespace App\Enums;

enum ErrorKind: string
{
    case RateLimited = 'rate_limited';
    case AuthExpired = 'auth_expired';
    case Validation = 'validation';
    case DuplicateContent = 'duplicate_content';
    case BillingRequired = 'billing_required';
    case Network = 'network';
    case ServerError = 'server_error';
    case MediaProcessing = 'media_processing';
    /** A required server-side capability is missing (e.g. a media tool isn't installed); retrying can't fix it. */
    case Unsupported = 'unsupported';
    case Unknown = 'unknown';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::RateLimited, self::Network, self::ServerError, self::MediaProcessing => true,
            self::AuthExpired, self::Validation, self::DuplicateContent, self::BillingRequired, self::Unsupported, self::Unknown => false,
        };
    }

    /**
     * Publishing has a stricter retry contract than background reads.
     * Network and 5xx outcomes are ambiguous: the provider may have accepted the
     * post before the response was lost. Blindly retrying can create duplicates.
     * Only outcomes that prove no new publish should occur, or that resume an
     * already-created media-processing state, may be retried automatically.
     */
    public function isSafeToRetryPublish(): bool
    {
        return match ($this) {
            self::RateLimited, self::MediaProcessing => true,
            self::Network, self::ServerError, self::AuthExpired, self::Validation,
            self::DuplicateContent, self::BillingRequired, self::Unsupported, self::Unknown => false,
        };
    }

    public function requiresPublishReconciliation(): bool
    {
        return match ($this) {
            self::Network, self::ServerError => true,
            default => false,
        };
    }
}
