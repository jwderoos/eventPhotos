<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class InvitationCreateType extends AbstractType
{
    public const array ROLE_CHOICES = [
        'Organizer' => 'ROLE_ORGANIZER',
        'Admin'     => 'ROLE_ADMIN',
    ];

    public const int MIN_DAYS = 1;

    public const int MAX_DAYS = 30;

    public const int DEFAULT_DAYS = 7;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('role', ChoiceType::class, [
                'label'       => 'Role',
                'choices'     => self::ROLE_CHOICES,
                'expanded'    => true,
                'multiple'    => false,
                'data'        => 'ROLE_ORGANIZER',
                'constraints' => [new NotBlank()],
            ])
            ->add('expiresInDays', IntegerType::class, [
                'label'       => 'Valid for (days)',
                'data'        => self::DEFAULT_DAYS,
                'constraints' => [
                    new NotBlank(),
                    new Range(min: self::MIN_DAYS, max: self::MAX_DAYS),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
