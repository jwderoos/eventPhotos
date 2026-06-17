<?php

declare(strict_types=1);

namespace App\Service\Session;

use WhichBrowser\Parser;

final class UserAgentParser
{
    private const int MAX_DISPLAY_LENGTH = 128;

    public function displayString(string $userAgent): ?string
    {
        $userAgent = trim($userAgent);
        if ($userAgent === '') {
            return null;
        }

        $parser = new Parser($userAgent);

        $browser = trim($parser->browser->toString());
        $os = trim($parser->os->toString());

        $parts = array_filter([$browser, $os], static fn (string $s): bool => $s !== '');
        $display = $parts !== []
            ? implode(' — ', $parts)
            : trim($parser->toString());

        if ($display === '') {
            return null;
        }

        if (strlen($display) > self::MAX_DISPLAY_LENGTH) {
            return substr($display, 0, self::MAX_DISPLAY_LENGTH - 1) . '…';
        }

        return $display;
    }
}
