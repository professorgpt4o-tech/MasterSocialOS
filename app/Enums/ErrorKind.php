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

    /**
     * Safe automatic retry policy for outward-facing mutations.
     *
     * A network failure or 5xx response is an ambiguous outcome: the provider may
     * have accepted the mutation before the response was lost. Retrying it blindly
     * can duplicate a post, reply, or repost. Those outcomes must be reconciled
     * against the remote platform before another write is allowed.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::RateLimited, self::MediaProcessing => true,
            self::Network, self::ServerError, self::AuthExpired, self::Validation,
            self::DuplicateContent, self::BillingRequired, self::Unsupported, self::Unknown => false,
        };
    }

    public function isSafeToRetryPublish(): bool
    {
        return $this->isRetryable();
    }

    public function requiresPublishReconciliation(): bool
    {
        return match ($this) {
            self::Network, self::ServerError => true,
            default => false,
        };
    }
}
