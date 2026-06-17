<?php

declare(strict_types=1);

namespace App\Controller\Account;

use Symfony\Component\HttpFoundation\RedirectResponse;
use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserSessionRepository;
use App\Security\Voter\UserSessionVoter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class SessionController extends AbstractController
{
    private const int MAX_LABEL_LENGTH = 64;

    public function __construct(
        private readonly UserSessionRepository $repo,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/account/sessions', name: 'account_sessions_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sessions = $this->repo->findForUserOrderedByActivity($user);

        $currentSessId = $request->hasSession(true) ? $request->getSession()->getId() : null;

        return $this->render('account/sessions/index.html.twig', [
            'sessions' => $sessions,
            'current_sess_id' => $currentSessId,
        ]);
    }

    // STUBS — implemented in Tasks 13-15. Routes registered now so template path() calls work.

    #[Route('/account/sessions/{sessId}/revoke', name: 'account_sessions_revoke', methods: ['POST'])]
    public function revoke(string $sessId, Request $request): RedirectResponse
    {
        $session = $this->repo->findOneBySessId($sessId);
        if (!$session instanceof UserSession) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(UserSessionVoter::MANAGE, $session);

        if (!$this->isCsrfTokenValid('user_session_revoke', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $this->connection->executeStatement('DELETE FROM sessions WHERE sess_id = :id', ['id' => $sessId]);

        $this->addFlash('success', 'Session revoked.');
        return $this->redirectToRoute('account_sessions_index');
    }

    #[Route('/account/sessions/revoke-others', name: 'account_sessions_revoke_others', methods: ['POST'])]
    public function revokeOthers(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('user_session_revoke_others', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        /** @var User $user */
        $user = $this->getUser();
        $currentSessId = $request->getSession()->getId();

        $this->connection->executeStatement(
            'DELETE FROM sessions WHERE sess_id IN (
                SELECT sess_id FROM user_sessions WHERE user_id = :uid AND sess_id != :current
             )',
            ['uid' => $user->getId(), 'current' => $currentSessId],
        );

        $this->addFlash('success', 'All other sessions have been signed out.');
        return $this->redirectToRoute('account_sessions_index');
    }

    #[Route('/account/sessions/{sessId}/label', name: 'account_sessions_label', methods: ['POST'])]
    public function label(string $sessId, Request $request): RedirectResponse
    {
        $session = $this->repo->findOneBySessId($sessId);
        if (!$session instanceof UserSession) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(UserSessionVoter::MANAGE, $session);

        if (!$this->isCsrfTokenValid('user_session_label', (string) $request->request->get('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $label = (string) $request->request->get('label', '');
        if (strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new BadRequestHttpException('Label must be 64 characters or fewer.');
        }

        $session->setLabel($label === '' ? null : $label);
        $this->em->flush();

        $this->addFlash('success', $session->getLabel() === null ? 'Label cleared.' : 'Label updated.');
        return $this->redirectToRoute('account_sessions_index');
    }
}
