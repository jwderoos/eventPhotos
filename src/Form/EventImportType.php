<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<null> */
final class EventImportType extends AbstractType
{
    private const string MAX_UPLOAD = '256M';

    public function __construct(private readonly Security $security)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('archive', FileType::class, [
            'label'       => 'Event archive (.zip)',
            'mapped'      => false,
            'constraints' => [
                new Assert\NotNull(message: 'Choose an archive to import.'),
                new Assert\File(
                    maxSize: self::MAX_UPLOAD,
                    mimeTypes: ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
                    mimeTypesMessage: 'Upload the .zip produced by Export.',
                ),
            ],
        ]);

        if ($this->security->isGranted('ROLE_ADMIN')) {
            $builder->add('owner', EntityType::class, [
                'class'        => User::class,
                'choice_label' => 'email',
                'mapped'       => false,
                'required'     => false,
                'placeholder'  => '— import under me —',
                'label'        => 'Assign to user (admin)',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
