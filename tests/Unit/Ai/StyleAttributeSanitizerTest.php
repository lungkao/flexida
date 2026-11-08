<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Ai;

use Grafida\Ai\StyleAttributeSanitizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class StyleAttributeSanitizerTest extends TestCase
{
    public function testKeepsOrdinaryStylingAndDropsNetworkAndOverlayDeclarations(): void
    {
        $s   = new StyleAttributeSanitizer();
        $cfg = new HtmlSanitizerConfig();

        self::assertSame(
            'text-align: center; color: red',
            $s->sanitizeAttribute('p', 'style', 'text-align: center; background: url(https://x.test/a.png); color: red; position: fixed', $cfg),
        );
        self::assertNull($s->sanitizeAttribute('p', 'style', '@import "x"; behavior: url(x)', $cfg));
    }
}
