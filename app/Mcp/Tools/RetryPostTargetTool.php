<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PostTargetStatus;
use App\Jobs\PublishPostTarget;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publishing\PostStatusRollup;
use App\Services\Safety\PostActionFingerprint;
use App\Support\PostView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Retry a failed or skipped publish target. Outward-facing. Requires confirm=true and the exact approval_fingerprint returned by get_post. Ambiguous network/server outcomes cannot be retried until reconciled remotely.')]
class RetryPostTargetTool extends WorkspaceTool
{
    public function handle(Request $request, PostStatusRollup $rollup, PostActionFingerprint $fingerprints): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'target_id' => ['required', 'string'],
            'approval_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'confirm' => ['boolean'],
        ]);

        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        if ($denied = $this->authorize($request, 'update', $post)) {
            return $denied;
        }

        // Scope the target to this post (and thus this workspace).
        $target = PostTarget::query()->whereKey($validated['target_id'])->where('post_id', $post->id)->first();
        if ($target === null) {
            return Response::error('No such target on that post.');
        }

        if (! $target->status->isRetryable()) {
            return Response::error('Only failed or skipped targets can be retried.');
        }

        if ($target->error_kind?->requiresPublishReconciliation()) {
            return Response::error('Retry blocked: the previous network/server outcome is ambiguous. Reconcile the remote platform first; blind retry could create a duplicate.');
        }

        $currentFingerprint = $fingerprints->publish($post);
        if (! hash_equals($currentFingerprint, (string) $validated['approval_fingerprint'])) {
            return Response::error('Approval fingerprint mismatch. Fetch the post again and obtain a new approval before retrying.');
        }

        if ($unconfirmed = $this->requireConfirmation($request, 'This will re-attempt publishing the exact fingerprinted content to the connected account.')) {
            return $unconfirmed;
        }

        $target->forceFill([
            'status' => PostTargetStatus::Pending->value,
            'error_kind' => null,
            'error_message' => null,
            'next_attempt_at' => null,
        ])->save();

        PublishPostTarget::dispatch($target);
        $rollup->recompute($post);

        return Response::text(json_encode([
            'status' => 'queued',
            'approved_fingerprint' => $currentFingerprint,
            'post' => PostView::make($post->fresh(['targets.account', 'media'])),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->description('Post id.')->required(),
            'target_id' => $schema->string()->description('Failed or skipped target id.')->required(),
            'approval_fingerprint' => $schema->string()->description('Exact SHA-256 approval fingerprint returned by get_post for the content, media, and destinations being retried.')->required(),
            'confirm' => $schema->boolean()->description('Must be true to retry.'),
        ];
    }
}
