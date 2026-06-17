<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Photo;
use App\Entity\PhotoStatus;
use App\Repository\PhotoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PhotoServeController extends AbstractController
{
    private const int MAX_AGE = 31536000;

    private const string CACHE_CONTROL = 'public, max-age=' . self::MAX_AGE . ', immutable';

    private const string INTERNAL_THUMB_PREFIX = '/_protected/thumbs/';

    private const string INTERNAL_PREVIEW_PREFIX = '/_protected/previews/';

    public function __construct(
        private readonly PhotoRepository $photos,
    ) {
    }

    #[Route(
        '/e/{slug}/p/{id}/thumb.jpg',
        name: 'photo_serve_thumb',
        requirements: ['slug' => '[a-z0-9-]+', 'id' => '\d+'],
        methods: ['GET'],
    )]
    public function thumb(string $slug, int $id): Response
    {
        return $this->serve($slug, $id, self::INTERNAL_THUMB_PREFIX);
    }

    #[Route(
        '/e/{slug}/p/{id}/preview.jpg',
        name: 'photo_serve_preview',
        requirements: ['slug' => '[a-z0-9-]+', 'id' => '\d+'],
        methods: ['GET'],
    )]
    public function preview(string $slug, int $id): Response
    {
        return $this->serve($slug, $id, self::INTERNAL_PREVIEW_PREFIX);
    }

    // Cache strategy: PHP emits `Cache-Control: immutable` so browsers cache for a year
    // without revalidating. Rare revalidates (Cmd-Shift-R, dev tools) hit PHP for the auth
    // check, then go through nginx's static module which handles If-Modified-Since/If-None-Match
    // against the file mtime/auto-ETag. We deliberately don't set an ETag in PHP — X-Accel
    // strips upstream ETag in nginx 1.27, so it would never reach the client anyway.
    private function serve(string $slug, int $id, string $internalPrefix): Response
    {
        $photo = $this->photos->find($id);
        if (!$photo instanceof Photo || $photo->getStatus() !== PhotoStatus::Ready) {
            throw $this->createNotFoundException();
        }

        if ($photo->getEvent()->getSlug() !== $slug) {
            throw $this->createNotFoundException();
        }

        $response = new Response();
        $response->headers->set('Content-Type', 'image/jpeg');
        $response->headers->set('Cache-Control', self::CACHE_CONTROL);
        // Hand bytes to nginx via X-Accel-Redirect. The internal location is `internal;`
        // so it cannot be hit directly from outside — PHP must authorise first (above).
        $response->headers->set(
            'X-Accel-Redirect',
            $internalPrefix . sprintf('event-%d/%d.jpg', (int) $photo->getEvent()->getId(), $id),
        );

        return $response;
    }
}
