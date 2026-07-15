<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Photo;

use App\Service\Photo\DerivativeGenerator;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

final class DerivativeGeneratorDeleteTest extends KernelTestCase
{
    private DerivativeGenerator $generator;

    private FilesystemOperator $thumbs;

    private FilesystemOperator $previews;

    private const string PATH = 'event-99999/1.jpg';

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();

        /** @var DerivativeGenerator $generator */
        $generator = $c->get(DerivativeGenerator::class);
        /** @var FilesystemOperator $thumbs */
        $thumbs = $c->get('photo_thumbs_storage');
        /** @var FilesystemOperator $previews */
        $previews = $c->get('photo_previews_storage');

        $this->generator = $generator;
        $this->thumbs = $thumbs;
        $this->previews = $previews;
    }

    public function testDeleteRemovesThumbAndPreview(): void
    {
        $this->thumbs->write(self::PATH, 'thumb-bytes');
        $this->previews->write(self::PATH, 'preview-bytes');

        $this->generator->delete(self::PATH);

        $this->assertFalse($this->thumbs->fileExists(self::PATH));
        $this->assertFalse($this->previews->fileExists(self::PATH));
    }

    public function testDeleteIsBestEffortWhenFilesAbsent(): void
    {
        $this->expectNotToPerformAssertions();

        // No files written — must not throw.
        $this->generator->delete(self::PATH);
    }

    protected function tearDown(): void
    {
        foreach ([$this->thumbs, $this->previews] as $fs) {
            try {
                $fs->deleteDirectory('event-99999');
            } catch (Throwable) {
            }
        }

        parent::tearDown();
    }
}
