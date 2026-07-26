<?php

declare(strict_types=1);

namespace Tvdt\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** @extends AbstractType<null> */
class EnterNameType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class,
                [
                    'required' => true,
                    'label' => $this->translator->trans('Enter your name'),
                    'translation_domain' => false,
                    'attr' => ['autofocus' => true, 'autocomplete' => 'off'],
                ],
            )
        ;
    }
}
