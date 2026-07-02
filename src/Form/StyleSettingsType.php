<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\StyleSettings;
use App\Service\Style\ResolvedStyle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<StyleSettings>
 */
final class StyleSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $hex = [new Assert\Regex(pattern: StyleSettings::HEX_PATTERN, message: 'Use a #RRGGBB hex color.')];

        $builder
            ->add('customFontColor', CheckboxType::class, [
                'mapped' => false, 'required' => false, 'label' => 'Customize font color',
            ])
            ->add('fontColor', TextType::class, ['required' => false, 'constraints' => $hex])
            ->add('customBackgroundColor', CheckboxType::class, [
                'mapped' => false, 'required' => false, 'label' => 'Customize background color',
            ])
            ->add('backgroundColor', TextType::class, ['required' => false, 'constraints' => $hex])
            ->add('customButtonColor', CheckboxType::class, [
                'mapped' => false, 'required' => false, 'label' => 'Customize button color',
            ])
            ->add('buttonColor', TextType::class, ['required' => false, 'constraints' => $hex])
            // Glow is a nullable bool (inherit / on / off), so a single tri-state
            // select is clearer than an override checkbox + a value checkbox.
            ->add('glowMode', ChoiceType::class, [
                'mapped'   => false,
                'label'    => false,
                'choices'  => ['Inherit' => 'inherit', 'On' => 'on', 'Off' => 'off'],
            ]);

        $builder->addEventListener(FormEvents::POST_SET_DATA, $this->initOverrideCheckboxes(...));
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->applyOverrides(...));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StyleSettings::class,
            'inherited'  => null,
        ]);
        $resolver->setAllowedTypes('inherited', ['null', ResolvedStyle::class]);
    }

    private function initOverrideCheckboxes(FormEvent $event): void
    {
        $style = $event->getData();
        $form  = $event->getForm();
        if (!$style instanceof StyleSettings) {
            return;
        }

        $form->get('customFontColor')->setData($style->getFontColor() !== null);
        $form->get('customBackgroundColor')->setData($style->getBackgroundColor() !== null);
        $form->get('customButtonColor')->setData($style->getButtonColor() !== null);

        $glow = $style->getGlowEnabled();
        $form->get('glowMode')->setData($glow === null ? 'inherit' : ($glow ? 'on' : 'off'));
    }

    private function applyOverrides(FormEvent $event): void
    {
        $style = $event->getData();
        $form  = $event->getForm();
        if (!$style instanceof StyleSettings) {
            return;
        }

        $style->setFontColor(
            $form->get('customFontColor')->getData() === true
                ? $this->asString($form->get('fontColor')->getData())
                : null
        );
        $style->setBackgroundColor(
            $form->get('customBackgroundColor')->getData() === true
                ? $this->asString($form->get('backgroundColor')->getData())
                : null
        );
        $style->setButtonColor(
            $form->get('customButtonColor')->getData() === true
                ? $this->asString($form->get('buttonColor')->getData())
                : null
        );
        $style->setGlowEnabled(match ($form->get('glowMode')->getData()) {
            'on'    => true,
            'off'   => false,
            default => null,
        });
    }

    private function asString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
