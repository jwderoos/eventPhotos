<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class UserEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label'       => 'Display name',
                'constraints' => [new NotBlank()],
            ]);

        if ($options['can_edit_role'] === true) {
            $builder->add('role', ChoiceType::class, [
                'label'    => 'Role',
                'choices'  => UserCreateType::ROLE_CHOICES,
                'expanded' => true,
                'multiple' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => null, 'can_edit_role' => true])
            ->setAllowedTypes('can_edit_role', 'bool');
    }
}
