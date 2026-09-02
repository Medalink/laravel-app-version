<?php

namespace Medalink\AppVersion\ReleaseNotes;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Medalink\AppVersion\Models\ReleaseNote;

/**
 * Turns raw commit subjects into user-facing release note sections.
 *
 * Pipeline per subject: drop noise (ignore patterns), parse conventional
 * prefixes and breaking markers, tidy the text (refs, backticks, ticket
 * keys), classify into New / Improved / Fixed (type, then leading verb,
 * then fix-signal keywords), rewrite into the configured voice, de-duplicate,
 * then group by feature area against both the raw and rewritten text.
 *
 * @phpstan-type Sections array{new: list<string>, improved: list<string>, fixed: list<string>}
 * @phpstan-type FeatureGroup array{title: string, summary: string, sections: Sections, item_count: int}
 * @phpstan-type Compiled array{headline: string, summary: string, sections: Sections, summary_sections: Sections, feature_groups: list<FeatureGroup>, item_count: int, generation_mode: string, generation_warnings: list<string>}
 * @phpstan-type Parsed array{text: string, raw: string, type: string|null, scope: string|null, breaking: bool}
 */
class ReleaseNotesCompiler
{
    public const string GENERAL_GROUP_TITLE = 'General Improvements';

    public const string VOICE_PAST = 'past';

    public const string VOICE_IMPERATIVE = 'imperative';

    public const string BREAKING_PREFIX = 'Breaking change: ';

    /** @var list<string> base verbs that open a "something new" subject */
    private const array NEW_VERBS = [
        'add', 'introduce', 'create', 'implement', 'ship', 'launch', 'enable',
        'support', 'allow', 'provide', 'expose', 'offer', 'build', 'wire', 'bring',
        'install', 'new',
    ];

    /** @var list<string> base verbs that open a fix */
    private const array FIXED_VERBS = [
        'fix', 'resolve', 'correct', 'prevent', 'repair', 'stop', 'avoid', 'handle',
        'guard', 'restore', 'recover', 'patch', 'address', 'unbreak', 'harden',
        'protect', 'catch', 'tolerate', 'survive',
    ];

