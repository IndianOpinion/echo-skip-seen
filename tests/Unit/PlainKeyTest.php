<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Tests\Unit;

use IndianOpinion\EchoSkipSeen\Keys\KeyFactory;
use IndianOpinion\EchoSkipSeen\Keys\PlainKey;
use IndianOpinion\EchoSkipSeen\Keys\Rules;
use IndianOpinion\EchoSkipSeen\Keys\Url;
use PHPUnit\Framework\TestCase;

/**
 * PRD 4.3, acceptance test T28.
 */
final class PlainKeyTest extends TestCase
{
    public function testDefinition(): void
    {
        self::assertSame('example.com/a?b=1&a=2', PlainKey::make(Url::clean('HTTPS://WWW.Example.com/A/?b=1&a=2#f')));
    }

    public function testQueryIsKept(): void
    {
        self::assertNotSame(
            PlainKey::make(Url::clean('https://example.com/?p=1')),
            PlainKey::make(Url::clean('https://example.com/?p=2'))
        );
    }

    public function testOnlyOneWwwAndOneSlash(): void
    {
        self::assertSame('www.example.com/a/', PlainKey::make(Url::clean('https://www.www.example.com/a//')));
    }

    public function testUserInformationIsRemoved(): void
    {
        self::assertSame('example.com/a', PlainKey::make(Url::clean('https://user:pw@www.example.com/a')));
    }

    public function testIdenticalKeysGiveOneRow(): void
    {
        // T36: canonical and plain keys of this URL are the same string.
        $keys = (new KeyFactory(static fn (): Rules => new Rules()))->forUrl('https://example.com/a');
        self::assertSame($keys->canonical, $keys->plain);
        self::assertCount(1, $keys->rows());

        $keys = (new KeyFactory(static fn (): Rules => new Rules()))->forUrl('https://m.example.com/a/');
        self::assertCount(2, $keys->rows());
    }
}
