<?php

namespace Wszdb\FlarumAiChat;

/**
 * The base URI an admin typed, checked before it ever reaches the HTTP client:
 * http(s) only, and never loopback, link-local or private addresses. Both the
 * client factory and the model-list fetcher go through here, so the setting is
 * not a request-forgery primitive.
 */
class Endpoint
{
    /**
     * @throws \InvalidArgumentException when the URI is not fetchable safely
     */
    public static function assertSafe(?string $baseUri): string
    {
        $parts = parse_url(trim((string) $baseUri));
        $host = $parts['host'] ?? '';

        if (!in_array($parts['scheme'] ?? '', ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException('the base URI must be an http(s) url');
        }

        // a literal is checked as-is, a name is resolved once
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException("cannot resolve the host '{$host}'");
        }

        $unsafe = $ip === '::1'
            || str_starts_with($ip, '127.')
            || str_starts_with($ip, '169.254.')
            || str_starts_with(strtolower($ip), 'fe80:')
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RESERVATION) === false;

        if ($unsafe) {
            throw new \InvalidArgumentException("'{$host}' resolves to a local or private address");
        }

        return trim((string) $baseUri);
    }
}
