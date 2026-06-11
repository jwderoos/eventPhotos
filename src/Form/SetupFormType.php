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
final class SetupFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label'       => 'Email',
                'constraints' => [
                    new NotBlank(message: 'Please enter an email address.'),
                    new Email(),
                ],
            ])
            ->add('displayName', TextType::class, [
                'label'       => 'Display name',
                'constraints' => [new NotBlank(message: 'Please enter a display name.')],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type'            => PasswordType::class,
                'mapped'          => false,
                'invalid_message' => 'The password fields must match.',
                'first_options'   => [
                    'label'       => 'Password',
                    'attr'        => ['autocomplete' => 'new-password'],
                    'constraints' => [
                        new NotBlank(message: 'Please enter a password.'),
                        new Length(min: 12, minMessage: 'Your password must be at least 12 characters long.'),
                    ],
                ],
                'second_options' => [
                    'label' => 'Repeat password',
                    'attr'  => ['autocomplete' => 'new-password'],
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
