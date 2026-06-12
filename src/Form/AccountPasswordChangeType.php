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
final class AccountPasswordChangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['require_current_password'] === true) {
            $builder->add('currentPassword', PasswordType::class, [
                'label' => 'Current password',
                'constraints' => [new NotBlank()],
                'mapped' => false,
            ]);
        }

        $builder->add('newPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'mapped' => false,
            'invalid_message' => 'Passwords do not match.',
            'first_options'  => ['label' => 'New password'],
            'second_options' => ['label' => 'Confirm new password'],
            'constraints' => [new NotBlank(), new Length(min: 8)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('require_current_password');
        $resolver->setAllowedTypes('require_current_password', 'bool');
    }
}
