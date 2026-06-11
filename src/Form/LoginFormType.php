<?php

declare(strict_types=1);

namespace App\Form;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class LoginFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('_username', EmailType::class, [
                'label'    => 'Email',
                'data'     => $options['last_username'],
                'required' => true,
                'attr'     => [
                    'autocomplete' => 'email',
                    'autofocus'    => true,
                ],
            ])
            ->add('_password', PasswordType::class, [
                'label'    => 'Password',
                'required' => true,
                'attr'     => ['autocomplete' => 'current-password'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'last_username'   => '',
            'data_class'      => null,
            'csrf_field_name' => '_csrf_token',
            'csrf_token_id'   => 'authenticate',
        ]);

        $resolver->setAllowedTypes('last_username', 'string');
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
