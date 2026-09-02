<?php

namespace Medalink\AppVersion\Console;

use Illuminate\Console\Command;
use Medalink\AppVersion\ReleaseNotes\ReleaseNotesPublisher;
use Throwable;

class BackfillReleaseNotesCommand extends Command
{
    protected $signature = 'app:release-notes:backfill
        {--from= : Oldest version to include}
        {--to= : Newest version to include}
        {--latest=5 : Backfill the latest N versions when no range is given}
        {--force : Persist generated release notes}
        {--strict : Fail instead of falling back when generation errors occur}';

    protected $description = 'Preview or backfill release notes for a range of versions';

    public function handle(ReleaseNotesPublisher $publisher): int
    {
        $versions = $this->resolveVersions($publisher->versionHistory());

        if ($versions === []) {
            $this->warn('No versions matched the requested backfill range.');

            return self::SUCCESS;
        }

        $rows = [];
        $strict = (bool) $this->option('strict');
        $force = (bool) $this->option('force');

        foreach ($versions as $version) {
            try {
                $payload = $force
                    ? $publisher->publish($version, $strict)->toArray()
                    : $publisher->payloadForVersion($version, $strict);
            } catch (Throwable $e) {
                if ($strict) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                $rows[] = [$version, 'error', '0', $e->getMessage()];

                continue;
            }

            $rows[] = [
                $version,
                (string) $payload['generation_mode'],
                number_format((int) $payload['item_count']),
                (string) ($payload['previous_version'] ?? '-'),
            ];
        }

        $this->table(['Version', 'Mode', 'Items', 'Previous'], $rows);

        if (! $force) {
            $this->line('Dry run only. Re-run with --force to write release notes.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{version: string, commit: string}>  $history
     * @return list<string>
     */
    protected function resolveVersions(array $history): array
    {
        $versions = array_values(array_column($history, 'version'));

        if ($versions === []) {
            return [];
        }

        $from = $this->option('from');
        $to = $this->option('to');

        if (! $from && ! $to) {
            $latest = max((int) $this->option('latest'), 1);

            return array_reverse(array_slice($versions, 0, $latest));
        }

        $fromIndex = $from ? array_search($from, $versions, true) : count($versions) - 1;
        $toIndex = $to ? array_search($to, $versions, true) : 0;

        if ($fromIndex === false || $toIndex === false) {
            return [];
        }

        $start = min($fromIndex, $toIndex);
        $length = abs($toIndex - $fromIndex) + 1;

        return array_reverse(array_slice($versions, $start, $length));
    }
}
