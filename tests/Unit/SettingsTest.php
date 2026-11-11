<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Tests\Unit;

use IndianOpinion\EchoSkipSeen\Settings;
use PHPUnit\Framework\TestCase;
use WpStubs;

/**
 * PRD section 8: every value from a request is validated.
 */
final class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubs::reset();
    }

    public function testDefaults(): void
    {
        $settings = new Settings();
        self::assertTrue($settings->enabled());
        self::assertSame(90, $settings->retentionDays());
        self::assertSame([], $settings->metaKeys());
        self::assertFalse($settings->debug());
        self::assertSame(['post'], $settings->postTypes());
    }

    public function testRetentionIsClamped(): void
    {
        self::assertSame(0, Settings::clampDays('0'));
        self::assertSame(3650, Settings::clampDays('99999'));
        self::assertSame(90, Settings::clampDays('-5'));
        self::assertSame(90, Settings::clampDays('ten'));
        self::assertSame(0, Settings::clampDays(-3));
        self::assertSame(90, Settings::clampDays(null));
    }

    public function testMetaKeysAreValidated(): void
    {
        self::assertSame(
            ['_source_url', 'feed:link', 'a.b-c'],
            Settings::sanitiseMetaKeys("_source_url\n<script>\nfeed:link, a.b-c\n_source_url\n\n" . str_repeat('x', 300))
        );
        self::assertSame([], Settings::sanitiseMetaKeys(42));
    }

    public function testSavedOptionIsNotAutoloaded(): void
    {
        (new Settings())->save(['retention_days' => 5]);
        self::assertFalse(WpStubs::$autoload[1][Settings::OPTION]);
        self::assertSame(5, (new Settings())->retentionDays());
    }

    public function testBadPostTypesAreDropped(): void
    {
        add_filter('echo_skip_seen_post_types', static fn (): array => ['post', 'Bad Type', 7, 'news']);
        self::assertSame(['post', 'news'], (new Settings())->postTypes());
    }
}
