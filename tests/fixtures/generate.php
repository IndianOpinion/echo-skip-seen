<?php

/**
 * Regenerates tests/fixtures/keys.json from the current rules.
 *
 * Run only on purpose, after a deliberate key version change:
 *   php tests/fixtures/generate.php
 *
 * @package IndianOpinion\EchoSkipSeen
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use IndianOpinion\EchoSkipSeen\Keys\KeyFactory;
use IndianOpinion\EchoSkipSeen\Keys\Rules;

$hosts = ['example.com', 'www.Example.COM', 'm.news.example.org', 'amp.example.in', '192.0.2.10', 'xn--bcher-kva.example', 'hindi.example.com', 'example.com:8080'];
$paths = ['', '/', '/a/b/', '/2026/11/story-title-123456.html', '/news/amp/story', '/story.amp', '/A//B/./c/../D', '/%7euser/%e2%82%b9', '/index.php', '/page.aspx', '/samachar/समाचार', '/s-amp.html', '/x/amp_articleshow/99.cms', '/camp/amplifier'];
$queries = ['', '?utm_source=rss&utm_medium=feed', '?id=42&page=3', '?p=7&ref=home', '?b=2&a=1', '?id=1&id=2#frag', '?a[]=1&x=&ID=AbC', '?fbclid=Q&story=12', '?q=caf%c3%a9+bar'];

$urls = [];
$i = 0;
foreach ($hosts as $h => $host) {
    foreach ($paths as $p => $path) {
        foreach ($queries as $q => $query) {
            if (($h * 31 + $p * 7 + $q * 3 + $i) % 5 !== 0) {
                $i++;
                continue;
            }
            $scheme = $i % 2 === 0 ? 'https' : 'HTTP';
            $urls[] = $scheme . '://' . $host . $path . $query;
            $i++;
        }
    }
}
$urls = array_slice($urls, 0, 200);

$factory = new KeyFactory(static fn (): Rules => new Rules(useIntl: false));
$out = [];
foreach ($urls as $url) {
    $keys = $factory->forUrl($url);
    $out[] = ['url' => $url, 'canonical' => $keys->canonical, 'plain' => $keys->plain];
}

file_put_contents(__DIR__ . '/keys.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo count($out), " fixtures written\n";
