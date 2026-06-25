<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\Attribute\Audited;
use App\Entity\UserSession;
use App\Repository\UserRepository;
use App\Repository\UserSessionRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class UserSessionController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserSessionRepository $sessions,
        private readonly Connection $connection,
        private readonly AuditContext $audit,
    ) {
    }

    #[Route('/admin/users/{id}/sessions', name: 'admin_user_sessions_index', methods: ['GET'])]
    public function index(int $id): Response
    {
        $user = $this->users->find($id);
        if ($user === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/user_sessions/index.html.twig', [
            'target' => $user,
            'sessions' => $this->sessions->findForUserOrderedByActivity($user),
        ]);
    }

    #[Route('/admin/users/{id}/sessions/{sessId}/revoke', name: 'admin_user_sessions_revoke', methods: ['POST'])]
    #[Audited(AuditAction::SessionRevoke, targetParam: 'id', targetType: 'User')]
    public function revoke(int $id, string $sessId, Request $request): RedirectResponse
    {
        $user = $this->users->find($id);
        if ($user === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('user_session_revoke', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $session = $this->sessions->findOneBySessId($sessId);
        if (!$session instanceof UserSession || $session->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        $this->connection->executeStatement('DELETE FROM sessions WHERE sess_id = :id', ['id' => $sessId]);

        $this->audit->set('sess_id', $sessId);

        $this->addFlash('success', 'Session revoked.');
        return $this->redirectToRoute('admin_user_sessions_index', ['id' => $id]);
    }
}
