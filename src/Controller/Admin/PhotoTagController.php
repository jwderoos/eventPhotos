<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\Attribute\Audited;
use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Entity\BibSuppression;
use App\Entity\Event;
use App\Entity\PhotoAttributeType;
use App\Repository\BibSuppressionRepository;
use App\Repository\PhotoAttributeRepository;
use App\Security\Voter\EventVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
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

    public function __construct(
        private readonly PhotoAttributeRepository $attributes,
        private readonly BibSuppressionRepository $bibSuppressions,
        private readonly EntityManagerInterface $em,
        private readonly AuditContext $audit,
    ) {
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

        $suppressed    = $this->bibSuppressions->suppressedBibNumbers($event);
        $suppressedSet = array_flip($suppressed);

        $byType = [];
        foreach ($this->attributes->aggregateForEvent($event) as $row) {
            $byType[$row['type']][] = ['value' => $row['value'], 'count' => $row['count']];
        }

        // Photo counts for de-indexed bib chips (rows still exist under the overlay model).
        $bibCounts = [];
        foreach ($byType['bib'] ?? [] as $item) {
            $bibCounts[$item['value']] = $item['count'];
        }

        $groups = [];
        foreach (self::GROUPS as $type => $meta) {
            $items = $byType[$type] ?? [];
            if ($type === 'bib') {
                $items = array_values(array_filter(
                    $items,
                    static fn (array $i): bool => !isset($suppressedSet[$i['value']]),
                ));
            }

            $groups[] = [
                'type'  => $type,
                'label' => $meta['label'],
                'param' => $meta['param'],
                'multi' => $meta['multi'],
                'items' => $items,
            ];
        }

        // De-indexed list is driven by the suppression table so preemptive
        // (zero-count) de-indexes still appear.
        $deindexedBibs = [];
        foreach ($suppressed as $bib) {
            $deindexedBibs[] = ['value' => $bib, 'count' => $bibCounts[$bib] ?? 0];
        }

        return $this->render('admin/event/photo_tags.html.twig', [
            'event'         => $event,
            'groups'        => $groups,
            'deindexedBibs' => $deindexedBibs,
        ]);
    }

    #[Route(
        '/admin/events/{id}/bib-suppressions',
        name: 'admin_bib_suppress',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::EventBibSuppress, targetParam: 'id', targetType: 'Event')]
    public function suppressBib(Event $event, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);
        $this->assertCsrf($request, 'suppress_bib_' . $event->getId());

        $bibNumber = trim((string) $request->request->get('bibNumber'));
        if ($bibNumber === '') {
            $this->addFlash('error', 'Enter a bib number to de-index.');

            return $this->redirectToRoute('admin_photo_tags', ['id' => $event->getId()]);
        }

        if (mb_strlen($bibNumber) > BibSuppression::MAX_BIB_NUMBER_LENGTH) {
            $this->addFlash('error', 'Bib number is too long.');

            return $this->redirectToRoute('admin_photo_tags', ['id' => $event->getId()]);
        }

        // Reversible overlay: flag only, never delete PhotoAttribute rows.
        if (!$this->bibSuppressions->isSuppressed($event, $bibNumber)) {
            $this->em->persist(new BibSuppression($event, $bibNumber));
            $this->em->flush();
        }

        $this->audit->set('suppressed_bib', $bibNumber);
        $this->addFlash('success', sprintf('Bib %s is no longer indexed.', $bibNumber));

        return $this->redirectToRoute('admin_photo_tags', ['id' => $event->getId()]);
    }

    #[Route(
        '/admin/events/{id}/bib-reindex',
        name: 'admin_bib_reindex',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::EventBibReindex, targetParam: 'id', targetType: 'Event')]
    public function reindexBib(Event $event, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EventVoter::EDIT, $event);
        $this->assertCsrf($request, 'reindex_bib_' . $event->getId());

        $bibNumber = trim((string) $request->request->get('bibNumber'));
        $existing  = $bibNumber === ''
            ? null
            : $this->bibSuppressions->findOneBy(['event' => $event, 'bibNumber' => $bibNumber]);

        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();
            $this->audit->set('reindexed_bib', $bibNumber);
            $this->addFlash('success', sprintf('Bib %s is indexed again.', $bibNumber));
        } else {
            // Nothing to undo — don't log a spurious audit row for a no-op.
            $this->audit->suppress();
            $this->addFlash('info', 'Nothing to re-index.');
        }

        return $this->redirectToRoute('admin_photo_tags', ['id' => $event->getId()]);
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid($tokenId, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
