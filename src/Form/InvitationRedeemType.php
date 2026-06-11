<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class InvitationRedeemType extends AbstractType
{
    public const int MIN_PASSWORD_LENGTH = 12;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label'       => 'Email',
                'constraints' => [new NotBlank(), new Email()],
            ])
            ->add('displayName', TextType::class, [
                'label'       => 'Display name',
                'constraints' => [new NotBlank()],
            ])
            ->add('password', RepeatedType::class, [
                'type'            => PasswordType::class,
                'invalid_message' => 'The password fields must match.',
                'first_options'   => [
                    'attr'        => ['autocomplete' => 'new-password'],
                    'label'       => 'Password',
                    'constraints' => [
                        new NotBlank(message: 'Please enter a password.'),
                        new Length(
                            min: self::MIN_PASSWORD_LENGTH,
                            minMessage: 'Your password must be at least {{ limit }} characters long.',
                        ),
                    ],
                ],
                'second_options'  => [
                    'attr'  => ['autocomplete' => 'new-password'],
                    'label' => 'Repeat password',
                ],
                'mapped'          => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
