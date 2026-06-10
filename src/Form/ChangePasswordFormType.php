<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type'            => PasswordType::class,
            'mapped'          => false,
            'invalid_message' => 'The password fields must match.',
            'first_options'   => [
                'attr'        => ['autocomplete' => 'new-password'],
                'label'       => 'New password',
                'constraints' => [
                    new NotBlank(message: 'Please enter a password.'),
                    new Length(min: 12, minMessage: 'Your password must be at least 12 characters long.'),
                ],
            ],
            'second_options' => [
                'attr'  => ['autocomplete' => 'new-password'],
                'label' => 'Repeat password',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
