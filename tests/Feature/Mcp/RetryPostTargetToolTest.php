<?php

use App\Enums\ErrorKind;
use App\Enums\PostTargetStatus;
use App\Mcp\Servers\ShoutrrrServer;
use App\Mcp\Tools\RetryPostTargetTool;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Safety\PostActionFingerprint;
use Illuminate\Support\Facades\Queue;

test('retry_post_target requires confirmation', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create();
    $target = PostTarget::factory()->for($post)->failed()->create(['error_kind' => ErrorKind::Validation->value]);

    $response = ShoutrrrServer::actingAs($user)->tool(RetryPostTargetTool::class, [
        'post_id' => $post->id,
        'target_id' => $target->id,
        'approval_fingerprint' => app(PostActionFingerprint::class)->publish($post->fresh(['targets.placements', 'media'])),
    ]);

    $response->assertHasErrors();
    expect($target->fresh()->status)->toBe(PostTargetStatus::Failed);
});

test('retry_post_target with matching fingerprint and confirm resets safe target to pending', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create();
    $target = PostTarget::factory()->for($post)->failed()->create(['error_kind' => ErrorKind::Validation->value]);
    $fingerprint = app(PostActionFingerprint::class)->publish($post->fresh(['targets.placements', 'media']));

    $response = ShoutrrrServer::actingAs($user)->tool(RetryPostTargetTool::class, [
        'post_id' => $post->id,
        'target_id' => $target->id,
        'approval_fingerprint' => $fingerprint,
        'confirm' => true,
    ]);

    $response->assertOk();
    expect($target->fresh()->status)->toBe(PostTargetStatus::Pending);
});

test('retry_post_target blocks ambiguous network outcomes even when confirmed', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create();
    $target = PostTarget::factory()->for($post)->failed()->create(['error_kind' => ErrorKind::Network->value]);
    $fingerprint = app(PostActionFingerprint::class)->publish($post->fresh(['targets.placements', 'media']));

    $response = ShoutrrrServer::actingAs($user)->tool(RetryPostTargetTool::class, [
        'post_id' => $post->id,
        'target_id' => $target->id,
        'approval_fingerprint' => $fingerprint,
        'confirm' => true,
    ]);

    $response->assertHasErrors();
    expect($target->fresh()->status)->toBe(PostTargetStatus::Failed);
    Queue::assertNothingPushed();
});

test('retry_post_target rejects a non-failed target', function (): void {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    bindTokenToWorkspace($user, $workspace);

    $post = Post::factory()->for($workspace)->create();
    $target = PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Published->value]);

    $response = ShoutrrrServer::actingAs($user)->tool(RetryPostTargetTool::class, [
        'post_id' => $post->id,
        'target_id' => $target->id,
        'approval_fingerprint' => app(PostActionFingerprint::class)->publish($post->fresh(['targets.placements', 'media'])),
        'confirm' => true,
    ]);

    $response->assertHasErrors();
    expect($target->fresh()->status)->toBe(PostTargetStatus::Published);
});
