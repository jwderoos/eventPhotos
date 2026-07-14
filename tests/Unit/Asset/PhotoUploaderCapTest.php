<?php

declare(strict_types=1);

namespace App\Tests\Unit\Asset;

use PHPUnit\Framework\TestCase;

/**
 * Guards that the client-side upload cap in the Stimulus controller stays in
 * sync with the server-side cap (PhotoController::MAX_BYTES = 10 * 1024 * 1024).
 *
 * WHY a string-scan and not an HTTP boundary test: the exact server-side byte
 * boundary cannot be exercised by a functional HTTP test on this host/CI,
 * because PHP's PERDIR upload_max_filesize (2M here) makes Symfony's
 * HttpKernelBrowser inject UPLOAD_ERR_INI_SIZE -> HTTP 413 before the request
 * ever reaches MAX_BYTES. There is no JS test harness in this project
 * (Asset Mapper + Stimulus, no bundler), so this lightweight PHP guard is the
 * portable way to lock client/server cap parity against silent drift.
 */
final class PhotoUploaderCapTest extends TestCase
{
    public function testClientCapMirrorsServerCap(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 3) . '/assets/controllers/photo_uploader_controller.js',
        );

        // Same expression the server const uses (PhotoController::MAX_BYTES).
        $this->assertStringContainsString('10 * 1024 * 1024', $js);
    }

    public function testClientHintCopyReadsTenMegabytes(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 3) . '/assets/controllers/photo_uploader_controller.js',
        );

        $this->assertStringContainsString('up to 10 MB each', $js);
        $this->assertStringContainsString('Too large (>10 MB)', $js);
    }
}
