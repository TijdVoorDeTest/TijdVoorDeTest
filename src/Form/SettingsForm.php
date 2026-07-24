<?php

declare(strict_types=1);

namespace Tvdt\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Tvdt\Entity\SeasonSettings;

/** @extends AbstractType<SeasonSettings> */
class SettingsForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('showNumbers', options: [
                'label' => 'Show Numbers',
                'label_attr' => ['class' => 'checkbox-switch'],
                'attr' => ['role' => 'switch', 'switch' => null]])
            ->add('confirmAnswers', options: [
                'label' => 'Confirm Answers',
                'label_attr' => ['class' => 'checkbox-switch'],
                'attr' => ['role' => 'switch', 'switch' => null]])
            ->add('language', ChoiceType::class, [
                'label' => 'Language',
                'choices' => [
                    'Nederlands' => 'nl',
                    'English' => 'en',
                ],
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
