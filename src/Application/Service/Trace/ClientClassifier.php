<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

/**
 * Who is on the other end of a request, from the User-Agent alone: a person
 * in a browser, a crawler, or a program talking to the API. Coarse on
 * purpose — the live panel draws three icons at the CLIENT end, and a wrong
 * icon is worse than a vague one, so anything unrecognised is "api".
 *
 * The full User-Agent never reaches the journal; only the family name does.
 */
final class ClientClassifier
{
    private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|uptime|headless|lighthouse|pingdom|scanner|archive/i';
    private const TOOLS = '/curl|wget|python-requests|python-urllib|aiohttp|go-http-client|okhttp|java\/|libwww|guzzle|symfony httpclient|postman|insomnia|httpie|node-fetch|axios|undici|php/i';
    private const BROWSERS = '/mozilla|chrome|safari|firefox|edg|opera|applewebkit/i';

    /** Family names, first match wins; order puts the specific before the generic. */
    private const FAMILIES = [
        'Googlebot', 'bingbot', 'YandexBot', 'DuckDuckBot', 'Baiduspider', 'Applebot',
        'curl', 'Wget', 'python-requests', 'Go-http-client', 'okhttp', 'PostmanRuntime', 'insomnia', 'HTTPie', 'axios', 'node-fetch', 'undici', 'GuzzleHttp',
        'Edg', 'OPR', 'Firefox', 'Chrome', 'Safari',
    ];

    /**
     * @param  array<string, mixed> $headers lower-cased header names
     * @return array{client: string, agent: string}
     */
    public static function classify(array $headers): array
    {
        if (isset($headers['x-observatory-demo'])) {
            return ['client' => 'internal', 'agent' => 'demo load'];
        }

        $ua = trim((string) ($headers['user-agent'] ?? ''));
        if ($ua === '') {
            return ['client' => 'api', 'agent' => 'no user-agent'];
        }

        $agent = self::family($ua);

        if (preg_match(self::BOTS, $ua) === 1) {
            return ['client' => 'bot', 'agent' => $agent];
        }
        if (preg_match(self::TOOLS, $ua) === 1) {
            return ['client' => 'api', 'agent' => $agent];
        }
        if (preg_match(self::BROWSERS, $ua) === 1) {
            return ['client' => 'human', 'agent' => $agent];
        }

        return ['client' => 'api', 'agent' => $agent];
    }

    private static function family(string $ua): string
    {
        foreach (self::FAMILIES as $name) {
            if (stripos($ua, $name) !== false) {
                return match ($name) {
                    'Edg' => 'Edge',
                    'OPR' => 'Opera',
                    'PostmanRuntime' => 'Postman',
                    default => $name,
                };
            }
        }

        // "Something/1.2 (...)": the product token before the slash.
        $token = strtok($ua, '/ (');

        return is_string($token) && $token !== '' ? mb_substr($token, 0, 24) : 'unknown';
    }
}
