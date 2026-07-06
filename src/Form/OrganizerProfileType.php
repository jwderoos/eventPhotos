<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\OrganizerProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Vich\UploaderBundle\Form\Type\VichImageType;

/**
 * @extends AbstractType<OrganizerProfile>
 */
final class OrganizerProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('brandLabel', TextType::class, [
            'required' => false,
            'label'    => 'Brand label',
            'help'     => 'Shown in the header of your public event pages.',
        ]);

        $builder->add('brandLogoFile', VichImageType::class, [
            'required'      => false,
            'label'         => 'Brand logo (PNG or JPEG, max 2 MB)',
            'help'          => 'Transparent PNG recommended so it sits on any background.',
            'allow_delete'  => true,
            'download_uri'  => false,
            'image_uri'     => false,
        ]);

        $builder->add('brandUrl', UrlType::class, [
            'required'         => false,
            'label'            => 'Brand homepage URL',
            'default_protocol' => null,
        ]);

        $builder->add('style', StyleSettingsType::class, ['label' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => OrganizerProfile::class]);
    }
}