    /** @var list<string> base verbs that open an improvement */
    private const array IMPROVED_VERBS = [
        'improve', 'enhance', 'refine', 'standardize', 'standardise', 'update',
        'polish', 'make', 'move', 'rename', 'restyle', 'align', 'colour', 'color',
        'show', 'hide', 'drop', 'remove', 'replace', 'pin', 'size', 'resize', 'keep',
        'mark', 'put', 'send', 'narrow', 'widen', 'claim', 'give', 'apply', 'use',
        'simplify', 'clean', 'tidy', 'speed', 'reduce', 'increase', 'raise', 'lower',
        'tweak', 'adjust', 'change', 'switch', 'convert', 'migrate', 'upgrade',
        'refactor', 'extract', 'split', 'merge', 'unify', 'consolidate', 'streamline',
        'optimize', 'optimise', 'cache', 'defer', 'prefer', 'default', 'let', 'set',
        'turn', 'tighten', 'loosen', 'relax', 'expand', 'extend', 'shorten', 'trim',
        'strip', 'wrap', 'link', 'open', 'close', 'load', 'render', 'display',
        'surface', 'prune', 'retire', 'deprecate', 'disable', 'skip', 'ignore',
        'require', 'validate', 'verify', 'check', 'track', 'record', 'log', 'report',
        'persist', 'store', 'save', 'sync', 'share', 'pass', 'return', 'accept',
        'reject', 'respect', 'honor', 'honour', 'follow', 'match', 'sort', 'order',
        'filter', 'group', 'paginate', 'limit', 'cap', 'throttle', 'queue',
        'schedule', 'run', 'start', 'boot', 'wait', 'retry', 'rebuild', 'regenerate',
        'refresh', 'reset', 'clear', 'flush', 'warm', 'seed', 'generate', 'compute',
        'derive', 'bake', 'embed', 'inline', 'bundle', 'compile', 'publish', 'deploy',
        'release', 'tag', 'name', 'label', 'describe', 'document', 'explain',
        'clarify', 'announce', 'notify', 'prompt', 'ask', 'confirm', 'warn',
        'remember', 'forget', 'teach', 'treat', 'count', 'measure', 'scale',
        'stretch', 'shrink', 'grow', 'fill', 'pad', 'center', 'centre', 'stack',
        'collapse', 'fold', 'unfold', 'toggle', 'swap', 'flip', 'reverse', 'rotate',
        'animate', 'fade', 'highlight', 'dim', 'darken', 'lighten', 'brighten',
        'style', 'theme', 'format', 'indent', 'normalize', 'normalise', 'sanitize',
        'escape', 'encode', 'decode', 'parse', 'serialize', 'hash', 'sign', 'encrypt',
        'revoke', 'grant', 'restrict', 'scope', 'isolate', 'separate', 'decouple',
        'connect', 'disconnect', 'reconnect', 'attach', 'detach', 'mount', 'unmount',
        'register', 'unregister', 'bind', 'unbind', 'lift', 'hoist', 'promote',
        'demote', 'flatten', 'nest', 'inherit', 'override', 'bump', 'prefill',
        'preload', 'prefetch', 'autofocus', 'focus', 'blur', 'scroll', 'snap',
        'stick', 'float', 'anchor', 'position', 'place', 'lay', 'tune', 'calibrate',
        'wire', 'route', 'redirect', 'rewrite', 'write', 'read', 'fetch', 'poll',
        'stream', 'batch', 'chunk', 'debounce', 'memoize', 'memoise', 'reuse',
        'recycle', 'dedupe', 'deduplicate', 'unblock', 'speedup', 'accelerate',
        'coalesce', 'reorder', 'reorganize', 'reorganise', 'restructure', 'relocate',
        'repoint', 'reword', 'rephrase', 'shorten', 'lengthen', 'rebase', 'squash',
        'clip', 'crop', 'mask', 'clamp', 'pin', 'dock', 'undock', 'suppress', 'mute',
        'silence', 'quiet', 'tidy', 'declutter', 'compact', 'condense',
    ];

    /** @var array<string, string> irregular or double-consonant past tenses */
    private const array PAST_TENSE_EXCEPTIONS = [
        'make' => 'made', 'keep' => 'kept', 'put' => 'put', 'send' => 'sent',
        'give' => 'gave', 'show' => 'showed', 'hide' => 'hid', 'build' => 'built',
        'let' => 'let', 'set' => 'set', 'cut' => 'cut', 'split' => 'split',
        'bring' => 'brought', 'write' => 'wrote', 'rewrite' => 'rewrote', 'read' => 'read',
        'run' => 'ran', 'get' => 'got', 'teach' => 'taught', 'catch' => 'caught',
        'speed' => 'sped', 'feed' => 'fed', 'lead' => 'led', 'hold' => 'held',
        'bind' => 'bound', 'unbind' => 'unbound', 'find' => 'found', 'shrink' => 'shrank',
        'grow' => 'grew', 'forget' => 'forgot', 'lay' => 'laid', 'pay' => 'paid',
        'reset' => 'reset', 'inset' => 'inset', 'offset' => 'offset', 'prefer' => 'preferred',
        'defer' => 'deferred', 'refer' => 'referred', 'override' => 'overrode',
        'undo' => 'undid', 'redo' => 'redid', 'do' => 'did', 'go' => 'went', 'become' => 'became',
        'begin' => 'began', 'stick' => 'stuck', 'sit' => 'sat', 'spin' => 'spun',
        'strike' => 'struck', 'swing' => 'swung', 'throw' => 'threw', 'tear' => 'tore',
        'wear' => 'wore', 'win' => 'won', 'sweep' => 'swept', 'sleep' => 'slept',
        'leave' => 'left', 'lose' => 'lost', 'freeze' => 'froze', 'choose' => 'chose',
        'stand' => 'stood', 'understand' => 'understood', 'rise' => 'rose', 'fall' => 'fell',
        'forbid' => 'forbade', 'quit' => 'quit', 'shut' => 'shut', 'hit' => 'hit',
        'upset' => 'upset', 'broadcast' => 'broadcast', 'cost' => 'cost', 'hurt' => 'hurt',
        'unbreak' => 'unbroke', 'break' => 'broke', 'speak' => 'spoke', 'take' => 'took',
        'retake' => 'retook', 'see' => 'saw', 'mean' => 'meant', 'meet' => 'met',
        'shoot' => 'shot', 'shed' => 'shed', 'spread' => 'spread', 'sell' => 'sold',
        'tell' => 'told', 'think' => 'thought', 'buy' => 'bought', 'seek' => 'sought',
        'fight' => 'fought', 'draw' => 'drew', 'redraw' => 'redrew', 'blow' => 'blew',
        'know' => 'knew', 'fly' => 'flew', 'light' => 'lit', 'ride' => 'rode',
        'hang' => 'hung', 'stopgap' => 'stopgapped', 'new' => 'added',
    ];

