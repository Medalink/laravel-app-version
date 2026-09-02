<?php

namespace Medalink\AppVersion\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Medalink\AppVersion\AppVersion;
use Medalink\AppVersion\ReleaseNotes\ReleaseNotesPublisher;
use Throwable;

class PublishReleaseNotesCommand extends Command
{
    protected $signature = 'app:release-notes:publish
        {--strict : Fail instead of falling back when generation errors occur}
        {--from-version= : Override the base version used to collect commits for this release}';

    protected $description = 'Generate and publish release notes for the current app version';

    public function handle(ReleaseNotesPublisher $publisher): int
    {
        $version = AppVersion::version();
        $strict = (bool) $this->option('strict');
        $fromVersion = $this->option('from-version');
        $fromVersion = is_string($fromVersion) && $fromVersion !== '' ? $fromVersion : null;

        try {
            $release = $publisher->publish($version, $strict, $fromVersion);
        } catch (Throwable $e) {
            Log::warning('Release note publishing failed', [
                'version' => $version,
                'error' => $e->getMessage(),
            ]);

            if ($strict) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->warn("Release note publishing failed for {$version}: {$e->getMessage()}; continuing without blocking deployment.");

            return self::SUCCESS;
        }

        $this->info("Published release notes for {$release->version}.");
        $this->line("Mode: {$release->generation_mode}");
        $this->line('Items: '.number_format($release->item_count));

        return self::SUCCESS;
    }
}
