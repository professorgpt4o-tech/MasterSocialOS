<?php

declare(strict_types=1);

namespace App\Services\NativeRead\Connectors;

use App\Dto\NativeRead\NativePost;
use App\Dto\NativeRead\NativeReadCursor;
use App\Dto\NativeRead\RecentPostsResult;
use App\Models\ConnectedAccount;
use App\Services\NativeRead\Contracts\NativeReadConnector;
use App\Services\Publishing\Connectors\LinkedInConnector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;

class LinkedInNativeReadConnector implements NativeReadConnector
{
    private const string POSTS_URL = 'https://api.linkedin.com/rest/posts';

    public function __construct(private readonly HttpFactory $http) {}

    public function fetchRecent(ConnectedAccount $account, NativeReadCursor $cursor, array $credentials): RecentPostsResult
    {
        $token = (string) ($credentials['access_token'] ?? '');
        if ($token === '') {
            return RecentPostsResult::failed('LinkedIn access token unavailable.');
        }

        $author = $account->linkedInAuthorUrn();

        try {
            $response = $this->http
                ->timeout(10)
                ->connectTimeout(5)
                ->withToken($token)
                ->withHeaders([
                    'LinkedIn-Version' => (string) config('services.linkedin-openid.api_version', LinkedInConnector::DEFAULT_VERSION),
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'X-RestLi-Method' => 'FINDER',
                ])
                ->acceptJson()
                ->get(self::POSTS_URL, [
                    'author' => $author,
                    'q' => 'author',
                    'count' => 50,
                    'sortBy' => 'CREATED',
                    'viewContext' => 'AUTHOR',
                ]);
        } catch (ConnectionException $e) {
            return RecentPostsResult::failed($e->getMessage());
        }

        if ($response->failed()) {
            return $response->status() === 429
                ? RecentPostsResult::rateLimited($response->body())
                : RecentPostsResult::failed($response->body());
        }

        $posts = [];
        $newest = null;

        foreach ((array) $response->json('elements', []) as $row) {
            $id = (string) ($row['id'] ?? '');
            $createdAtMs = (int) ($row['createdAt'] ?? $row['publishedAt'] ?? 0);
            if ($id === '' || $createdAtMs <= 0) {
                continue;
            }

            $createdAt = Carbon::createFromTimestampUTC(intdiv($createdAtMs, 1000))->toImmutable();
            if ($createdAt < $cursor->watermark) {
                continue;
            }

            if ((string) ($row['author'] ?? '') !== $author) {
                continue;
            }

            if (($row['lifecycleState'] ?? 'PUBLISHED') !== 'PUBLISHED') {
                continue;
            }

            $newest ??= $id;
            $posts[] = new NativePost(
                $id,
                (string) ($row['commentary'] ?? ''),
                $createdAt,
                [],
                isset($row['reshareContext']),
                isset($row['reshareContext']),
            );
        }

        return RecentPostsResult::ok($posts, $newest);
    }
}
