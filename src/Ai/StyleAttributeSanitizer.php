<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Ai;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Keeps ordinary inline styling (alignment, colours, spacing) and drops the declarations that can reach the
 * network or cover the interface: url(), @import, expression(), behaviour/binding and fixed/absolute positioning.
 */
final class StyleAttributeSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $kept = [];

        foreach (explode(';', $value) as $declaration) {
            $declaration = trim($declaration);

            if ($declaration === '') {
                continue;
            }

            if (preg_match('/url\s*\(|@import|expression|behaviou?r|binding|\\\\|position\s*:\s*(fixed|absolute|sticky)/i', $declaration) === 1) {
                continue;
            }

            $kept[] = $declaration;
        }

        return $kept === [] ? null : implode('; ', $kept);
    }
}
