<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Site;

use Grafida\Site\SiteService;
use PHPUnit\Framework\TestCase;

final class SameOriginTest extends TestCase
{
    public function testComparesSchemeHostAndPort(): void
    {
        self::assertTrue(SiteService::sameOrigin('https://Example.com/api', 'https://example.com'));
        self::assertTrue(SiteService::sameOrigin('https://example.com:443/x', 'https://example.com'));
        self::assertFalse(SiteService::sameOrigin('https://evil.test', 'https://example.com'));
        self::assertFalse(SiteService::sameOrigin('http://example.com', 'https://example.com'));
        self::assertFalse(SiteService::sameOrigin('https://example.com:8443', 'https://example.com'));
        self::assertFalse(SiteService::sameOrigin('', 'https://example.com'));
    }
}