    private const string FIX_SIGNALS = '/\b(bug|bugs|crash|crashes|crashing|broken|breaks|breaking|regression|regressions|flaky|flap|flapping|leak|leaks|leaking|race|races|false|wrong|wrongly|incorrect|incorrectly|missing|error|errors|fail|fails|failed|failing|failure|failures|typo|typos|stale|no longer|never again|exception|exceptions|deadlock|deadlocks|timeout|timeouts|hang|hangs|hanging|500s?|mismatch|mismatched|invalid|unexpected|undefined|null pointer|off-by-one|duplicate|duplicated|duplicates|lying|misleading|glitch|glitches)\b/i';

    /**
     * @param  list<string>  $subjects
     * @param  list<string>  $warnings
     * @return Compiled
     */
    public function compile(array $subjects, array $warnings = []): array
    {
        $sections = ReleaseNote::emptySections();
        $matchTexts = [];
        $seen = [];

        foreach ($subjects as $subject) {
            $parsed = $this->parse($subject);

            if ($parsed === null) {
                continue;
            }

            $section = $this->classify($parsed);
            $sentence = $this->render($parsed, $section);
            $key = $this->itemKey($sentence);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $sections[$section][] = $sentence;
            $matchTexts[$sentence] = $parsed['raw'];
        }

        $itemCount = count(Arr::flatten($sections));

        if ($itemCount === 0) {
            return $this->fallback($warnings);
        }

        $featureGroups = $this->featureGroups($sections, $matchTexts);

        return [
            'headline' => $this->buildHeadline($sections, $featureGroups),
            'summary' => $this->buildSummary($sections),
            'sections' => $sections,
            'summary_sections' => $this->summarySections($sections),
            'feature_groups' => $featureGroups,
            'item_count' => $itemCount,
            'generation_mode' => ReleaseNote::GENERATION_MODE_PARSED,
            'generation_warnings' => array_values($warnings),
        ];
    }

    /**
     * @param  list<string>  $warnings
     * @return Compiled
     */
    public function fallback(array $warnings = []): array
    {
        $sections = ReleaseNote::emptySections((array) $this->config('fallback.sections', []));

        return [
            'headline' => $this->fallbackHeadline(),
            'summary' => $this->fallbackSummary(),
            'sections' => $sections,
            'summary_sections' => $this->summarySections($sections),
            'feature_groups' => $this->featureGroups($sections),
            'item_count' => count(Arr::flatten($sections)),
            'generation_mode' => ReleaseNote::GENERATION_MODE_FALLBACK,
            'generation_warnings' => array_values($warnings),
        ];
    }

    public function fallbackHeadline(): string
    {
        $headline = $this->config('fallback.headline');

        return is_string($headline) && $headline !== ''
            ? $headline
            : $this->appName().' has been updated';
    }

