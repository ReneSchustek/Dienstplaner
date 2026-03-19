<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Day;
use App\Entity\ExternalTask;
use App\Entity\Person;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Formular zum Anlegen und Bearbeiten externer Aufgaben. */
class ExternalTaskType extends AbstractType
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
            ->add('day', EntityType::class, [
                'class' => Day::class,
                'choice_label' => fn(Day $day) => $day->getDate()->format('d.m.Y'),
                'label' => 'label.day',
                'choices' => $options['days'],
                'constraints' => [new NotBlank()],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'label.description',
                'required' => false,
                'attr' => ['rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ExternalTask::class,
            'persons' => [],
            'days' => [],
            'translation_domain' => 'messages',
        ]);
    }
}
