<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Dto\NativeRead\NativePost;
use App\Dto\NativeRead\NativeReadCursor;
use App\Dto\Publishing\ReconciliationResult;
use App\Enums\Platform;
use App\Enums\PostFormat;
use App\Enums\PostTargetStatus;
use App\Models\PostTarget;
use App\Services\NativeRead\NativeReadConnectorRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Throwable;

final class PostTargetReconciler
{
    public function __construct(
        private readonly NativeReadConnectorRegistry $readers,
        private readonly TokenManager $tokens,
        private readonly PostStatusRollup $rollup,
    ) {}

    public function reconcile(PostTarget $target): ReconciliationResult
    {
        $target = $target->fresh(['account', 'post']) ?? $target;

        if (! ($target->error_kind?->requiresPublishReconciliation() ?? false)) {
            return ReconciliationResult::unavailable('This target does not have an ambiguous network/server publish outcome.');
        }

        if ($target->format === PostFormat::Story) {
            return ReconciliationResult::unavailable('Story reconciliation is intentionally conservative because the remote read surface does not expose enough stable text identity. Retry remains blocked.');
        }

        $expected = $this->expectedText($target);
        if ($expected === '') {
            return ReconciliationResult::unavailable('Captionless ambiguous publishes cannot be matched uniquely enough for an automatic retry decision. Retry remains blocked.');
        }

        $account = $target->account()->firstOrFail();

        try {
            $credentials = $this->tokens->fresh($account);
            $reader = $this->readers->for($target->platform);
            $result = $reader->fetchRecent(
                $account,
                new NativeReadCursor($this->watermark($target), null),
                $credentials,
            );
        } catch (Throwable $e) {
            return ReconciliationResult::unavailable('Remote reconciliation failed safely: '.$e->getMessage());
        }

        if (! $result->isOk()) {
            return ReconciliationResult::unavailable('Remote reconciliation is unavailable: '.($result->message ?? $result->status->value));
        }

        $matches = array_values(array_filter(
            $result->posts,
            fn (NativePost $post): bool => ! $post->isReply
                && ! $post->isRepost
                && $this->normalize($post->text, $target->platform) === $expected,
        ));

        if ($matches === []) {
            return ReconciliationResult::notFound();
        }

        if (count($matches) !== 1) {
            return ReconciliationResult::ambiguous(array_map(
                static fn (NativePost $post): string => $post->remoteId,
                $matches,
            ));
        }

        $match = $matches[0];

        $target->forceFill([
            'status' => PostTargetStatus::Published->value,
            'remote_id' => $match->remoteId,
            'remote_ids' => [$match->remoteId],
            'posted_at' => $target->posted_at ?? $match->createdAt,
            'error_kind' => null,
            'error_message' => null,
            'next_attempt_at' => null,
        ])->save();

        $this->rollup->recompute($target->post()->firstOrFail());

        return ReconciliationResult::reconciled($match->remoteId);
    }

    private function watermark(PostTarget $target): CarbonImmutable
    {
        $startedAt = $target->attemptLogs()->latest('attempt_no')->value('started_at');

        if ($startedAt !== null) {
            return CarbonImmutable::parse($startedAt)->subMinutes(2);
        }

        return CarbonImmutable::instance(Date::now())->subMinutes(30);
    }

    private function expectedText(PostTarget $target): string
    {
        $separator = $target->platform === Platform::LinkedIn ? "\n" : "\n\n";
        $text = implode($separator, array_values(array_filter(
            array_map(static fn (string $segment): string => trim($segment), $target->sections ?? []),
            static fn (string $segment): bool => $segment !== '',
        )));

        return $this->normalize($text, $target->platform);
    }

    private function normalize(string $text, Platform $platform): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));

        if ($platform === Platform::LinkedIn) {
            $text = preg_replace('/\\\\([\\\\|{}@\[\]()<>#*_~])/u', '$1', $text) ?? $text;
        }

        return preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    }
}
