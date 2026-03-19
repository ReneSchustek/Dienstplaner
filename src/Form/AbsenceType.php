<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Absence;
use App\Entity\Person;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Formular zum Anlegen und Bearbeiten von Abwesenheiten. */
class AbsenceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('person', EntityType::class, [
                'class' => Person::class,
                'choice_label' => 'name',
                'label' => 'label.person',
                'choices' => $options['persons'],
                'constraints' => [new NotBlank()],
            ])
            ->add('startDate', DateType::class, [
                'label' => 'label.start_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new NotBlank()],
            ])
            ->add('endDate', DateType::class, [
                'label' => 'label.end_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new NotBlank()],
            ])
            ->add('note', TextareaType::class, [
                'label' => 'label.note',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Absence::class,
            'persons' => [],
            'translation_domain' => 'messages',
        ]);
    }
}
