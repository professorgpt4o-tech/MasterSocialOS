<?php

declare(strict_types=1);

use App\Dto\NativeRead\NativeReadCursor;
use App\Enums\MetricsStatus;
use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Services\NativeRead\Connectors\LinkedInNativeReadConnector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

test('LinkedIn native read returns recent posts authored by the connected organization', function (): void {
    $created = CarbonImmutable::parse('2026-10-06T12:00:00Z');
    Http::fake([
        'api.linkedin.com/rest/posts*' => Http::response([
            'elements' => [
                [
                    'id' => 'urn:li:share:123',
                    'author' => 'urn:li:organization:2414183',
                    'commentary' => 'Free Energy post',
                    'createdAt' => $created->getTimestampMs(),
                    'publishedAt' => $created->getTimestampMs(),
                    'lifecycleState' => 'PUBLISHED',
                    'content' => ['media' => ['id' => 'urn:li:image:abc']],
                ],
                [
                    'id' => 'urn:li:share:other',
                    'author' => 'urn:li:organization:999',
                    'commentary' => 'Not ours',
                    'createdAt' => $created->getTimestampMs(),
                    'lifecycleState' => 'PUBLISHED',
                ],
            ],
            'paging' => ['links' => []],
        ], 200),
    ]);

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::LinkedIn,
        'remote_account_id' => '2414183',
        'capabilities' => ['linkedin_account_type' => 'organization'],
    ]);

    $result = app(LinkedInNativeReadConnector::class)->fetchRecent(
        $account,
        new NativeReadCursor($created->subHour(), null),
        ['access_token' => 'tok'],
    );

    expect($result->status)->toBe(MetricsStatus::Ok)
        ->and($result->posts)->toHaveCount(1)
        ->and($result->posts[0]->remoteId)->toBe('urn:li:share:123')
        ->and($result->posts[0]->text)->toBe('Free Energy post')
        ->and($result->newestRemoteId)->toBe('urn:li:share:123');

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.linkedin.com/rest/posts?')
        && str_contains($request->url(), 'q=author')
        && str_contains(rawurldecode($request->url()), 'author=urn:li:organization:2414183')
        && $request->hasHeader('Authorization', 'Bearer tok')
        && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0')
        && $request->hasHeader('X-RestLi-Method', 'FINDER'));
});

test('LinkedIn native read maps 429 to rate limited', function (): void {
    Http::fake(['api.linkedin.com/rest/posts*' => Http::response('slow down', 429)]);

    $account = ConnectedAccount::factory()->create([
        'platform' => Platform::LinkedIn,
        'remote_account_id' => 'PERSON1',
    ]);

    $result = app(LinkedInNativeReadConnector::class)->fetchRecent(
        $account,
        new NativeReadCursor(CarbonImmutable::parse('2026-10-06T00:00:00Z'), null),
        ['access_token' => 'tok'],
    );

    expect($result->status)->toBe(MetricsStatus::RateLimited);
});
