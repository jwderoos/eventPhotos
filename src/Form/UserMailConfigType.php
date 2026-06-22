<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\MailProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;

/**
 * @extends AbstractType<null>
 */
final class UserMailConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('provider', ChoiceType::class, [
                'label' => 'Mail provider',
                'mapped' => false,
                'expanded' => true,
                'multiple' => false,
                'choices' => [
                    'Custom (SMTP DSN)' => MailProvider::Custom->value,
                    'Gmail' => MailProvider::Gmail->value,
                ],
                'data' => $options['provider'],
            ])
            ->add('dsn', TextareaType::class, [
                'label' => 'SMTP DSN',
                'help' => 'Format: smtp://user:password@smtp.example.com:587 or smtps://...',
                'attr' => ['rows' => 2, 'spellcheck' => 'false', 'autocomplete' => 'off'],
                'required' => false,
                'mapped' => false,
                'constraints' => [new Length(max: 1024)],
            ])
            ->add('gmailEmail', EmailType::class, [
                'label' => 'Gmail address',
                'help' => 'The address you sign in to Gmail with.',
                'attr' => ['autocomplete' => 'off'],
                'required' => false,
                'mapped' => false,
                'constraints' => [new Email(), new Length(max: 254)],
            ])
            ->add('gmailAppPassword', PasswordType::class, [
                'label' => 'App password',
                'help' => 'A 16-character Google app password (not your account password). '
                    . 'Create one at https://myaccount.google.com/apppasswords — requires 2-Step Verification.',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
                'always_empty' => false,
                'required' => false,
                'mapped' => false,
                'constraints' => [new Length(max: 256)],
            ])
            ->add('fromAddr', TextType::class, [
                'label' => 'From address',
                'required' => false,
                'mapped' => false,
                'constraints' => [new Email(), new Length(max: 254)],
            ])
            ->add('fromName', TextType::class, [
                'label' => 'From display name',
                'required' => false,
                'mapped' => false,
                'constraints' => [new Length(max: 120)],
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->validateProvider(...));
    }

    private function validateProvider(FormEvent $event): void
    {
        $form = $event->getForm();
        $provider = $form->get('provider')->getData();

        $blank = static function (string $field) use ($form): bool {
            $value = $form->get($field)->getData();

            return !is_string($value) || trim($value) === '';
        };

        if ($provider === MailProvider::Gmail->value) {
            if ($blank('gmailEmail')) {
                $form->get('gmailEmail')->addError(new FormError('Enter your Gmail address.'));
            }

            if ($blank('gmailAppPassword')) {
                $form->get('gmailAppPassword')->addError(new FormError('Enter your Google app password.'));
            }

            return;
        }

        if ($blank('dsn')) {
            $form->get('dsn')->addError(new FormError('Enter an SMTP DSN.'));
        }

        if ($blank('fromAddr')) {
            $form->get('fromAddr')->addError(new FormError('Enter a from address.'));
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'user_mail_config',
            'provider' => MailProvider::Custom->value,
        ]);
        $resolver->setAllowedTypes('provider', 'string');
    }
}
