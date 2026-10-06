<?php

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\PublishPostTool;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Safety\PostActionFingerprint;
use Illuminate\Support\Facades\Queue;

test('publish_post_now requires confirmation', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);
    $post = Post::factory()->for($workspace)->create();

    $response = ShoutrrrServer::actingAs($user)->tool(PublishPostTool::class, [
        'post_id' => $post->id,
        'approval_fingerprint' => app(PostActionFingerprint::class)->publish($post),
    ]);

    $response->assertHasErrors();
    expect($post->fresh()->status)->not->toBe(PostStatus::Publishing);
});

test('publish_post_now with matching fingerprint and confirm sets status to publishing', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create();
    PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Pending->value]);
    $fingerprint = app(PostActionFingerprint::class)->publish($post->fresh(['targets.placements', 'media']));

    $response = ShoutrrrServer::actingAs($user)->tool(PublishPostTool::class, [
        'post_id' => $post->id,
        'approval_fingerprint' => $fingerprint,
        'confirm' => true,
    ]);

    $response->assertOk();
    expect($post->fresh()->status)->toBe(PostStatus::Publishing);
});

test('publish_post_now refuses a stale approval fingerprint after content changes', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create(['base_text' => 'Approved text']);
    PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Pending->value]);
    $fingerprint = app(PostActionFingerprint::class)->publish($post->fresh(['targets.placements', 'media']));

    $post->forceFill(['base_text' => 'Changed after approval'])->save();

    $response = ShoutrrrServer::actingAs($user)->tool(PublishPostTool::class, [
        'post_id' => $post->id,
        'approval_fingerprint' => $fingerprint,
        'confirm' => true,
    ]);

    $response->assertHasErrors();
    expect($post->fresh()->status)->not->toBe(PostStatus::Publishing);
});
