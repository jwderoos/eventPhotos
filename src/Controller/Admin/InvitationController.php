<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\Attribute\Audited;
use App\Entity\Invitation;
use App\Entity\User;
use App\Form\InvitationCreateType;
use App\Repository\InvitationRepository;
use App\Service\Invitation\InvitationTokenService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class InvitationController extends AbstractController
{
    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly EntityManagerInterface $em,
        private readonly InvitationTokenService $tokens,
        private readonly LoggerInterface $logger,
        private readonly AuditContext $audit,
    ) {
    }

    #[Route('/admin/invites', name: 'admin_invite_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $session = $request->getSession();
        $newUrl = null;
        if ($session->has('invitation.new_url')) {
            $value = $session->get('invitation.new_url');
            $newUrl = is_string($value) ? $value : null;
            $session->remove('invitation.new_url');
        }

        return $this->render('admin/invitation/index.html.twig', [
            'invitations' => $this->invitations->findAllOrderedByCreated(),
            'newUrl'      => $newUrl,
        ]);
    }

    #[Route('/admin/invites/new', name: 'admin_invite_new', methods: ['GET', 'POST'])]
    #[Audited(AuditAction::InviteCreate, targetParam: null)]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(InvitationCreateType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{role: string, expiresInDays: int} $data */
            $data = $form->getData();

            $generated = $this->tokens->generate();
            $invite = new Invitation(
                selector: $generated->selector,
                hashedVerifier: $generated->hashedVerifier,
                role: $data['role'],
                createdBy: $this->getCurrentAdmin(),
                expiresAt: new DateTimeImmutable('+' . $data['expiresInDays'] . ' days'),
            );

            $this->em->persist($invite);
            $this->em->flush();

            $this->audit->set('created_id', $invite->getId());
            $this->audit->set('role', $invite->getRole());
            $this->audit->targetLabel('invite#' . $invite->getId() . ' (' . $invite->getRole() . ')');

            $url = $this->generateUrl(
                'public_invite_redeem',
                ['token' => $generated->plaintext],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );

            $request->getSession()->set('invitation.new_url', $url);

            $this->logger->info('invite.created', [
                'invite_id'     => $invite->getId(),
                'role'          => $invite->getRole(),
                'created_by_id' => $invite->getCreatedBy()->getId(),
                'expires_at'    => $invite->getExpiresAt()->format(DateTimeImmutable::ATOM),
            ]);

            return new RedirectResponse($this->generateUrl('admin_invite_index'));
        }

        $status = $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('admin/invitation/new.html.twig', [
            'form' => $form,
        ], new Response(null, $status));
    }

    #[Route(
        '/admin/invites/{id}/revoke',
        name: 'admin_invite_revoke',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[Audited(AuditAction::InviteRevoke, targetParam: 'id', targetType: 'Invitation')]
    public function revoke(Invitation $invite, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('invite_revoke_' . $invite->getId(), $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$invite->isPending()) {
            $this->addFlash('warning', sprintf(
                'Invite is already %s — nothing to revoke.',
                $invite->status()->value,
            ));
            $this->audit->suppress();
            return new RedirectResponse($this->generateUrl('admin_invite_index'));
        }

        $this->audit->targetLabel('invite#' . $invite->getId() . ' (' . $invite->getRole() . ')');
        $invite->revoke($this->getCurrentAdmin());
        $this->em->flush();

        $this->logger->info('invite.revoked', [
            'invite_id'     => $invite->getId(),
            'revoked_by_id' => $this->getCurrentAdmin()->getId(),
        ]);

        $this->addFlash('success', 'Invite revoked.');
        return new RedirectResponse($this->generateUrl('admin_invite_index'));
    }

    private function getCurrentAdmin(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new LogicException('Authenticated user expected.');
        }

        return $user;
    }
}
