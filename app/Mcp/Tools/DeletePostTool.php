<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PostStatus;
use App\Jobs\DeletePostTarget;
use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Safety\PostActionFingerprint;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Delete a post. A draft is removed permanently; a published post is also deleted from the connected accounts where possible. Irreversible. Requires confirm=true and the exact deletion_fingerprint returned by get_post.')]
class DeletePostTool extends WorkspaceTool
{
    public function handle(Request $request, PostActionFingerprint $fingerprints): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'deletion_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'confirm' => ['boolean'],
        ]);

        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        if ($denied = $this->authorize($request, 'delete', $post)) {
            return $denied;
        }

        $currentFingerprint = $fingerprints->delete($post);
        if (! hash_equals($currentFingerprint, (string) $validated['deletion_fingerprint'])) {
            return Response::error('Deletion fingerprint mismatch. The post or its remote targets changed after approval. Fetch the post again and obtain a new deletion approval.');
        }

        $hadBeenPublished = in_array($post->status, [PostStatus::Published, PostStatus::Partial, PostStatus::Failed], true);
        $consequence = $hadBeenPublished
            ? 'This will delete the exact fingerprinted post from its connected accounts where possible.'
            : 'This will permanently delete the exact fingerprinted draft.';

        if ($unconfirmed = $this->requireConfirmation($request, $consequence)) {
            return $unconfirmed;
        }

        $post->loadMissing('targets');

        if (! $hadBeenPublished) {
            $post->delete();

            return Response::text(json_encode([
                'deleted' => true,
                'remote' => false,
                'approved_fingerprint' => $currentFingerprint,
            ], JSON_THROW_ON_ERROR));
        }

        $post->targets
            ->filter(fn (PostTarget $t): bool => $t->remote_id !== null)
            ->each(fn (PostTarget $t) => DeletePostTarget::dispatch($t));

        $post->forceFill(['status' => PostStatus::Deleted->value, 'deleted_at' => now()])->save();

        return Response::text(json_encode([
            'deleted' => true,
            'remote' => true,
            'approved_fingerprint' => $currentFingerprint,
            'message' => 'Remote deletion queued for the approved published targets.',
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->description('Id of the post to delete.')->required(),
            'deletion_fingerprint' => $schema->string()->description('Exact SHA-256 deletion fingerprint returned by get_post for the post and its current remote targets.')->required(),
            'confirm' => $schema->boolean()->description('Must be true to delete.'),
        ];
    }
}
