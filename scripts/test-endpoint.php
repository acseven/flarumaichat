<?php

/**
 * Self-check for the base-URI guard, which every dial-out goes through:
 * php -d zend.assertions=1 scripts/test-endpoint.php
 *
 * No network: the hosts here are literals or resolve locally.
 */

use Wszdb\FlarumAiChat\Endpoint;

require __DIR__ . '/../src/Endpoint.php';

$refused = function (?string $uri): bool {
    try {
        Endpoint::assertSafe($uri);
    } catch (\InvalidArgumentException) {
        return true;
    } catch (\Throwable $e) {
        // anything else escapes the callers' catch and takes the request with it
        assert(false, 'the guard threw ' . get_class($e) . ': ' . $e->getMessage());
    }

    return false;
};

// a public address passes, and comes back trimmed
assert(Endpoint::assertSafe('  https://8.8.8.8/v1/  ') === 'https://8.8.8.8/v1/', 'a public https url passes');
assert(Endpoint::assertSafe('http://93.184.216.34/v1') === 'http://93.184.216.34/v1', 'plain http passes');

// everything that is not an http(s) url with a host
assert($refused(null) && $refused('') && $refused('   '), 'nothing is not a url');
assert($refused('ftp://example.com/'), 'another scheme is refused');
assert($refused('file:///etc/passwd'), 'a file url is refused');
assert($refused('/v1/chat'), 'a bare path is refused');

// the whole point: nothing that dials into this machine or its network
assert($refused('http://127.0.0.1:11434/v1'), 'loopback is refused');
assert($refused('http://localhost:8080/v1'), 'a name resolving to loopback is refused');
assert($refused('http://[::1]/v1'), 'ipv6 loopback is refused');
assert($refused('http://10.1.2.3/v1'), 'a private address is refused');
assert($refused('http://192.168.1.1/v1'), 'a home-network address is refused');
assert($refused('http://172.16.0.1/v1'), 'the other private range is refused');
assert($refused('http://169.254.169.254/latest/meta-data/'), 'the metadata address is refused');
assert($refused('http://[fe80::1]/v1'), 'link-local ipv6 is refused');
assert($refused('http://no-such-host.invalid/v1'), 'a host that resolves to nothing is refused');

echo "ok\n";
