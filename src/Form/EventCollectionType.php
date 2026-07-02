<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\EventCollection;
use App\Entity\User;
use App\Form\StyleSettingsType;
use App\Service\Style\ResolvedStyle;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<EventCollection>
 */
final class EventCollectionType extends AbstractType
{
    public function __construct(private readonly Security $security)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('slug', TextType::class)
            ->add('name', TextType::class)
            ->add('description', TextareaType::class, ['required' => false]);

        if ($this->security->isGranted('ROLE_ADMIN')) {
            $builder->add('owner', EntityType::class, [
                'class'        => User::class,
                'choice_label' => 'email',
            ]);
        }

        $builder->add('style', StyleSettingsType::class, [
            'label'     => false,
            'inherited' => $options['inherited'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EventCollection::class, 'inherited' => null]);
        $resolver->setAllowedTypes('inherited', ['null', ResolvedStyle::class]);
    }
}
