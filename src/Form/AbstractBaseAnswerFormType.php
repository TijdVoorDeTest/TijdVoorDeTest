<?php

declare(strict_types=1);

namespace Tvdt\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * @template TAnswer of object
 *
 * @extends AbstractType<TAnswer>
 */
abstract class AbstractBaseAnswerFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ordering', HiddenType::class, ['empty_data' => '0'])
            ->add('text', TextType::class, [
                'label' => 'Answer',
                'label_attr' => ['class' => 'visually-hidden'],
                'attr' => ['placeholder' => 'Answer', 'maxlength' => 255],
            ])
            ->add('isRightAnswer', CheckboxType::class, [
                'label' => 'Correct',
                'required' => false,
            ])
        ;
    }
}