    public function fallbackSummary(): string
    {
        $summary = $this->config('fallback.summary');

        return is_string($summary) && $summary !== ''
            ? $summary
            : 'This release includes stability improvements and general polish across the app.';
    }

    /**
     * @param  Sections  $sections
     * @return Sections
     */
    public function summarySections(array $sections): array
    {
        $limit = (int) $this->config('limits.per_section', 3);
        $capped = [];

        foreach (ReleaseNote::SECTIONS as $section) {
            $capped[$section] = array_slice($sections[$section] ?? [], 0, $limit);
        }

        return $capped;
    }

    /**
     * Group items by the first configured feature area whose pattern matches
     * either the rewritten sentence or the raw subject it came from.
     *
     * @param  Sections  $sections
     * @param  array<string, string>  $matchTexts  sentence => raw subject
     * @return list<FeatureGroup>
     */
    public function featureGroups(array $sections, array $matchTexts = []): array
    {
        $groups = [];

        foreach ((array) $this->config('feature_groups', []) as $group) {
            if (! is_array($group) || ! isset($group['title'])) {
                continue;
            }

            $groups[(string) $group['title']] = [
                'title' => (string) $group['title'],
                'short' => (string) ($group['short'] ?? $group['title']),
                'summary' => (string) ($group['summary'] ?? ''),
                'patterns' => $group['patterns'] ?? [],
                'sections' => ReleaseNote::emptySections(),
            ];
        }

        $groups[self::GENERAL_GROUP_TITLE] ??= [
            'title' => self::GENERAL_GROUP_TITLE,
            'short' => self::GENERAL_GROUP_TITLE,
            'summary' => 'Additional changes, fixes, and polish.',
            'patterns' => [],
            'sections' => ReleaseNote::emptySections(),
        ];

        foreach (ReleaseNote::SECTIONS as $section) {
            foreach ($sections[$section] ?? [] as $item) {
                $candidates = array_unique([$item, $matchTexts[$item] ?? $item]);
                $title = $this->featureGroupTitleFor($candidates, $groups) ?? self::GENERAL_GROUP_TITLE;
                $groups[$title]['sections'][$section][] = $item;
            }
        }

        return collect($groups)
            ->map(static function (array $group): array {
                unset($group['patterns']);
                $group['item_count'] = count(Arr::flatten($group['sections']));

                return $group;
            })
            ->filter(static fn (array $group): bool => $group['item_count'] > 0)
            ->values()
            ->all();
    }

    /**
     * Past tense of an imperative verb: exceptions first, then the regular
     * rules (e -> ed, consonant-y -> ied, single-syllable CVC doubles).
     */
    public static function pastTense(string $verb): string
    {
        $verb = strtolower($verb);

        if (isset(self::PAST_TENSE_EXCEPTIONS[$verb])) {
            return self::PAST_TENSE_EXCEPTIONS[$verb];
        }

        if (str_ends_with($verb, 'ed') || str_ends_with($verb, 'e')) {
            return str_ends_with($verb, 'ed') ? $verb : $verb.'d';
        }

        if (preg_match('/[^aeiou]y$/', $verb)) {
            return substr($verb, 0, -1).'ied';
        }

        // Single-syllable consonant-vowel-consonant verbs double the final
        // consonant (stop -> stopped, pin -> pinned) but not after a vowel
        // pair (wait -> waited) or a final w/x/y (narrow -> narrowed).
        $singleSyllable = preg_match_all('/[aeiouy]+/', $verb) === 1;

        if ($singleSyllable && preg_match('/[^aeiou][aeiou][^aeiouwxy]$/', $verb)) {
            return $verb.substr($verb, -1).'ed';
        }

        return $verb.'ed';
    }

