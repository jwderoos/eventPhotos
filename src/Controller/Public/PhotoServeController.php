<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Repository\PhotoRepository;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class PhotoServeController extends AbstractController
{
    private const int MAX_AGE = 31536000;

    private const string CACHE_CONTROL = 'public, max-age=' . self::MAX_AGE . ', immutable';

    public function __construct(
        private readonly PhotoRepository $photos,
        #[Autowire(service: 'photo_thumbs_storage')]
        private readonly FilesystemOperator $thumbs,
        #[Autowire(service: 'photo_previews_storage')]
        private readonly FilesystemOperator $previews,
    ) {
    }

    #[Route(
        '/e/{slug}/p/{id}/thumb.jpg',
        name: 'photo_serve_thumb',
        requirements: ['slug' => '[a-z0-9-]+', 'id' => '\d+'],
        methods: ['GET'],
    )]
    public function thumb(string $slug, int $id, Request $request): StreamedResponse
    {
        return $this->serve($slug, $id, $this->thumbs, $request);
    }

    #[Route(
        '/e/{slug}/p/{id}/preview.jpg',
        name: 'photo_serve_preview',
        requirements: ['slug' => '[a-z0-9-]+', 'id' => '\d+'],
        methods: ['GET'],
    )]
    public function preview(string $slug, int $id, Request $request): StreamedResponse
    {
        return $this->serve($slug, $id, $this->previews, $request);
    }

    private function serve(string $slug, int $id, FilesystemOperator $storage, Request $request): StreamedResponse
    {
        $photo = $this->photos->find($id);
        if (!$photo instanceof Photo || $photo->getStatus() !== PhotoStatus::Ready) {
            throw $this->createNotFoundException();
        }

        if ($photo->getEvent()->getSlug() !== $slug) {
            throw $this->createNotFoundException();
        }

        $path = sprintf('event-%d/%d.jpg', (int) $photo->getEvent()->getId(), $id);

        $etag         = sha1($id . '|' . $photo->getUpdatedAt()->format('U'));
        $quotedEtag   = '"' . $etag . '"';
        $response     = new StreamedResponse();
        $response->headers->set('Content-Type', 'image/jpeg');
        $response->headers->set('Cache-Control', self::CACHE_CONTROL);
        $response->headers->set('ETag', $quotedEtag);

        if ($request->headers->get('If-None-Match') === $quotedEtag) {
            return $response->setStatusCode(Response::HTTP_NOT_MODIFIED);
        }

        $response->setCallback(static function () use ($storage, $path): void {
            try {
                $stream = $storage->readStream($path);
            } catch (FilesystemException) {
                return;
            }

            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        });

        return $response;
    }
}
