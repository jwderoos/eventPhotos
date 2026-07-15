<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\PreviewSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PreviewSettings>
 */
final class PreviewSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('longEdge', ChoiceType::class, [
                'label'   => 'Display image size',
                'choices' => $this->labelledChoices(PreviewSettings::ALLOWED_LONG_EDGES, ' px'),
                'help'    => 'Long edge of the shared display image. Larger looks sharper but costs '
                    . 'more storage and bandwidth.',
            ])
            ->add('quality', ChoiceType::class, [
                'label'   => 'Display image quality',
                'choices' => $this->labelledChoices(PreviewSettings::ALLOWED_QUALITIES, ''),
                'help'    => 'JPEG quality of the shared display image.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PreviewSettings::class]);
    }

    /**
     * @param list<int> $values
     *
     * @return array<string, int>
     */
    private function labelledChoices(array $values, string $suffix): array
    {
        $choices = [];
        foreach ($values as $value) {
            $choices[$value . $suffix] = $value;
        }

        return $choices;
    }
}
