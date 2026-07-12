<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Event;
use App\Entity\EventCollection;
use App\Entity\User;
use App\Form\StyleSettingsType;
use App\Service\Style\ResolvedStyle;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Form\Type\VichFileType;

/**
 * @extends AbstractType<Event>
 */
final class EventType extends AbstractType
{
    private const string TIME_PATTERN = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    public function __construct(private readonly Security $security)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $timezones = DateTimeZone::listIdentifiers();

        $builder
            ->add('name', TextType::class)
            ->add('description', TextareaType::class, ['required' => false])
            ->add('eventDate', DateType::class, [
                'mapped'   => false,
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
                'required' => true,
                'label'    => 'Date',
            ])
            ->add('startTime', TextType::class, [
                'mapped'      => false,
                'required'    => true,
                'label'       => 'Start (HH:mm)',
                'attr'        => [
                    'placeholder'     => 'HH:mm',
                    'pattern'         => '[0-2]\d:[0-5]\d',
                    'data-controller' => 'time-input',
                    'data-action'     => 'blur->time-input#format change->time-input#format',
                ],
                'constraints' => [new Assert\Regex(self::TIME_PATTERN, 'Expected HH:mm.')],
            ])
            ->add('endTime', TextType::class, [
                'mapped'      => false,
                'required'    => true,
                'label'       => 'End (HH:mm) — rolls to next day if ≤ start',
                'attr'        => [
                    'placeholder'     => 'HH:mm',
                    'pattern'         => '[0-2]\d:[0-5]\d',
                    'data-controller' => 'time-input',
                    'data-action'     => 'blur->time-input#format change->time-input#format',
                ],
                'constraints' => [new Assert\Regex(self::TIME_PATTERN, 'Expected HH:mm.')],
            ])
            ->add('timezone', ChoiceType::class, [
                'choices' => array_combine($timezones, $timezones),
                'help'    => 'IANA zone for EXIF timestamps without an explicit offset.',
            ]);

        $builder->add('logoFile', VichFileType::class, [
            'required'     => false,
            'label'        => 'Logo (PNG or JPEG, max 2 MB)',
            'allow_delete' => true,
            'download_uri' => false,
        ]);

        $builder->add('bannerFile', FileType::class, [
            'mapped'      => false,
            'required'    => false,
            'label'       => 'Banner / hero image (JPEG or PNG, max 5 MB — recommended 1200×400, 3:1)',
            'constraints' => [
                new Assert\File(
                    maxSize: '5M',
                    mimeTypes: ['image/jpeg', 'image/png'],
                    mimeTypesMessage: 'Upload a JPEG or PNG image.',
                ),
            ],
        ]);

        $builder->add('removeBanner', CheckboxType::class, [
            'mapped'   => false,
            'required' => false,
            'label'    => 'Remove current banner',
        ]);

        $user    = $this->security->getUser();
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

        // Only offered when the owner has a verified mail transport. Kept unmapped so a
        // submit while mail is inactive (field absent) can never silently clear the flag,
        // and so the entity keeps its enable/disable domain API instead of a bare setter.
        if ($options['mail_active'] === true) {
            $builder->add('notificationsEnabled', CheckboxType::class, [
                'mapped'   => false,
                'required' => false,
                'label'    => 'Allow participants to request email updates',
                'help'     => "Shows an email sign-up form on the event's public page so "
                    . 'visitors can be notified when the photos go live.',
            ]);
        }

        $builder->add('style', StyleSettingsType::class, [
            'label'     => false,
            'inherited' => $options['inherited'],
        ]);

