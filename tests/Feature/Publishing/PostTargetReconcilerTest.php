<?php

declare(strict_types=1);

use App\Enums\ErrorKind;
use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\ConnectedAccount;
use App\Models\ConnectedAccountSecret;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Publishing\PostTargetReconciler;
use Illuminate\Support\Facades\Http;

function ambiguousInstagramTarget(string $caption = 'Exact copy'): PostTarget
{
    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::Instagram,
        'remote_account_id' => 'ig-123',
        'token_expires_at' => null,
    ]);
    ConnectedAccountSecret::factory()->create([
        'connected_account_id' => $account->id,
        'access_token' => 'tok',
    ]);
    $post = Post::factory()->create(['base_text' => $caption]);

    return PostTarget::factory()->for($post)->create([
        'connected_account_id' => $account->id,
        'platform' => Platform::Instagram,
        'sections' => [$caption],
        'status' => PostTargetStatus::Failed->value,
        'error_kind' => ErrorKind::Network->value,
        'error_message' => 'connection dropped',
    ]);
}

test('reconciler binds one exact remote match instead of retrying the write', function (): void {
    $target = ambiguousInstagramTarget();
    Http::fake([
        'graph.facebook.com/*/ig-123/media*' => Http::response([
            'data' => [[
                'id' => 'remote-ig-1',
                'caption' => 'Exact copy',
                'media_type' => 'IMAGE',
                'media_url' => 'https://example.test/image.jpg',
                'timestamp' => now()->toIso8601String(),
            ]],
        ], 200),
    ]);

    $result = app(PostTargetReconciler::class)->reconcile($target);

    expect($result->status)->toBe('reconciled')
        ->and($result->remoteId)->toBe('remote-ig-1')
        ->and($target->fresh()->status)->toBe(PostTargetStatus::Published)
        ->and($target->fresh()->remote_id)->toBe('remote-ig-1')
        ->and($target->fresh()->error_kind)->toBeNull();
});

test('reconciler never treats no match as permission to retry', function (): void {
    $target = ambiguousInstagramTarget();
    Http::fake([
        'graph.facebook.com/*/ig-123/media*' => Http::response(['data' => []], 200),
    ]);

    $result = app(PostTargetReconciler::class)->reconcile($target);

    expect($result->status)->toBe('not_found')
        ->and($target->fresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($target->fresh()->error_kind)->toBe(ErrorKind::Network)
        ->and($target->fresh()->remote_id)->toBeNull();
});

test('reconciler refuses to guess when multiple remote posts match', function (): void {
    $target = ambiguousInstagramTarget();
    $row = [
        'caption' => 'Exact copy',
        'media_type' => 'IMAGE',
        'media_url' => 'https://example.test/image.jpg',
        'timestamp' => now()->toIso8601String(),
    ];
    Http::fake([
        'graph.facebook.com/*/ig-123/media*' => Http::response([
            'data' => [
                ['id' => 'remote-ig-1', ...$row],
                ['id' => 'remote-ig-2', ...$row],
            ],
        ], 200),
    ]);

    $result = app(PostTargetReconciler::class)->reconcile($target);

    expect($result->status)->toBe('ambiguous')
        ->and($result->candidateRemoteIds)->toBe(['remote-ig-1', 'remote-ig-2'])
        ->and($target->fresh()->status)->toBe(PostTargetStatus::Failed)
        ->and($target->fresh()->remote_id)->toBeNull();
});
