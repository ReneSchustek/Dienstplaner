<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Assembly;
use App\Entity\Person;
use App\Entity\Task;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Formular zum Anlegen und Bearbeiten von Personen. */
class PersonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'label.name',
                'constraints' => [new NotBlank()],
            ])
            ->add('email', EmailType::class, [
                'label' => 'label.email',
                'required' => false,
            ])
            ->add('phone', TelType::class, [
                'label' => 'label.phone',
                'required' => false,
            ])
            ->add('assembly', EntityType::class, [
                'class' => Assembly::class,
                'choice_label' => 'name',
                'label' => 'label.assembly',
                'disabled' => $options['assembly_fixed'],
            ])
            ->add('tasks', EntityType::class, [
                'class' => Task::class,
                'choice_label' => fn(Task $t) => $t->getDepartment()->getName() . ' › ' . $t->getName(),
                'label' => 'label.tasks',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choices' => $options['available_tasks'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Person::class,
            'assembly_fixed' => false,
            'available_tasks' => [],
            'translation_domain' => 'messages',
        ]);
    }
}
