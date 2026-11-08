<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Media;

/**
 * Makes an untrusted file name safe to use as the last path component.
 *
 * File names reach us from imported `.grafida` files and from remote sites; they must never be able to
 * choose a directory, so any directory part, control character and Windows-reserved character is dropped.
 */
final class FileNames
{
    public static function sanitise(string $name, string $fallback = 'image.png'): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '', $name);
        $name = trim($name, ". \t");

        if ($name === '' || $name === '.' || $name === '..') {
            return $fallback;
        }

        return mb_substr($name, 0, 150);
    }
}
