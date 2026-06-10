<?php

declare(strict_types=1);

namespace App\Form;

use Doctrine\ORM\QueryBuilder;
use App\Entity\Event;
use App\Entity\EventCollection;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Vich\UploaderBundle\Form\Type\VichFileType;

/**
 * @extends AbstractType<Event>
 */
final class EventType extends AbstractType
{
    public function __construct(private readonly Security $security)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('slug', TextType::class, [
                'help' => 'Used in the public QR URL: /e/{slug}',
            ])
            ->add('name', TextType::class)
            ->add('description', TextareaType::class, ['required' => false])
            ->add('date', DateType::class, ['widget' => 'single_text'])
            ->add('startsAt', DateTimeType::class, [
                'widget'   => 'single_text',
                'required' => false,
            ])
            ->add('endsAt', DateTimeType::class, [
                'widget'   => 'single_text',
                'required' => false,
            ])
            ->add('defaultWindowMinutes', IntegerType::class, [
                'required' => false,
                'help'     => sprintf('Minutes around "now". Empty → default %d.', Event::DEFAULT_WINDOW_MINUTES),
            ]);

        $builder->add('logoFile', VichFileType::class, [
            'required'     => false,
            'label'        => 'Logo (PNG or JPEG, max 2 MB)',
            'allow_delete' => true,
            'download_uri' => false,
        ]);

        $user = $this->security->getUser();
        $isAdmin = $this->security->isGranted('ROLE_ADMIN');

        $builder->add('collection', EntityType::class, [
            'class'         => EventCollection::class,
            'choice_label'  => 'name',
            'required'      => false,
            'placeholder'   => '— none —',
            'query_builder' => static function (EntityRepository $repo) use ($user, $isAdmin): QueryBuilder {
                $qb = $repo->createQueryBuilder('c')->orderBy('c.name', 'ASC');

                if (!$isAdmin && $user instanceof User) {
                    $qb->andWhere('c.owner = :owner')->setParameter('owner', $user);
                }

                return $qb;
            },
        ]);

        if ($isAdmin) {
            $builder->add('owner', EntityType::class, [
                'class'        => User::class,
                'choice_label' => 'email',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Event::class]);
    }
}
