<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Assembly;
use App\Entity\Department;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Formular zum Anlegen und Bearbeiten von Abteilungen. */
class DepartmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'label.name',
                'constraints' => [new NotBlank()],
            ])
            ->add('assembly', EntityType::class, [
                'class' => Assembly::class,
                'choice_label' => 'name',
                'label' => 'label.assembly',
                'disabled' => $options['assembly_fixed'],
            ])
            ->add('color', ColorType::class, [
                'label' => 'label.color',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Department::class,
            'assembly_fixed' => false,
            'translation_domain' => 'messages',
        ]);
    }
}
