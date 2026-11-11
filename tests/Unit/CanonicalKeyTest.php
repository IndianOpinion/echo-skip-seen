<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Tests\Unit;

use IndianOpinion\EchoSkipSeen\Exception\InvalidUrlException;
use IndianOpinion\EchoSkipSeen\Keys\Canonicalizer;
use IndianOpinion\EchoSkipSeen\Keys\KeyFactory;
use IndianOpinion\EchoSkipSeen\Keys\Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpStubs;

/**
 * PRD 4.2, acceptance tests T1 to T26.
 */
final class CanonicalKeyTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubs::reset();
    }

    private function key(string $url, ?Rules $rules = null): string
    {
        if ($rules !== null) {
            return (new Canonicalizer($rules))->key($url);
        }

        return (new KeyFactory(static fn (): Rules => Rules::fromFilters()))->forUrl($url)->canonical;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function equalPairs(): array
    {
        return [
            'T1 whitespace and entities' => ['  https://example.com/a?id=1&amp;x=2  ', 'https://example.com/a?id=1&x=2'],
            'T4 user information' => ['https://user:pw@example.com/a', 'https://example.com/a'],
            'T5 scheme' => ['http://example.com/a', 'https://example.com/a'],
            'T6 port 443' => ['https://example.com:443/a', 'https://example.com/a'],
            'T6 port 80' => ['http://example.com:80/a', 'https://example.com/a'],
            'T6 port 80 with https' => ['https://example.com:80/a', 'https://example.com/a'],
            'T7 host case and dot' => ['https://EXAMPLE.com./a', 'https://example.com/a'],
            'T9 www' => ['https://www.example.com/a', 'https://example.com/a'],
            'T9 m' => ['https://m.example.com/a', 'https://example.com/a'],
            'T9 mobile' => ['https://mobile.example.com/a', 'https://example.com/a'],
            'T9 amp' => ['https://amp.example.com/a', 'https://example.com/a'],
            'T9 www.m' => ['https://www.m.example.com/a', 'https://example.com/a'],
            'T11 AMP cache' => ['https://www-example-com.cdn.ampproject.org/c/s/www.example.com/a/amp?utm_source=x', 'https://example.com/a'],
            'T11 AMP cache v form' => ['https://www-example-com.cdn.ampproject.org/v/www.example.com/a', 'https://example.com/a'],
            'T13 empty path' => ['https://example.com', 'https://example.com/'],
            'T13 trailing slash' => ['https://example.com/a/', 'https://example.com/a'],
            'T14 tilde' => ['https://example.com/%7Ea', 'https://example.com/~a'],
            'T14 hex case' => ['https://example.com/%e2%82%b9', 'https://example.com/%E2%82%B9'],
            'T15 raw UTF-8' => ['https://example.com/समाचार/a', 'https://example.com/%E0%A4%B8%E0%A4%AE%E0%A4%BE%E0%A4%9A%E0%A4%BE%E0%A4%B0/a'],
            'T16 path case' => ['https://example.com/A/Story', 'https://example.com/a/story'],
            'T17 double slash' => ['https://example.com/a//b', 'https://example.com/a/b'],
            'T17 dot' => ['https://example.com/a/./b', 'https://example.com/a/b'],
            'T17 dot dot' => ['https://example.com/a/x/../b', 'https://example.com/a/b'],
            'T18 index.html' => ['https://example.com/a/index.html', 'https://example.com/a'],
            'T18 index.php' => ['https://example.com/a/index.php', 'https://example.com/a'],
            'T19 24a leading amp' => ['https://example.com/amp/story', 'https://example.com/story'],
            'T19 24b trailing amp' => ['https://example.com/story/amp', 'https://example.com/story'],
            'T19 24c inner amp' => ['https://example.com/news/amp/story', 'https://example.com/news/story'],
            'T19 24d amp/1' => ['https://example.com/story/amp/1', 'https://example.com/story'],
            'T19 24e .amp' => ['https://example.com/story.amp', 'https://example.com/story'],
            'T19 24f .amp.html' => ['https://example.com/story.amp.html', 'https://example.com/story.html'],
            'T19 24f -amp.html' => ['https://example.com/story-amp.html', 'https://example.com/story.html'],
            'T19 24g amp_ prefix' => ['https://example.com/news/amp_articleshow/123.cms', 'https://example.com/news/articleshow/123.cms'],
            'T20 utm any case' => ['https://example.com/a?UTM_Source=x', 'https://example.com/a'],
            'T20 fbclid' => ['https://example.com/a?fbclid=1', 'https://example.com/a'],
            'T20 gclid and ref' => ['https://example.com/a?gclid=1&ref=rss', 'https://example.com/a'],
            'T20 on script endpoint' => ['https://example.com/view.php?id=4&utm_medium=x&fbclid=2', 'https://example.com/view.php?id=4'],
            'T20 root endpoint' => ['https://example.com/?p=4&gclid=1&ref=rss', 'https://example.com/?p=4'],
            'T21 empty value' => ['https://example.com/view.php?x=1&id=', 'https://example.com/view.php?x=1'],
            'T21 array param' => ['https://example.com/view.php?x=1&a[]=1&a[]=2', 'https://example.com/view.php?x=1'],
            'T22 slug path drops non-id' => ['https://example.com/news/story?page=2&foo=bar', 'https://example.com/news/story'],
            'T24 order' => ['https://example.com/view.php?b=2&a=1', 'https://example.com/view.php?a=1&b=2'],
            'T24 last value wins' => ['https://example.com/news?id=1&id=2', 'https://example.com/news?id=2'],
            'T25 fragment' => ['https://example.com/a#top', 'https://example.com/a'],
        ];
    }

    #[DataProvider('equalPairs')]
    public function testEqualKeys(string $a, string $b): void
    {
        self::assertSame($this->key($b), $this->key($a));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function differentPairs(): array
    {
        return [
            'T6 other port' => ['https://example.com:8080/a', 'https://example.com/a'],
            'T12 language subdomain' => ['https://hindi.example.com/a', 'https://example.com/a'],
            'T14 encoded slash' => ['https://example.com/a%2Fb', 'https://example.com/a/b'],
            'T22 id on slug path' => ['https://example.com/news?id=42', 'https://example.com/news?id=43'],
            'T23 root p' => ['https://example.com/?p=1', 'https://example.com/?p=2'],
            'T23 aspx keeps all' => ['https://example.com/page.aspx?PRID=7&lang=1', 'https://example.com/page.aspx?PRID=7'],
            'T24 value case' => ['https://example.com/news?id=AbC', 'https://example.com/news?id=abc'],
        ];
    }

    #[DataProvider('differentPairs')]
    public function testDifferentKeys(string $a, string $b): void
    {
        self::assertNotSame($this->key($b), $this->key($a));
    }

    public function testEntitiesAreDecodedOnceOnly(): void
    {
        // T1: "&amp;amp;" decodes to "&amp;", which then reads as a parameter named "amp;x".
        $once = $this->key('https://example.com/view.php?id=1&amp;amp;x=2');
        self::assertSame('example.com/view.php?amp;x=2&id=1', $once);
        self::assertNotSame($this->key('https://example.com/view.php?id=1&x=2'), $once);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notUrls(): array
    {
        return [
            'ftp' => ['ftp://example.com/a'],
            'no scheme' => ['example.com/a'],
            'javascript' => ['javascript:alert(1)'],
            'headline' => ['Minister announces new rail line'],
            'scheme only' => ['https://'],
            'no host' => ['http:///path'],
        ];
    }

    #[DataProvider('notUrls')]
    public function testNonUrlsYieldNoKey(string $value): void
    {
        // T2, T3.
        $this->expectException(InvalidUrlException::class);
        $this->key($value);
    }

    public function testUserInformationNeverReachesTheKey(): void
    {
        // T4.
        $keys = (new KeyFactory(static fn (): Rules => new Rules()))->forUrl('https://user:pw@example.com/a');
        self::assertStringNotContainsString('pw', $keys->canonical);
        self::assertStringNotContainsString('pw', $keys->plain);
    }

    public function testInternationalHostWithIntl(): void
    {
        // T8.
        if (!function_exists('idn_to_ascii')) {
            self::markTestSkipped('intl is not loaded');
        }
        self::assertSame($this->key('https://xn--bcher-kva.example/a'), $this->key('https://bücher.example/a'));
        self::assertSame('xn--bcher-kva.example/a', $this->key('https://BÜCHER.example/a'));
    }

    public function testInternationalHostWithoutIntl(): void
    {
        // T8: without intl both spellings still key, without error.
        $rules = new Rules(useIntl: false);
        self::assertSame('bücher.example/a', $this->key('https://bücher.example/a', $rules));
        self::assertSame('xn--bcher-kva.example/a', $this->key('https://xn--bcher-kva.example/a', $rules));
    }

    public function testShortAndIpHostsAreKept(): void
    {
        // T10.
        self::assertSame('m.com/a', $this->key('https://m.com/a'));
        self::assertSame('m.com/a', $this->key('https://www.m.com/a'));
        self::assertSame('192.0.2.1/a', $this->key('https://192.0.2.1/a'));
        self::assertSame('[2001:db8::1]/a', $this->key('https://[2001:db8::1]/a'));
    }

    public function testAmpCacheIsUnwrappedOnce(): void
    {
        // T11: the inner URL is itself an AMP cache URL, so it stays as it is.
        $nested = 'https://a-b.cdn.ampproject.org/c/s/c-d.cdn.ampproject.org/c/s/example.com/a';
        self::assertSame('c-d.cdn.ampproject.org/c/s/example.com/a', $this->key($nested));
    }

    public function testHostFilterCanMergeLanguageSubdomain(): void
    {
        // T12.
        add_filter('echo_skip_seen_host', static fn (string $host): string => $host === 'hindi.example.com' ? 'example.com' : $host, 10, 2);
        self::assertSame($this->key('https://example.com/a'), $this->key('https://hindi.example.com/a'));
    }

    public function testPathLowerCasingIsOnByDefaultAndOffPerHost(): void
    {
        // T16: lower-cased by default (escapes keep upper-case hex); the filter turns it off for one host.
        self::assertSame($this->key('https://example.com/a/story'), $this->key('https://example.com/A/Story'));
        self::assertSame('example.com/a%E2%82%B9', $this->key('https://example.com/A%e2%82%b9'));
        add_filter('echo_skip_seen_lowercase_path', static fn (bool $lower, string $host): bool => $lower && $host !== 'example.com', 10, 2);
        self::assertSame('example.com/A/Story', $this->key('https://example.com/A/Story'));
        self::assertNotSame($this->key('https://example.com/a/story'), $this->key('https://example.com/A/Story'));
        self::assertSame('other.test/a', $this->key('https://other.test/A'));
    }

    public function testAmpLookalikesAreNotTouched(): void
    {
        // T19.
        self::assertSame('example.com/camp/story', $this->key('https://example.com/camp/story'));
        self::assertSame('example.com/example-amplifier', $this->key('https://example.com/example-amplifier'));
        self::assertSame('example.com/news/amp_other', $this->key('https://example.com/news/amp_other'));
        self::assertSame('example.com/ramp.html', $this->key('https://example.com/ramp.html'));
    }

    public function testOnlyTrackingParametersLeaveNoQuestionMark(): void
    {
        // T24.
        self::assertSame('example.com/view.php', $this->key('https://example.com/view.php?utm_source=a&fbclid=b'));
    }

    public function testArticleIdRuleAndKeyFilter(): void
    {
        // T26.
        add_filter('echo_skip_seen_article_id_rules', static fn (): array => ['example.com' => '~-(?<id>\d{6,})$~']);
        self::assertSame($this->key('https://example.com/cricket/y-123456'), $this->key('https://www.example.com/sports/x-123456'));
        self::assertNotSame($this->key('https://other.test/cricket/y-123456'), $this->key('https://other.test/sports/x-123456'));

        add_filter('echo_skip_seen_canonical_key', static fn (string $key): string => str_contains($key, 'rewrite') ? 'custom-key' : '', 10, 2);
        self::assertSame('custom-key', $this->key('https://example.com/rewrite'));
        self::assertSame('example.com/keep', $this->key('https://example.com/keep'));
    }

    public function testBrokenArticleIdPatternIsIgnoredWithoutWarning(): void
    {
        add_filter('echo_skip_seen_article_id_rules', static fn (): array => ['example.com' => '~(unclosed']);
        $errors = [];
        set_error_handler(static function (int $no, string $message) use (&$errors): bool {
            $errors[] = $message;

            return true;
        });
        try {
            self::assertSame('example.com/a', $this->key('https://example.com/a'));
        } finally {
            restore_error_handler();
        }
        self::assertSame([], $errors);
    }

    public function testFilterableLists(): void
    {
        add_filter('echo_skip_seen_tracking_params', static fn (array $p): array => array_merge($p, ['session']));
        add_filter('echo_skip_seen_id_params', static fn (array $p): array => array_merge($p, ['page']));
        add_filter('echo_skip_seen_mirror_labels', static fn (array $p): array => array_merge($p, ['beta']));
        add_filter('echo_skip_seen_script_extensions', static fn (array $p): array => array_merge($p, ['pl']));
        add_filter('echo_skip_seen_amp_segment_names', static fn (array $p): array => array_merge($p, ['story']));

        self::assertSame('example.com/a?page=2', $this->key('https://beta.example.com/a?page=2&session=1'));
        self::assertSame('example.com/run.pl?x=1', $this->key('https://example.com/run.pl?x=1'));
        self::assertSame('example.com/story/1', $this->key('https://example.com/amp_story/1'));
    }

    public function testVersionConstant(): void
    {
        self::assertSame(1, Canonicalizer::VERSION);
    }
}
