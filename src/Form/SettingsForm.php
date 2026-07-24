<?php

declare(strict_types=1);

namespace Tvdt\Form;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Tvdt\Entity\SeasonSettings;

/** @extends AbstractType<SeasonSettings> */
class SettingsForm extends AbstractType
{
    /** @param list<string> $enabledLocales */
    public function __construct(
        #[Autowire(param: 'kernel.enabled_locales')]
        private readonly array $enabledLocales,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach ($this->enabledLocales as $locale) {
            $choices[\Locale::getDisplayLanguage($locale, $locale)] = $locale;
        }

        $builder
            ->add('showNumbers', options: [
                'label' => 'Show Numbers',
                'label_attr' => ['class' => 'checkbox-switch'],
                'attr' => ['role' => 'switch', 'switch' => null]])
            ->add('confirmAnswers', options: [
                'label' => 'Confirm Answers',
                'label_attr' => ['class' => 'checkbox-switch'],
                'attr' => ['role' => 'switch', 'switch' => null]])
            ->add('locale', ChoiceType::class, [
                'label' => 'Language',
                'choices' => $choices,
            ])
            ->add('save', SubmitType::class, [
                'label' => 'Save',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SeasonSettings::class,
        ]);
    }
}
