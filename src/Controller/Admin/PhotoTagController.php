<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\PhotoAttributeType;
use App\Repository\PhotoAttributeRepository;
use App\Security\Voter\EventVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only overview of the searchable tags the extraction pipeline (#109)
 * produced for an event's photos: distinct values per type with photo counts.
 * Bib / colour / clothing-type values link to the public gallery filtered by
 * that value (the visitor's view); scenes are not publicly filterable, so they
 * render as plain counts.
 */
final class PhotoTagController extends AbstractController
{
    /**
     * type => [label, public-gallery query param or null for "not filterable"].
     *
     * @var array<value-of<PhotoAttributeType>, array{label: string, param: string|null, multi: bool}>
     */
    private const array GROUPS = [
        'bib'            => ['label' => 'Bib numbers', 'param' => 'bib', 'multi' => false],
        'clothing_color' => ['label' => 'Clothing colours', 'param' => 'colour', 'multi' => true],
        'clothing_type'  => ['label' => 'Clothing types', 'param' => 'garment', 'multi' => true],
        'scene'          => ['label' => 'Scenes', 'param' => null, 'multi' => false],
    ];

    public function __construct(private readonly PhotoAttributeRepository $attributes)
    {
    }

    #[Route(
        '/admin/events/{id}/tags',
        name: 'admin_photo_tags',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function overview(Event $event): Response
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);

        $byType = [];
        foreach ($this->attributes->aggregateForEvent($event) as $row) {
            $byType[$row['type']][] = ['value' => $row['value'], 'count' => $row['count']];
        }

        $groups = [];
        foreach (self::GROUPS as $type => $meta) {
            $groups[] = [
                'label' => $meta['label'],
                'param' => $meta['param'],
                'multi' => $meta['multi'],
                'items' => $byType[$type] ?? [],
            ];
        }

        return $this->render('admin/event/photo_tags.html.twig', [
            'event'  => $event,
            'groups' => $groups,
        ]);
    }
}
