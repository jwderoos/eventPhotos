<?php

declare(strict_types=1);

namespace App\Tests\Integration\Form;

use App\Entity\StyleSettings;
use App\Form\StyleSettingsType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Verifies the per-field "override" checkboxes: an unchecked custom* box writes
 * null onto the embeddable (inherit), a checked one persists the submitted value.
 *
 * Uses the real container form factory (KernelTestCase) rather than a flat
 * TypeTestCase — the repo convention for form tests, and it wires the real
 * validator for the fields' Regex constraints without a hand-built extension.
 */
final class StyleSettingsTypeTest extends KernelTestCase
{
    private FormFactoryInterface $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get('form.factory');
        $this->assertInstanceOf(FormFactoryInterface::class, $factory);
        $this->factory = $factory;
    }

    public function testOverrideOffStoresNull(): void
    {
        $model = new StyleSettings();
        $form  = $this->factory->create(StyleSettingsType::class, $model);

        $form->submit([
            'customFontColor'       => false,
            'fontColor'             => '#123456',
            'customBackgroundColor' => false,
            'backgroundColor'       => '#654321',
            'customButtonColor'     => false,
            'buttonColor'           => '#abcdef',
            'glowMode'              => 'inherit',
        ]);

        $this->assertTrue($form->isSynchronized());
        $this->assertNull($model->getFontColor());
        $this->assertNull($model->getBackgroundColor());
        $this->assertNull($model->getButtonColor());
        $this->assertNull($model->getGlowEnabled());
    }

    public function testOverrideOnStoresValue(): void
    {
        $model = new StyleSettings();
        $form  = $this->factory->create(StyleSettingsType::class, $model);

        $form->submit([
            'customFontColor'       => true,
            'fontColor'             => '#123456',
            'customBackgroundColor' => false,
            'backgroundColor'       => '#000000',
            'customButtonColor'     => true,
            'buttonColor'           => '#FF6B35',
            'glowMode'              => 'on',
        ]);

        $this->assertTrue($form->isSynchronized());
        $this->assertSame('#123456', $model->getFontColor());
        $this->assertNull($model->getBackgroundColor());
        $this->assertSame('#FF6B35', $model->getButtonColor());
        $this->assertTrue($model->getGlowEnabled());
    }

    public function testGlowModeOffStoresFalse(): void
    {
        $model = new StyleSettings();
        $form  = $this->factory->create(StyleSettingsType::class, $model);

        $form->submit([
            'customFontColor'       => false,
            'customBackgroundColor' => false,
            'customButtonColor'     => false,
            'glowMode'              => 'off',
        ]);

        $this->assertTrue($form->isSynchronized());
        $this->assertFalse($model->getGlowEnabled());
    }
}
