<?php

/**
 * Prints the canonical and plain keys of URLs, without WordPress.
 *
 *   php examples/keys-cli.php 'https://m.example.com/news/amp/story-1?utm_source=x'
 *
 * Uses the default rules. Site filters are not applied here; use
 * "wp skip-seen check <url>" on a site to see the keys it really uses.
 *
 * @package IndianOpinion\EchoSkipSeen
 */

declare(strict_types=1);

use IndianOpinion\EchoSkipSeen\Exception\InvalidUrlException;
use IndianOpinion\EchoSkipSeen\Keys\KeyFactory;
use IndianOpinion\EchoSkipSeen\Keys\Rules;

require_once dirname(__DIR__) . '/src/autoload.php';

$urls = array_slice($argv, 1);
if ($urls === []) {
    fwrite(STDERR, "usage: php examples/keys-cli.php <url> [<url> ...]\n");
    exit(2);
}

$factory = new KeyFactory(static fn (): Rules => new Rules());
foreach ($urls as $url) {
    try {
        $keys = $factory->forUrl($url);
    } catch (InvalidUrlException $e) {
        fwrite(STDOUT, $url . "\n  no key: " . $e->getMessage() . "\n");
        continue;
    }
    fwrite(STDOUT, sprintf(
        "%s\n  canonical  %s\n             %s\n  plain      %s\n             %s\n",
        $url,
        $keys->canonical,
        $keys->canonicalHash,
        $keys->plain,
        $keys->plainHash
    ));
}
