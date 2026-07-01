<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\Attribute\Audited;
use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Entity\UserMailConfigAudit;
use App\Enum\MailConfigAuditAction;
use App\Enum\MailProvider;
use App\Form\UserMailConfigType;
use App\Repository\UserMailConfigRepository;
use App\Service\Mail\DsnRejected;
use App\Service\Mail\DsnValidator;
use App\Service\Mail\DsnVault;
use App\Service\Mail\GmailDsnFactory;
use App\Service\Mail\TransportBuilder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class AccountMailController extends AbstractController
{
    private const int VERIFICATION_TTL_SECONDS = 86_400;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserMailConfigRepository $configs,
        private readonly DsnValidator $validator,
        private readonly DsnVault $vault,
        private readonly TransportBuilder $transports,
        private readonly GmailDsnFactory $gmailDsn,
        private readonly AuditContext $audit,
    ) {
    }

    #[Route('/admin/account/mail', name: 'admin_account_mail_edit', methods: ['GET'])]
    public function edit(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $config = $user->getMailConfig();
        $form = $this->createForm(UserMailConfigType::class, null, [
            'action' => $this->generateUrl('admin_account_mail_update'),
            'provider' => $config?->getProvider()->value ?? MailProvider::Custom->value,
        ]);

        return $this->render('admin/account/mail/edit.html.twig', [
            'form' => $form->createView(),
            'config' => $config,
        ]);
    }

    #[Route('/admin/account/mail', name: 'admin_account_mail_update', methods: ['POST'])]
    #[Audited(AuditAction::MailConfigUpdate, targetParam: null)]
    public function update(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(UserMailConfigType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/account/mail/edit.html.twig', [
                'form' => $form->createView(),
                'config' => $user->getMailConfig(),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $providerRaw = $form->get('provider')->getData();
        $provider = $providerRaw === MailProvider::Gmail->value ? MailProvider::Gmail : MailProvider::Custom;
        $fromNameRaw = $form->get('fromName')->getData();
        $fromName = is_string($fromNameRaw) && $fromNameRaw !== '' ? $fromNameRaw : null;

        if ($provider === MailProvider::Gmail) {
            $emailRaw = $form->get('gmailEmail')->getData();
            $appPwRaw = $form->get('gmailAppPassword')->getData();
            $email = is_string($emailRaw) ? $emailRaw : '';
            $appPw = is_string($appPwRaw) ? $appPwRaw : '';
            $dsn = $this->gmailDsn->build($email, $appPw);
            $fromAddrRaw = $form->get('fromAddr')->getData();
            $fromAddr = is_string($fromAddrRaw) && $fromAddrRaw !== '' ? $fromAddrRaw : $email;
        } else {
            $dsnRaw = $form->get('dsn')->getData();
            $fromAddrRaw = $form->get('fromAddr')->getData();
            $dsn = is_string($dsnRaw) ? $dsnRaw : '';
            $fromAddr = is_string($fromAddrRaw) ? $fromAddrRaw : '';
        }

        try {
            $this->validator->validate($dsn);
        } catch (DsnRejected $dsnRejected) {
            $errorField = $provider === MailProvider::Gmail ? 'gmailAppPassword' : 'dsn';
            $form->get($errorField)->addError(new FormError($dsnRejected->getMessage()));

            return $this->render('admin/account/mail/edit.html.twig', [
                'form' => $form->createView(),
                'config' => $user->getMailConfig(),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $envelope = $this->vault->encrypt($dsn);
        $config = $user->getMailConfig();
        if ($config === null) {
            $config = new UserMailConfig($user, $envelope, $fromAddr, $fromName, $provider);
            $this->em->persist($config);
        } else {
            $config->applyConfig($envelope, $fromAddr, $fromName, $provider);
        }

        $this->em->persist(new UserMailConfigAudit(
            user: $user,
            actor: $user,
            actorEmailSnapshot: $user->getEmail(),
            action: MailConfigAuditAction::Set,
            fromAddrSnapshot: $fromAddr,
        ));
        $this->em->flush();

        $this->audit->set('from_addr', $fromAddr);
        $this->audit->targetLabel($user->getEmail());

        try {
            $this->sendVerification($config, $dsn);
            $this->addFlash('success', sprintf(
                'Verification email sent to %s. Click the link within 24 hours.',
                $fromAddr,
            ));
        } catch (DsnRejected $dsnRejected) {
            $this->addFlash('warning', sprintf(
                'Mail configuration saved but verification email could not be sent: %s.'
                . ' Click "Resend verification" to retry.',
                $dsnRejected->getMessage(),
            ));
        } catch (TransportExceptionInterface $transportException) {
            $this->addFlash('warning', sprintf(
                'Mail configuration saved but verification email could not be delivered: %s.'
                . ' Click "Resend verification" to retry.',
                $transportException->getMessage(),
            ));
        }

        return $this->redirectToRoute('admin_account_mail_edit');
    }

    #[Route(
        '/admin/account/mail/verify/{token}',
        name: 'admin_account_mail_verify',
        requirements: ['token' => '[A-Za-z0-9_\-]{16,128}'],
        methods: ['GET'],
    )]
    #[Audited(AuditAction::MailConfigVerify, targetParam: null)]
    public function verify(string $token): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $config = $this->configs->findOneByVerificationToken($token);

        if (!$config instanceof UserMailConfig || !$config->getVerificationSentAt() instanceof DateTimeImmutable) {
            throw $this->createNotFoundException();
        }

        if ($config->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }

        $age = new DateTimeImmutable()->getTimestamp() - $config->getVerificationSentAt()->getTimestamp();
        if ($age > self::VERIFICATION_TTL_SECONDS) {
            $this->addFlash('warning', 'Verification link expired. Use "Resend verification" to generate a new one.');

            return $this->redirectToRoute('admin_account_mail_edit');
        }

        $config->markVerified();
        $this->em->persist(new UserMailConfigAudit(
            user: $user,
            actor: $user,
            actorEmailSnapshot: $user->getEmail(),
            action: MailConfigAuditAction::Verified,
            fromAddrSnapshot: $config->getFromAddr(),
        ));
        $this->em->flush();

        $this->audit->set('from_addr', $config->getFromAddr());
        $this->audit->targetLabel($user->getEmail());

        $this->addFlash(
            'success',
            'Mail configuration verified. Event emails will now be sent from your configured address.',
        );

        return $this->redirectToRoute('admin_account_mail_edit');
    }

    private function sendVerification(UserMailConfig $config, string $dsn): void
    {
        $token = (string) $config->getVerificationToken();
        $verifyUrl = $this->generateUrl(
            'admin_account_mail_verify',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $from = $config->getFromName() !== null
            ? new Address($config->getFromAddr(), $config->getFromName())
            : new Address($config->getFromAddr());

        $email = new TemplatedEmail()
            ->from($from)
            ->to($config->getFromAddr())
            ->subject('Verify your eventPhotos mail configuration')
            ->htmlTemplate('email/mail-config/verify.html.twig')
            ->textTemplate('email/mail-config/verify.txt.twig')
            ->context(['verifyUrl' => $verifyUrl]);

        $mailer = $this->transports->fromDsn($dsn);
        $mailer->send($email);
    }

    #[Route('/admin/account/mail/resend', name: 'admin_account_mail_resend', methods: ['POST'])]
    #[Audited(AuditAction::MailConfigResend, targetParam: null)]
    public function resendVerification(Request $request): RedirectResponse
    {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('mail_config_resend', $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $user */
        $user = $this->getUser();
        $config = $user->getMailConfig();
        if (!$config instanceof UserMailConfig) {
            throw $this->createNotFoundException();
        }

        $config->regenerateVerificationToken();
        $this->em->persist(new UserMailConfigAudit(
            user: $user,
            actor: $user,
            actorEmailSnapshot: $user->getEmail(),
            action: MailConfigAuditAction::VerificationResent,
            fromAddrSnapshot: $config->getFromAddr(),
        ));
        $this->em->flush();

        $this->audit->set('from_addr', $config->getFromAddr());
        $this->audit->targetLabel($user->getEmail());

        try {
            $dsn = $this->vault->decrypt($config->getEncryptedDsn());
            $this->sendVerification($config, $dsn);
            $this->addFlash('success', sprintf('Verification email resent to %s.', $config->getFromAddr()));
        } catch (DsnRejected $dsnRejected) {
            $this->addFlash('warning', sprintf(
                'Could not send verification email: %s',
                $dsnRejected->getMessage(),
            ));
        } catch (TransportExceptionInterface $transportException) {
            $this->addFlash('warning', sprintf(
                'Could not deliver verification email: %s',
                $transportException->getMessage(),
            ));
        }

        return $this->redirectToRoute('admin_account_mail_edit');
    }

    #[Route('/admin/account/mail/clear', name: 'admin_account_mail_clear', methods: ['POST'])]
    #[Audited(AuditAction::MailConfigClear, targetParam: null)]
    public function clear(Request $request): RedirectResponse
    {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('mail_config_clear', $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $user */
        $user = $this->getUser();
        $config = $user->getMailConfig();
        if (!$config instanceof UserMailConfig) {
            return $this->redirectToRoute('admin_account_mail_edit');
        }

        $fromAddrSnapshot = $config->getFromAddr();
        $user->setMailConfig(null);
        $this->em->remove($config);
        $this->em->persist(new UserMailConfigAudit(
            user: $user,
            actor: $user,
            actorEmailSnapshot: $user->getEmail(),
            action: MailConfigAuditAction::Cleared,
            fromAddrSnapshot: $fromAddrSnapshot,
        ));
        $this->em->flush();

        $this->audit->set('from_addr', $fromAddrSnapshot);
        $this->audit->targetLabel($user->getEmail());

        $this->addFlash(
            'success',
            'Mail configuration cleared. Without a verified configuration, event mail cannot be sent.',
        );

        return $this->redirectToRoute('admin_account_mail_edit');
    }
}
