<?php

namespace Medalink\AppVersion\ReleaseNotes;

use Medalink\AppVersion\Models\ReleaseNote;

/**
 * Picks the security fixes out of a release's changes and rates them.
 *
 * It reads a stored change (text, details, section, feature area), so it
 * works on every published release without recompiling one. Rules come from
 * `app-version.release_notes.security`; any key left out keeps its default.
 */
final class SecurityClassifier
{
    public const string HIGH = 'high';

    public const string MEDIUM = 'medium';

    public const string LOW = 'low';

    /** Most to least severe; also the sort order of a security list. */
    public const array SEVERITIES = [self::HIGH, self::MEDIUM, self::LOW];

    /**
     * @var array{sections: list<string>, high: list<string>, medium: list<string>, exclude: list<string>, areas: list<string>}
     */
    public const array DEFAULTS = [
        // New features are never security fixes, even when they add one.
        'sections' => [ReleaseNote::SECTION_FIXED, ReleaseNote::SECTION_IMPROVED],

        // A published weakness: an advisory, a CVE, or an attack class.
        'high' => [
            '/\badvisory\b/i',
            '/\bCVE-\d{4}-\d+/i',
            '/\bGHSA-[\w-]+/i',
            '/\bvulnerab/i',
            '/\b(?:sql |command |header |shell )?injection\b/i',
            '/\b(?:XSS|CSRF|SSRF|RCE|XXE)\b/',
            '/\bcross-site\b/i',
            '/\b(?:path|directory) traversal\b/i',
            '/\b(?:auth(?:entication|orization)?|permission|access) bypass\b/i',
            '/\bprivilege escalation\b/i',
            '/\bunauthori[sz]ed (?:access|users?|requests?)\b/i',
            '/\bleak(?:ed|s|ing)? (?:secrets?|credentials?|tokens?|passwords?|keys?)\b/i',
        ],

        // Hardening: secrets, credentials, encoding, confinement, ownership.
        'medium' => [
            '/\bsecurity\b/i',
            '/\bredact/i',
            '/\bsecrets?\b/i',
            '/\bcredentials?\b/i',
            '/\bpasswords?\b/i',
            '/\bsudoers?\b/i',
            '/\bOAuth\b/i',
            '/\b(?:escape|escaped|escaping|encode[ds]?)\b/i',
            '/\bsanitiz/i',
            '/\bdecrypt/i',
            '/\bhardening\b/i',
            '/\bprivileges?\b/i',
            '/\bCSP\b/',
            '/\bconfine[ds]?\b/i',
            '/\brestrict\w* [\w ]*origins?\b/i',
            '/\bmade [\w ]*private\b/i',
            '/\bverif(?:y|ied|ies) ownership\b/i',
            '/\bscoped? [\w ]*to the (?:calling|current|signed-in) user\b/i',
            '/\bcertificates?\b/i',
        ],

        // Matches that only look like security: the change text alone decides.
        'exclude' => [
            '/\b(?:chart|layout|colou?r|font)s?\b/i',
            '/\bpermissions page\b/i',
        ],

        // Feature areas whose fixes count as security even without a keyword.
        'areas' => ['/\bsecurity\b/i'],
    ];

    /**
     * @param  array{sections: list<string>, high: list<string>, medium: list<string>, exclude: list<string>, areas: list<string>}  $rules
     */
    public function __construct(private readonly array $rules = self::DEFAULTS) {}

    public static function fromConfig(): self
    {
        $configured = config('app-version.release_notes.security');

        return new self(array_replace(self::DEFAULTS, is_array($configured) ? $configured : []));
    }

    /**
     * The severity of one change, or null when it is not a security fix.
     *
     * @param  list<string>  $details
     */
    public function classify(string $text, array $details, string $section, string $area = ''): ?string
    {
        if (! in_array($section, $this->rules['sections'], true)) {
            return null;
        }

        $body = trim($text.' '.implode(' ', $details));

        if ($this->matches($this->rules['high'], $body)) {
            return self::HIGH;
        }

        if ($this->matches($this->rules['medium'], $body) && ! $this->matches($this->rules['exclude'], $text)) {
            return self::MEDIUM;
        }

        if ($section === ReleaseNote::SECTION_FIXED && $this->matches($this->rules['areas'], $area)) {
            return self::LOW;
        }

        return null;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matches(array $patterns, string $subject): bool
    {
        foreach ($patterns as $pattern) {
            if ($subject !== '' && @preg_match($pattern, $subject) === 1) {
                return true;
            }
        }

        return false;
    }
}
