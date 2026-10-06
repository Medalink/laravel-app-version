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
            // A missing authorization check: "Authorize X writes and reject foreign host IDs".
            '/\bIDOR\b/',
            '/\bauthori[sz]e\w* (?:[\w-]+ ){0,3}(?:writes?|reads?|toggles?|actions?|access)\b/i',
            '/\breject\w* foreign\b/i',
            '/\b(?i:require[ds]?|requiring|check(?:ed|s)?) [a-z]+(?:_[a-z]+)+\b/',
            '/\bonly (?:for|to) [a-z]+(?:_[a-z]+)+ users\b/',
            '/\b(?:credentials?|logins?|passwords?|secrets?|tokens?) exposure\b/i',
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
            '/@js\(\)|\bescap\w* (?:user|untrusted|input|output|html)\b/i',
            '/\bsanitiz/i',
            '/\bdecrypt/i',
            '/\bhardening\b/i',
            '/\b(?:mutation|sign-in|auth\w*) gates?\b/i',
            '/\bprivileges?\b/i',
            '/\bunprivileged\b|\bleast privilege\b/i',
            '/\bsupply[- ]chain\b/i',
            '/\bCSP\b/',
            '/\bconfine[ds]?\b/i',
            '/\brestrict\w* [\w ]*(?:origins?|access)\b/i',
            '/\bmade [\w ]*private\b/i',
            '/\bverif(?:y|ied|ies) ownership\b/i',
            '/\bscoped? [\w ]*to the (?:calling|current|signed-in) user\b/i',
            '/\bpermissions?\b.*\bgat(?:e|es|ed|ing)\b|\bgat(?:e|es|ed|ing)\b.*\bpermissions?\b/i',
            '/\bgat(?:e|es|ed) [\w ]*\b(?:on|behind) [a-z]+(?:_[a-z]+)+\b/',
            '/\btenant[- ]safe\b/i',
            '/\b(?:user )?registration\b.*\bproduction\b|\bdisabled? (?:user )?registration\b/i',
            '/\bdebug\b.*\bproduction\b|\bproduction leak\b/i',
            '/\b(?:session|idle) timeout\b|\bTMOUT\b/',
            '/\bits own channel\b|\bchannel isolation\b|\bsession isolation\b/i',
        ],

        // Matches that only look like security: the change text alone decides.
        'exclude' => [
            '/\b(?:chart|layout|colou?r|font)s?\b/i',
            '/\bpermissions page\b/i',
            '/\bpermission name\b/i',
            '/\brelease[- ]notes?\b/i',
            '/\bcredential fallback\b/i',
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
