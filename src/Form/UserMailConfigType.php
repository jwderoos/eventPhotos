<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<null>
 */
final class UserMailConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dsn', TextareaType::class, [
                'label' => 'SMTP DSN',
                'help' => 'Format: smtp://user:password@smtp.example.com:587 or smtps://...',
                'attr' => ['rows' => 2, 'spellcheck' => 'false', 'autocomplete' => 'off'],
                'mapped' => false,
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 1024),
                ],
            ])
            ->add('fromAddr', TextType::class, [
                'label' => 'From address',
                'mapped' => false,
                'constraints' => [
                    new NotBlank(),
                    new Email(),
                    new Length(max: 254),
                ],
            ])
            ->add('fromName', TextType::class, [
                'label' => 'From display name',
                'required' => false,
                'mapped' => false,
                'constraints' => [
                    new Length(max: 120),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'user_mail_config',
        ]);
    }
}
