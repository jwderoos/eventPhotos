<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditAction;
use App\Repository\AuditLogEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AuditLogController extends AbstractController
{
    private const int PER_PAGE = 50;

    public function __construct(private readonly AuditLogEntryRepository $repository)
    {
    }

    #[Route('/admin/audit', name: 'admin_audit_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));

        $filters = [];
        $action = $request->query->getString('action');
        if ($action !== '' && AuditAction::tryFrom($action) !== null) {
            $filters['action'] = $action;
        }

        $actorId = $request->query->getInt('actorId');
        if ($actorId > 0) {
            $filters['actorId'] = $actorId;
        }

        $targetType = trim($request->query->getString('targetType'));
        if ($targetType !== '') {
            $filters['targetType'] = $targetType;
        }

        $targetId = $request->query->getInt('targetId');
        if ($targetId > 0) {
            $filters['targetId'] = $targetId;
        }

        $result = $this->repository->findFiltered($filters, $page, self::PER_PAGE);

        return $this->render('admin/audit/index.html.twig', [
            'entries' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'actions' => AuditAction::cases(),
            'filters' => $filters,
        ]);
    }
}
