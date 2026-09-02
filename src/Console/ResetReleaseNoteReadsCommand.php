<?php

namespace Medalink\AppVersion\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Medalink\AppVersion\Concerns\HasReleaseNoteReadState;

/**
 * Clears stored read state so QA can replay the "new release" experience.
 * The user model must use {@see HasReleaseNoteReadState}.
 */
class ResetReleaseNoteReadsCommand extends Command
{
    protected $signature = 'app:release-notes:reset-user
        {user? : User ID or email}
        {--all : Reset release note read state for all users}';

    protected $description = 'Clear stored release note read state for QA and testing';

    public function handle(): int
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        if ((bool) $this->option('all')) {
            $count = 0;

            $model::query()->chunkById(100, function ($users) use (&$count): void {
                foreach ($users as $user) {
                    $user->clearReleaseNoteReadState();
                    $count++;
                }
            });

            $this->info('Reset release note read state for '.number_format($count).' users.');

            return self::SUCCESS;
        }

        $identifier = $this->argument('user');

        if (! is_string($identifier) || $identifier === '') {
            $this->error('Provide a user ID/email or use --all.');

            return self::FAILURE;
        }

        $user = str_contains($identifier, '@')
            ? $model::query()->where('email', $identifier)->first()
            : $model::query()->find($identifier);

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $user->clearReleaseNoteReadState();

        $this->info("Reset release note read state for {$identifier}.");

        return self::SUCCESS;
    }
}
