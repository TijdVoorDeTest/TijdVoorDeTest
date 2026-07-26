<?php

declare(strict_types=1);

namespace Tvdt\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Contracts\Translation\TranslatorInterface;

/** @extends AbstractType<null> */
class SelectSeasonType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('season_code', TextType::class, [
                'required' => true,
                'constraints' => new Regex(pattern: "/^[A-Za-z\d]{5}$/"),
                'label' => $this->translator->trans('Season Code'),
                'translation_domain' => false,
                'attr' => ['autofocus' => true, 'autocomplete' => 'off'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Configure your form options here
        ]);
    }
}
