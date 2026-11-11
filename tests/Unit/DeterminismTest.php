<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Tests\Unit;

use IndianOpinion\EchoSkipSeen\Keys\KeyFactory;
use IndianOpinion\EchoSkipSeen\Keys\Rules;
use PHPUnit\Framework\TestCase;

/**
 * PRD 39, acceptance test T27: 200 fixture URLs give byte-identical keys
 * on every PHP version in CI and under a non-C locale.
 */
final class DeterminismTest extends TestCase
{
    /**
     * @return list<array{url: string, canonical: string, plain: string}>
     */
    private static function fixtures(): array
    {
        $json = file_get_contents(dirname(__DIR__) . '/fixtures/keys.json');
        self::assertIsString($json);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    public function testFixtureKeysAreStable(): void
    {
        $fixtures = self::fixtures();
        self::assertCount(200, $fixtures);
        $this->assertKeys($fixtures);
    }

    public function testFixtureKeysUnderAnotherLocale(): void
    {
        $previous = setlocale(LC_ALL, '0');
        $set = setlocale(LC_ALL, 'tr_TR.UTF-8', 'tr_TR', 'de_DE.UTF-8', 'C.UTF-8');
        try {
            $this->assertKeys(self::fixtures());
        } finally {
            setlocale(LC_ALL, $previous === false ? 'C' : $previous);
        }
        self::assertTrue($set === false || is_string($set));
    }

    /**
     * @param list<array{url: string, canonical: string, plain: string}> $fixtures
     */
    private function assertKeys(array $fixtures): void
    {
        $factory = new KeyFactory(static fn (): Rules => new Rules(useIntl: false));
        foreach ($fixtures as $fixture) {
            $keys = $factory->forUrl($fixture['url']);
            self::assertSame($fixture['canonical'], $keys->canonical, $fixture['url']);
            self::assertSame($fixture['plain'], $keys->plain, $fixture['url']);
        }
    }
}
