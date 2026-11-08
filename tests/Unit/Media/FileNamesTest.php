<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Tests\Unit\Media;

use Grafida\Media\FileNames;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileNamesTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function names(): array
    {
        return [
            'plain'             => ['photo.png', 'photo.png'],
            'posix traversal'   => ['../../.ssh/authorized_keys', 'authorized_keys'],
            'windows traversal' => ['..\\..\\Startup\\run.bat', 'run.bat'],
            'only dots'         => ['..', 'image.png'],
            'empty'             => ['', 'image.png'],
            'control chars'     => ["a\x00b\nc.png", 'abc.png'],
            'reserved chars'    => ['a:b*c?.png', 'abc.png'],
        ];
    }

    #[DataProvider('names')]
    public function testSanitise(string $in, string $out): void
    {
        self::assertSame($out, FileNames::sanitise($in));
    }
}
