<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\WorkspaceTool;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publishing\PostTargetReconciler;
use App\Support\PostView;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Reconcile an ambiguous failed publish against the remote platform without issuing a new publish. If exactly one matching remote post is found, binds its remote id and marks the target published. Never retries a write.')]
class ReconcilePostTargetTool extends WorkspaceTool
{
    public function handle(Request $request, PostTargetReconciler $reconciler): Response
    {
        if ($this->bindWorkspace($request) === null) {
            return Response::error('This connection is not bound to a workspace. Reconnect and select a workspace.');
        }

        $validated = $request->validate([
            'post_id' => ['required', 'string'],
            'target_id' => ['required', 'string'],
        ]);

        $post = Post::query()->whereKey($validated['post_id'])->first();
        if ($post === null) {
            return Response::error('No post with that id exists in this workspace.');
        }

        if ($denied = $this->authorize($request, 'update', $post)) {
            return $denied;
        }

        $target = PostTarget::query()
            ->whereKey($validated['target_id'])
            ->where('post_id', $post->id)
            ->first();

        if ($target === null) {
            return Response::error('No such target on that post.');
        }

        $result = $reconciler->reconcile($target);

        return Response::text(json_encode([
            'status' => $result->status,
            'remote_id' => $result->remoteId,
            'candidate_remote_ids' => $result->candidateRemoteIds,
            'message' => $result->message,
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
            'target_id' => $schema->string()->description('Target id with an ambiguous network/server publish outcome.')->required(),
        ];
    }
}
