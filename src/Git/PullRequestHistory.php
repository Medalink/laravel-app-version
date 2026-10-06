<?php

namespace Medalink\AppVersion\Git;

/**
 * Splits one `git log --topo-order` listing of a release range into pull
 * requests, all in memory.
 *
 * The mainline is the first-parent chain from the range's tip. Each merge on
 * it brings in the first-parent chain of its second parent, up to the first
 * commit something older already claimed (or the edge of the range). Merges
 * are taken oldest first, so a branch cut from another unmerged branch does
 * not swallow the commits its base merged later. Inside a pull request the
 * same walk repeats, so a release branch or a stack surfaces every pull
 * request it carried as its own change; the carrier keeps only the commits
 * made on it directly.
 *
 * @phpstan-import-type Change from \Medalink\AppVersion\Contracts\ReleaseChangeSource
 *
 * @phpstan-type Commit array{parents: list<string>, subject: string, body: string}
 */
class PullRequestHistory
{
    /** `%H%x1f%P%x1f%s%x1f%b%x1e`: fields split by US, records by RS. */
    public const string FORMAT = '%H%x1f%P%x1f%s%x1f%b%x1e';

    private const string PULL_REQUEST_SUBJECT = '/^Merge pull request #(?<number>\d+) from (?<ref>\S+)/';

    /** @var array<string, Commit> */
    private array $commits = [];

    /** @var array<string, true> */
    private array $claimed = [];

    /** @var list<Change> */
    private array $changes = [];

    /**
     * @return list<Change> newest first
     */
    public function changes(string $log): array
    {
        $this->commits = [];
        $this->claimed = [];
        $this->changes = [];
        $head = null;

        foreach (explode("\x1e", $log) as $record) {
            $record = ltrim($record, "\r\n");

            if ($record === '') {
                continue;
            }

            [$hash, $parents, $subject, $body] = explode("\x1f", $record, 4) + ['', '', '', ''];

            if ($hash === '') {
                continue;
            }

            $this->commits[$hash] = [
                'parents' => array_values(array_filter(explode(' ', trim($parents)))),
                'subject' => trim($subject),
                'body' => trim($body),
            ];
            $head ??= $hash;
        }

        if ($head !== null) {
            $this->collect($this->chain($head), null);
        }

        return array_reverse($this->changes);
    }

    /**
     * Claim the unclaimed first-parent chain from $start, newest first.
     *
     * @return list<string>
     */
    private function chain(string $start): array
    {
        $chain = [];

        for ($hash = $start; isset($this->commits[$hash]) && ! isset($this->claimed[$hash]); $hash = $this->commits[$hash]['parents'][0] ?? '') {
            $this->claimed[$hash] = true;
            $chain[] = $hash;
        }

        return $chain;
    }

    /**
     * @param  list<string>  $chain  newest first
     * @param  int|null  $owner  index of the pull request the chain belongs to; null on the mainline
     */
    private function collect(array $chain, ?int $owner): void
    {
        foreach (array_reverse($chain) as $hash) {
            $commit = $this->commits[$hash];

            if (count($commit['parents']) < 2) {
                if ($owner === null) {
                    $this->changes[] = ['title' => $commit['subject'], 'branch' => null, 'number' => null, 'details' => []];
                } elseif ($commit['subject'] !== '') {
                    $this->changes[$owner]['details'][] = $commit['subject'];
                }

                continue;
            }

            $side = $this->chain($commit['parents'][1]);
            $pullRequest = $this->pullRequest($commit);

            // A plain merge ("Merge main into feature") adds nothing of its
            // own; what it carried belongs to the caller.
            if ($pullRequest === null) {
                $this->collect($side, $owner);

                continue;
            }

            $this->changes[] = $pullRequest;
            $this->collect($side, array_key_last($this->changes));
        }
    }

    /**
     * @param  Commit  $commit
     * @return Change|null
     */
    private function pullRequest(array $commit): ?array
    {
        if (! preg_match(self::PULL_REQUEST_SUBJECT, $commit['subject'], $match)) {
            return null;
        }

        // "owner/branch/with/slashes": the owner is the first segment.
        $branch = str_contains($match['ref'], '/') ? substr($match['ref'], strpos($match['ref'], '/') + 1) : $match['ref'];
        $title = trim(strtok($commit['body'], "\n") ?: '');

        return [
            'title' => $title !== '' ? $title : trim((string) preg_replace('/[-_\/]+/', ' ', $branch)),
            'branch' => $branch,
            'number' => (int) $match['number'],
            'details' => [],
        ];
    }
}
