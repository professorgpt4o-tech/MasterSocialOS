<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostMediaPlacement;
use App\Models\PostTarget;

final class PostActionFingerprint
{
    public function publish(Post $post): string
    {
        return $this->forAction($post, 'publish');
    }

    public function delete(Post $post): string
    {
        return $this->forAction($post, 'delete');
    }

    private function forAction(Post $post, string $action): string
    {
        $post->loadMissing(['targets.placements', 'media']);

        $payload = [
            'version' => 1,
            'action' => $action,
            'post' => [
                'id' => (string) $post->id,
                'workspace_id' => (string) $post->workspace_id,
                'base_text' => (string) $post->base_text,
                'segments' => $post->segments ?? [],
                'mentions' => $post->mentions ?? [],
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                'auto_repost' => $post->auto_repost,
                'skip_sync' => (bool) $post->skip_sync,
            ],
            'targets' => $post->targets
                ->sortBy(fn (PostTarget $target): string => (string) $target->id)
                ->map(fn (PostTarget $target): array => [
                    'id' => (string) $target->id,
                    'connected_account_id' => (string) $target->connected_account_id,
                    'platform' => $target->platform->value,
                    'sections' => $target->sections ?? [],
                    'content_override' => $target->content_override,
                    'auto_split' => (bool) $target->auto_split,
                    'format' => $target->format->value,
                    'remote_id' => $target->remote_id,
                    'remote_ids' => $target->remote_ids ?? [],
                    'placements' => $target->placements
                        ->sortBy(fn (PostMediaPlacement $placement): string => sprintf(
                            '%08d:%s:%s',
                            (int) $placement->position,
                            (string) $placement->segment_ref,
                            (string) $placement->post_media_id,
                        ))
                        ->map(fn (PostMediaPlacement $placement): array => [
                            'post_media_id' => (string) $placement->post_media_id,
                            'segment_ref' => (string) $placement->segment_ref,
                            'position' => (int) $placement->position,
                        ])->values()->all(),
                ])->values()->all(),
            'media' => $post->media
                ->sortBy(fn (PostMedia $media): string => (string) $media->id)
                ->map(fn (PostMedia $media): array => [
                    'id' => (string) $media->id,
                    'disk' => (string) $media->disk,
                    'path' => (string) $media->path,
                    'mime' => (string) $media->mime,
                    'size_bytes' => (int) $media->size_bytes,
                    'width' => $media->width,
                    'height' => $media->height,
                    'alt_text' => $media->alt_text,
                    'position' => (int) $media->position,
                ])->values()->all(),
        ];

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
