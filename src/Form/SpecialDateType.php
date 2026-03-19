<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\SpecialDate;
use App\Enum\SpecialDateType as SpecialDateTypeEnum;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Formular zum Anlegen und Bearbeiten besonderer Termine. */
class SpecialDateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'label.special_date.type',
                'choices' => array_combine(
                    array_map(fn(SpecialDateTypeEnum $t) => $t->label(), SpecialDateTypeEnum::cases()),
                    array_map(fn(SpecialDateTypeEnum $t) => $t->value, SpecialDateTypeEnum::cases()),
                ),
                'constraints' => [new NotBlank()],
            ])
            ->add('startDate', DateType::class, [
                'label' => 'label.start_date',
                'widget' => 'single_text',
                'constraints' => [new NotBlank()],
            ])
            ->add('endDate', DateType::class, [
                'label' => 'label.end_date',
                'widget' => 'single_text',
                'constraints' => [new NotBlank()],
            ])
            ->add('note', TextareaType::class, [
                'label' => 'label.note',
                'required' => false,
                'attr' => ['rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SpecialDate::class,
            'translation_domain' => 'messages',
        ]);
    }
}
