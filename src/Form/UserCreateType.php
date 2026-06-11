<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class UserCreateType extends AbstractType
{
    public const array ROLE_CHOICES = [
        'User'      => 'ROLE_USER',
        'Organizer' => 'ROLE_ORGANIZER',
        'Admin'     => 'ROLE_ADMIN',
    ];

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
            ->add('role', ChoiceType::class, [
                'label'       => 'Role',
                'choices'     => self::ROLE_CHOICES,
                'expanded'    => true,
                'multiple'    => false,
                'data'        => 'ROLE_ORGANIZER',
                'constraints' => [new NotBlank()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