        $builder->addEventListener(FormEvents::POST_SET_DATA, $this->prefillUnmappedFields(...));
        $builder->addEventListener(FormEvents::PRE_SUBMIT, $this->normalizeTimeInputs(...));
        $builder->addEventListener(FormEvents::SUBMIT, $this->composeStartsAndEnds(...));
        $builder->addEventListener(FormEvents::SUBMIT, $this->applyNotificationsPreference(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Event::class, 'mail_active' => false, 'inherited' => null]);
        $resolver->setAllowedTypes('mail_active', 'bool');
        $resolver->setAllowedTypes('inherited', ['null', ResolvedStyle::class]);
    }

    private function prefillUnmappedFields(FormEvent $formEvent): void
    {
        $event = $formEvent->getData();
        if (!$event instanceof Event) {
            return;
        }

        $tz       = new DateTimeZone($event->getTimezone());
        $startsAt = $event->getStartsAt()->setTimezone($tz);
        $endsAt   = $event->getEndsAt()->setTimezone($tz);

        $form = $formEvent->getForm();
        $form->get('eventDate')->setData(new DateTimeImmutable($startsAt->format('Y-m-d')));
        $form->get('startTime')->setData($startsAt->format('H:i'));
        $form->get('endTime')->setData($endsAt->format('H:i'));

        if ($form->has('notificationsEnabled')) {
            $form->get('notificationsEnabled')->setData($event->areNotificationsEnabled());
        }
    }

    private function applyNotificationsPreference(FormEvent $formEvent): void
    {
        $event = $formEvent->getData();
        $form  = $formEvent->getForm();
        if (!$event instanceof Event || !$form->has('notificationsEnabled')) {
            return;
        }

        if ($form->get('notificationsEnabled')->getData() === true) {
            $event->enableNotifications();
        } else {
            $event->disableNotifications();
        }
    }

    private function composeStartsAndEnds(FormEvent $formEvent): void
    {
        $event = $formEvent->getData();
        if (!$event instanceof Event) {
            return;
        }

        $form      = $formEvent->getForm();
        $date      = $form->get('eventDate')->getData();
        $startTime = $form->get('startTime')->getData();
        $endTime   = $form->get('endTime')->getData();

        if (
            !$date instanceof DateTimeImmutable
            || !is_string($startTime) || preg_match(self::TIME_PATTERN, $startTime) !== 1
            || !is_string($endTime) || preg_match(self::TIME_PATTERN, $endTime) !== 1
        ) {
            return;
        }

        $tz       = new DateTimeZone($event->getTimezone());
        $day      = $date->format('Y-m-d');
        $startsAt = DateTimeImmutable::createFromFormat('Y-m-d H:i', sprintf('%s %s', $day, $startTime), $tz);
        $endsAt   = DateTimeImmutable::createFromFormat('Y-m-d H:i', sprintf('%s %s', $day, $endTime), $tz);

        if (!$startsAt instanceof DateTimeImmutable || !$endsAt instanceof DateTimeImmutable) {
            return;
        }

        if ($endsAt <= $startsAt) {
            $endsAt = $endsAt->modify('+1 day');
        }

        $event->setStartsAt($startsAt);
        $event->setEndsAt($endsAt);
    }

    /**
     * Lenient time input — pre-validation normalisation for #49.
     *
     * Reshapes 1–4 digit input to HH:mm so a user can type `0930` or `9` and
     * still satisfy the regex constraint. Already-formatted input is untouched;
     * anything else (e.g. `25:99`, `abc`) is left as-is so the regex still rejects it.
     */
    private function normalizeTimeInputs(FormEvent $formEvent): void
    {
        $data = $formEvent->getData();
        if (!is_array($data)) {
            return;
        }

        foreach (['startTime', 'endTime'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = $this->normalizeTimeString($data[$field]);
            }
        }

        $formEvent->setData($data);
    }

    private function normalizeTimeString(string $input): string
    {
        $trimmed = trim($input);

        if (preg_match('/^\d{1,2}$/', $trimmed) === 1) {
            return sprintf('%02d:00', (int) $trimmed);
        }

        if (preg_match('/^\d{3,4}$/', $trimmed) === 1) {
            $padded = str_pad($trimmed, 4, '0', STR_PAD_LEFT);

            return substr($padded, 0, 2) . ':' . substr($padded, 2, 2);
        }

        return $input;
    }
}
