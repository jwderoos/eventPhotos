<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\UserMailConfig;
use App\Entity\UserMailConfigAudit;
use App\Enum\MailConfigAuditAction;
use App\Enum\MailProvider;
use App\Form\UserMailConfigType;
use App\Service\Mail\DsnRejected;
use App\Service\Mail\DsnValidator;
use App\Service\Mail\DsnVault;
use App\Service\Mail\GmailDsnFactory;
use App\Service\Mail\TransportBuilder;
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

#[IsGranted('ROLE_ADMIN')]
final class UserMailController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DsnValidator $validator,
        private readonly DsnVault $vault,
        private readonly TransportBuilder $transports,
        private readonly GmailDsnFactory $gmailDsn,
    ) {
    }

    #[Route(
        '/admin/users/{id}/mail',
        name: 'admin_user_mail_edit',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function edit(User $target): Response
    {
        $form = $this->createForm(UserMailConfigType::class, null, [
            'action' => $this->generateUrl('admin_user_mail_update', ['id' => $target->getId()]),
            'provider' => $target->getMailConfig()?->getProvider()->value ?? MailProvider::Custom->value,
        ]);

        return $this->render('admin/account/mail/edit.html.twig', [
            'form' => $form->createView(),
            'config' => $target->getMailConfig(),
            'target' => $target,
        ]);
    }

    #[Route(
        '/admin/users/{id}/mail',
        name: 'admin_user_mail_update',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function update(User $target, Request $request): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $form = $this->createForm(UserMailConfigType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/account/mail/edit.html.twig', [
                'form' => $form->createView(),
                'config' => $target->getMailConfig(),
                'target' => $target,
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
                'config' => $target->getMailConfig(),
                'target' => $target,
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $envelope = $this->vault->encrypt($dsn);
        $config = $target->getMailConfig();
        if (!$config instanceof UserMailConfig) {
            $config = new UserMailConfig($target, $envelope, $fromAddr, $fromName, $provider);
            $this->em->persist($config);
        } else {
            $config->applyConfig($envelope, $fromAddr, $fromName, $provider);
        }

        $this->em->persist(new UserMailConfigAudit(
            user: $target,
            actor: $actor,
            actorEmailSnapshot: $actor->getEmail(),
            action: MailConfigAuditAction::Set,
            fromAddrSnapshot: $fromAddr,
        ));
        $this->em->flush();

        try {
            $this->sendVerification($config, $dsn);
            $this->addFlash('success', sprintf('Verification email sent to %s.', $fromAddr));
        } catch (DsnRejected $dsnRejected) {
            $this->addFlash('warning', sprintf(
                'Mail configuration saved but verification email could not be sent: %s',
                $dsnRejected->getMessage(),
            ));
        } catch (TransportExceptionInterface $transportException) {
            $this->addFlash('warning', sprintf(
                'Mail configuration saved but verification email could not be delivered: %s',
                $transportException->getMessage(),
            ));
        }

        return $this->redirectToRoute('admin_user_mail_edit', ['id' => $target->getId()]);
    }

    #[Route(
        '/admin/users/{id}/mail/clear',
        name: 'admin_user_mail_clear',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function clear(User $target, Request $request): RedirectResponse
    {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('mail_config_clear', $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $actor */
        $actor = $this->getUser();
        $config = $target->getMailConfig();
        if (!$config instanceof UserMailConfig) {
            return $this->redirectToRoute('admin_user_mail_edit', ['id' => $target->getId()]);
        }

        $fromAddrSnapshot = $config->getFromAddr();
        $target->setMailConfig(null);
        $this->em->remove($config);
        $this->em->persist(new UserMailConfigAudit(
            user: $target,
            actor: $actor,
            actorEmailSnapshot: $actor->getEmail(),
            action: MailConfigAuditAction::Cleared,
            fromAddrSnapshot: $fromAddrSnapshot,
        ));
        $this->em->flush();

        $this->addFlash('success', 'Cleared mail configuration for ' . $target->getEmail());

        return $this->redirectToRoute('admin_user_mail_edit', ['id' => $target->getId()]);
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
            ->subject('Verify your eventFotos mail configuration')
            ->htmlTemplate('email/mail-config/verify.html.twig')
            ->textTemplate('email/mail-config/verify.txt.twig')
            ->context(['verifyUrl' => $verifyUrl]);

        $mailer = $this->transports->fromDsn($dsn);
        $mailer->send($email);
    }
}
