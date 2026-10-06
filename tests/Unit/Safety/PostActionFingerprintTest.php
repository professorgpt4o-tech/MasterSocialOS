<?php

use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Safety\PostActionFingerprint;

test('publish fingerprint is deterministic for unchanged post state', function (): void {
    $post = Post::factory()->create(['base_text' => 'Exact approved copy']);
    PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Pending->value]);
    $service = app(PostActionFingerprint::class);

    $first = $service->publish($post->fresh(['targets.placements', 'media']));
    $second = $service->publish($post->fresh(['targets.placements', 'media']));

    expect($first)->toHaveLength(64)->and($second)->toBe($first);
});

test('publish fingerprint changes when copy changes', function (): void {
    $post = Post::factory()->create(['base_text' => 'Approved']);
    PostTarget::factory()->for($post)->create(['status' => PostTargetStatus::Pending->value]);
    $service = app(PostActionFingerprint::class);
    $approved = $service->publish($post->fresh(['targets.placements', 'media']));

    $post->forceFill(['base_text' => 'Changed'])->save();

    expect($service->publish($post->fresh(['targets.placements', 'media'])))->not->toBe($approved);
});

test('delete fingerprint changes when remote identity changes', function (): void {
    $post = Post::factory()->create();
    $target = PostTarget::factory()->for($post)->create([
        'status' => PostTargetStatus::Published->value,
        'remote_id' => 'remote-a',
    ]);
    $service = app(PostActionFingerprint::class);
    $approved = $service->delete($post->fresh(['targets.placements', 'media']));

    $target->forceFill(['remote_id' => 'remote-b'])->save();

    expect($service->delete($post->fresh(['targets.placements', 'media'])))->not->toBe($approved);
});