    /**
     * @param  list<string>  $candidates
     * @param  array<string, array{title: string, summary: string, patterns: mixed, sections: Sections}>  $groups
     */
    protected function featureGroupTitleFor(array $candidates, array $groups): ?string
    {
        foreach ($groups as $group) {
            foreach ((array) ($group['patterns'] ?? []) as $pattern) {
                if (! is_string($pattern)) {
                    continue;
                }

                foreach ($candidates as $candidate) {
                    if (preg_match($pattern, $candidate)) {
                        return $group['title'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return Parsed|null
     */
    protected function parse(string $subject): ?array
    {
        $raw = trim($subject);

        if ($raw === '') {
            return null;
        }

        foreach ((array) $this->config('ignore_patterns', []) as $pattern) {
            if (is_string($pattern) && preg_match($pattern, $raw)) {
                return null;
            }
        }

        $type = null;
        $scope = null;
        $breaking = false;
        $text = $raw;

        if (preg_match('/^(?<type>[a-z]+)(?:\((?<scope>[^)]+)\))?(?<bang>!)?:\s*(?<rest>.+)$/i', $text, $match)) {
            $type = strtolower($match['type']);
            $scope = $match['scope'] !== '' ? $match['scope'] : null;
            $breaking = $match['bang'] === '!';
            $text = $match['rest'];
        }

        if (preg_match('/\bBREAKING[ -]CHANGE\b/i', $text)) {
            $breaking = true;
            $text = (string) preg_replace('/\bBREAKING[ -]CHANGE\b:?\s*/i', '', $text);
        }

        $text = (string) preg_replace('/^\[[^\]]+\]\s*/', '', $text);
        $text = (string) preg_replace('/^[A-Z]{2,}-\d+:?\s*/', '', $text);
        $text = (string) preg_replace('/^:[a-z_]+:\s*/', '', $text);
        $text = (string) preg_replace('/^[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]+\s*/u', '', $text);
        $text = (string) preg_replace('/\s*\(#\d+\)\s*$/', '', $text);
        $text = (string) preg_replace('/\s+#\d+\s*$/', '', $text);
        $text = (string) preg_replace('/\s*\((?:closes|fixes|resolves|refs?)\s+#\d+\)\s*$/i', '', $text);
        $text = str_replace('`', '', $text);
        $text = (string) preg_replace('/\s+/', ' ', $text);
        $text = trim($text, " \t\n\r\0\x0B-:.");

        if ($text === '') {
            return null;
        }

        return [
            'text' => $text,
            'raw' => $raw,
            'type' => $type,
            'scope' => $scope,
            'breaking' => $breaking,
        ];
    }

    /**
     * @param  Parsed  $parsed
     */
    protected function classify(array $parsed): string
    {
        $byType = match ($parsed['type']) {
            'feat', 'feature' => ReleaseNote::SECTION_NEW,
            'fix', 'bugfix', 'hotfix', 'revert' => ReleaseNote::SECTION_FIXED,
            'perf', 'refactor', 'style', 'improvement', 'improve', 'ui', 'ux' => ReleaseNote::SECTION_IMPROVED,
            default => null,
        };

        if ($byType !== null) {
            return $byType;
        }

        $leading = $this->leadingVerb($parsed['text']);

        if ($leading !== null && in_array($leading, $this->verbs(ReleaseNote::SECTION_NEW), true)) {
            return ReleaseNote::SECTION_NEW;
        }

        if ($leading !== null && in_array($leading, $this->verbs(ReleaseNote::SECTION_FIXED), true)) {
            return ReleaseNote::SECTION_FIXED;
        }

        if (preg_match(self::FIX_SIGNALS, $parsed['text'])) {
            return ReleaseNote::SECTION_FIXED;
        }

        if ($leading === null && preg_match('/\b(new|initial|first)\b/i', $parsed['text'])) {
            return ReleaseNote::SECTION_NEW;
        }

        return ReleaseNote::SECTION_IMPROVED;
    }

    /**
     * @param  Parsed  $parsed
     */
    protected function render(array $parsed, string $section): string
    {
        $text = $parsed['text'];

        foreach ((array) $this->config('cleanup_replacements', []) as $pattern => $replacement) {
            $text = preg_replace($pattern, (string) $replacement, $text) ?? $text;
        }

        $text = (string) preg_replace('/\s+/', ' ', trim($text));
        $text = $this->detailRewrite($text) ?? $this->voice($text);
        $text = Str::ucfirst(trim($text));
        $text = rtrim($text, '.').'.';

        return $parsed['breaking'] ? self::BREAKING_PREFIX.$text : $text;
    }

    protected function detailRewrite(string $text): ?string
    {
        $withoutVerb = $this->stripLeadingVerb($text);

        foreach ((array) $this->config('detail_rewrites', []) as $pattern => $replacement) {
            if (preg_match($pattern, $text) || preg_match($pattern, $withoutVerb)) {
                return (string) $replacement;
            }
        }

        return null;
    }

    /**
     * Past voice turns the leading imperative verb, and any known verb that
     * follows "and" / ", ", into past tense ("Pin X and link Y" becomes
     * "Pinned X and linked Y"). Imperative voice keeps the subject as written.
     */
    protected function voice(string $text): string
    {
        if ($this->config('voice', self::VOICE_PAST) !== self::VOICE_PAST) {
            return $text;
        }

        // "Prompt audit: native JSON schemas" is a titled noun phrase, not
        // an instruction to prompt something.
        if (preg_match('/^\S+ \S+:/', $text)) {
            return $text;
        }

        $words = explode(' ', $text);
        $first = $this->knownVerb(rtrim($words[0], ',;:'));

        if ($first === null) {
            return $text;
        }

        $words[0] = $this->conjugate($first);

        // After a causative ("make X wait", "let Y run") the later verbs are
        // bare infinitives and must stay that way.
        if (in_array(strtolower($first), ['make', 'let', 'have', 'help'], true)) {
            return implode(' ', $words);
        }

        foreach ($words as $index => $word) {
            if ($index === 0) {
                continue;
            }

            $previous = strtolower(rtrim($words[$index - 1], ','));
            $conjunction = $previous === 'and' || $previous === 'then' || str_ends_with($words[$index - 1], ',');
            $candidate = $this->knownVerb(rtrim($word, ',;:'));

            if ($conjunction && $candidate !== null && strtolower($candidate) !== 'new') {
                $words[$index] = $this->conjugate($candidate).substr($word, strlen($candidate));
            }
        }

        return implode(' ', $words);
    }

    /**
     * The word itself when it is a lexicon verb, including un-/re- prefixed
     * forms of one ("unclip", "reattach"). Returns the original casing.
     */
    protected function knownVerb(string $word): ?string
    {
        $lower = strtolower($word);

        if ($lower === '' || preg_match('/[^a-z-]/', $lower)) {
            return null;
        }

        $known = $this->allVerbs();

        if (in_array($lower, $known, true)) {
            return $word;
        }

        foreach (['un', 're'] as $prefix) {
            if (strlen($lower) > strlen($prefix) + 2 && str_starts_with($lower, $prefix) && in_array(substr($lower, strlen($prefix)), $known, true)) {
                return $word;
            }
        }

        return null;
    }

    protected function conjugate(string $verb): string
    {
        $lower = strtolower($verb);

        if (in_array($lower, $this->allVerbs(), true)) {
            return self::pastTense($lower);
        }

        foreach (['un', 're'] as $prefix) {
            if (str_starts_with($lower, $prefix) && in_array(substr($lower, strlen($prefix)), $this->allVerbs(), true)) {
                return $prefix.self::pastTense(substr($lower, strlen($prefix)));
            }
        }

        return self::pastTense($lower);
    }

    protected function leadingVerb(string $text): ?string
    {
        $verb = $this->knownVerb((string) preg_replace('/[^a-z-].*$/i', '', explode(' ', $text)[0]));

        if ($verb === null) {
            return null;
        }

        $lower = strtolower($verb);

        foreach (['un', 're'] as $prefix) {
            if (! in_array($lower, $this->allVerbs(), true) && str_starts_with($lower, $prefix)) {
                return substr($lower, strlen($prefix));
            }
        }

        return $lower;
    }

    protected function stripLeadingVerb(string $text): string
    {
        $words = explode(' ', $text, 2);

        if ($this->leadingVerb($text) === null || ! isset($words[1])) {
            return $text;
        }

        return trim($words[1]);
    }

    /** @return list<string> */
    protected function verbs(string $section): array
    {
        $builtIn = match ($section) {
            ReleaseNote::SECTION_NEW => self::NEW_VERBS,
            ReleaseNote::SECTION_FIXED => self::FIXED_VERBS,
            default => self::IMPROVED_VERBS,
        };

        $configured = array_map(
            static fn ($verb): string => strtolower((string) $verb),
            (array) ($this->config('section_prefixes', [])[$section] ?? []),
        );

        return array_values(array_unique([...$builtIn, ...$configured]));
    }

    /** @return list<string> */
    protected function allVerbs(): array
    {
        return array_values(array_unique([
            ...$this->verbs(ReleaseNote::SECTION_NEW),
            ...$this->verbs(ReleaseNote::SECTION_FIXED),
            ...$this->verbs(ReleaseNote::SECTION_IMPROVED),
        ]));
    }

    protected function itemKey(string $sentence): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($sentence));
    }

    /**
     * Name the release by its busiest feature areas ("New features and
     * fixes in Editor, Intelligence and Projects"); the generic phrasing
     * only remains for releases with no configured areas hit.
     *
     * @param  Sections  $sections
     * @param  list<FeatureGroup>  $featureGroups
     */
    protected function buildHeadline(array $sections, array $featureGroups = []): string
    {
        $areas = collect($featureGroups)
            ->reject(static fn (array $group): bool => $group['title'] === self::GENERAL_GROUP_TITLE)
            ->sortByDesc('item_count')
            ->take((int) $this->config('limits.headline_areas', 3))
            ->map(static fn (array $group): string => (string) ($group['short'] ?? $group['title']))
            ->values()
            ->all();

        if ($areas !== []) {
            $list = Arr::join($areas, ', ', ' and ');

            return match (true) {
                $sections[ReleaseNote::SECTION_NEW] !== [] && $sections[ReleaseNote::SECTION_FIXED] !== [] => "New features and fixes in {$list}",
                $sections[ReleaseNote::SECTION_NEW] !== [] => "New in {$list}",
                $sections[ReleaseNote::SECTION_FIXED] !== [] => "Fixes across {$list}",
                default => "Improvements to {$list}",
            };
        }

        $app = $this->appName();

        return match (true) {
            $sections[ReleaseNote::SECTION_NEW] !== [] && $sections[ReleaseNote::SECTION_FIXED] !== [] => 'New features and fixes are live',
            $sections[ReleaseNote::SECTION_NEW] !== [] => 'New features are now available',
            $sections[ReleaseNote::SECTION_FIXED] !== [] => "{$app} now includes fresh fixes",
            default => "{$app} has been improved",
        };
    }

    /**
     * @param  Sections  $sections
     */
    protected function buildSummary(array $sections): string
    {
        $labels = [
            ReleaseNote::SECTION_NEW => ['new feature', 'new features'],
            ReleaseNote::SECTION_IMPROVED => ['improvement', 'improvements'],
            ReleaseNote::SECTION_FIXED => ['fix', 'fixes'],
        ];
        $parts = [];

        foreach ($labels as $section => [$singular, $plural]) {
            $count = count($sections[$section]);

            if ($count === 0) {
                continue;
            }

            $parts[] = sprintf('%s %s', number_format($count), $count === 1 ? $singular : $plural);
        }

        if ($parts === []) {
            return $this->fallbackSummary();
        }

        return 'This release includes '.implode(', ', $parts).'.';
    }

    protected function appName(): string
    {
        $name = config('app.name');

        return is_string($name) && $name !== '' ? $name : 'The app';
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config('app-version.release_notes.'.$key, $default);
    }
}
