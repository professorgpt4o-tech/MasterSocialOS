<?php

declare(strict_types=1);

namespace App\Dto\Publishing;

final readonly class ReconciliationResult
{
    /**
     * @param  list<string>  $candidateRemoteIds
     */
    private function __construct(
        public string $status,
        public ?string $remoteId,
        public array $candidateRemoteIds,
        public string $message,
    ) {}

    public static function reconciled(string $remoteId): self
    {
        return new self('reconciled', $remoteId, [$remoteId], 'A unique matching remote post was found and bound to the target.');
    }

    public static function notFound(string $message = 'No matching remote post was found. Retry remains blocked because absence is not proof that the previous write did not succeed.'): self
    {
        return new self('not_found', null, [], $message);
    }

    /** @param list<string> $remoteIds */
    public static function ambiguous(array $remoteIds, string $message = 'Multiple matching remote posts were found. No automatic state change was made.'): self
    {
        return new self('ambiguous', null, array_values($remoteIds), $message);
    }

    public static function unavailable(string $message): self
    {
        return new self('unavailable', null, [], $message);
    }
}
