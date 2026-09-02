<?php

use Medalink\AppVersion\Models\ReleaseNote;
use Medalink\AppVersion\Models\ReleaseNoteRead;
use Medalink\AppVersion\ReleaseNotes\ReleaseNotesFeed;
use Medalink\AppVersion\Tests\Fixtures\User;

function feed(): ReleaseNotesFeed
{
    return app(ReleaseNotesFeed::class);
}

it('describes nothing for guests or before any release is published', function (): void {
    expect(feed()->describe(null))->toMatchArray(['current' => null, 'unreadCount' => 0, 'showBanner' => false, 'releases' => []]);

    $user = User::make();

    expect(feed()->describe($user)['current'])->toBeNull()
        ->and($user->releaseNoteReadState())->toBe([]);
});

it('bootstraps existing users to the previous release so the current one shows once', function (): void {
    ReleaseNote::factory()->create(['version' => '3.5.0']);
    ReleaseNote::factory()->create(['version' => '3.6.0', 'previous_version' => '3.5.0', 'headline' => 'Fresh']);
    $this->writeVersionJson('3.6.0');
    $user = User::make();

    $described = feed()->describe($user);

    expect($described['current']['headline'])->toBe('Fresh')
        ->and($described['showBanner'])->toBeTrue()
        ->and($described['unreadCount'])->toBe(1)
        ->and($described['title'])->toBe('Updates since you last logged in')
        ->and($described['releases'])->toHaveCount(1)
        ->and($described['releases'][0]['version'])->toBe('3.6.0')
        ->and($user->releaseNoteReadState())->toMatchArray([
            ReleaseNoteRead::LAST_SEEN_VERSION => '3.5.0',
            ReleaseNoteRead::LAST_PROMPTED_VERSION => '3.5.0',
        ]);
});

it('dismisses the banner without marking the release as read', function (): void {
    ReleaseNote::factory()->create(['version' => '3.5.0']);
    ReleaseNote::factory()->create(['version' => '3.6.0', 'previous_version' => '3.5.0']);
    $this->writeVersionJson('3.6.0');
    $user = User::make();

    feed()->dismiss($user);
    $described = feed()->describe($user);

    expect($described['showBanner'])->toBeFalse()
        ->and($described['unreadCount'])->toBe(1)
        ->and($user->releaseNoteReadState())->toMatchArray([
            ReleaseNoteRead::LAST_SEEN_VERSION => '3.5.0',
            ReleaseNoteRead::LAST_PROMPTED_VERSION => '3.6.0',
        ]);
});

it('marks the latest visible release as read and then shows recent history on demand', function (): void {
    ReleaseNote::factory()->create(['version' => '3.4.0']);
    ReleaseNote::factory()->create(['version' => '3.5.0', 'previous_version' => '3.4.0']);
    ReleaseNote::factory()->create(['version' => '3.6.0', 'previous_version' => '3.5.0']);
    $this->writeVersionJson('3.6.0');
    $user = User::make();
    $user->markReleaseNotesRead('3.4.0');

    $before = feed()->describe($user);
    feed()->markAsRead($user);
    $after = feed()->describe($user);

    expect(collect($before['releases'])->pluck('version')->all())->toBe(['3.6.0', '3.5.0'])
        ->and($before['unreadCount'])->toBe(2)
        ->and($after['unreadCount'])->toBe(0)
        ->and($after['showBanner'])->toBeFalse()
        ->and($after['title'])->toBe('Latest Sample update')
        ->and(collect($after['releases'])->pluck('version')->all())->toBe(['3.6.0'])
        ->and($user->releaseNoteReadState()[ReleaseNoteRead::LAST_SEEN_VERSION])->toBe('3.6.0');
});

it('caps the visible releases by count and summary items and reports the remainder', function (): void {
    config()->set('app-version.release_notes.limits.max_items_in_modal', 4);
    $sections = [ReleaseNote::SECTION_NEW => ['a', 'b', 'c']];

    foreach (['3.1.0', '3.2.0', '3.3.0', '3.4.0'] as $version) {
        ReleaseNote::factory()->create(['version' => $version, 'summary_sections' => $sections, 'sections' => $sections, 'item_count' => 3]);
    }

    $this->writeVersionJson('3.4.0');
    $user = User::make();
    $user->markReleaseNotesRead('3.0.0');

    $described = feed()->describe($user);

    expect(collect($described['releases'])->pluck('version')->all())->toBe(['3.4.0'])
        ->and($described['hiddenCount'])->toBe(3)
        ->and($described['unreadCount'])->toBe(4);
});

it('resets read state through the artisan command by email, id, or for everyone', function (): void {
    ReleaseNote::factory()->create(['version' => '1.0.0']);
    $first = User::make('first@example.test');
    $second = User::make('second@example.test');
    $first->markReleaseNotesRead('1.0.0');
    $second->markReleaseNotesRead('1.0.0');

    $this->artisan('app:release-notes:reset-user', ['user' => 'first@example.test'])->assertSuccessful();

    expect($first->fresh()?->releaseNoteReadState())->toBe([])
        ->and($second->fresh()?->releaseNoteReadState())->not->toBe([]);

    $this->artisan('app:release-notes:reset-user', ['user' => (string) $second->getKey()])->assertSuccessful();

    expect($second->fresh()?->releaseNoteReadState())->toBe([]);

    $first->markReleaseNotesRead('1.0.0');
    $this->artisan('app:release-notes:reset-user', ['--all' => true])->assertSuccessful();

    expect(ReleaseNoteRead::query()->count())->toBe(0);

    $this->artisan('app:release-notes:reset-user')->assertFailed();
    $this->artisan('app:release-notes:reset-user', ['user' => 'missing@example.test'])->assertFailed();
});
