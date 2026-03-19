<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Assembly;
use App\Entity\Department;
use App\Entity\User;
use App\Enum\UserRole;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Formular zum Anlegen und Bearbeiten von Benutzerkonten. */
class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isNew = $options['is_new'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'label.name',
                'required' => false,
            ])
            ->add('email', EmailType::class, [
                'label' => 'label.email',
                'constraints' => [new NotBlank(), new Email()],
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'label.role',
                'choices' => array_combine(
                    array_map(fn(UserRole $r) => $r->label(), UserRole::cases()),
                    array_map(fn(UserRole $r) => $r->value, UserRole::cases()),
                ),
            ])
            ->add('assembly', EntityType::class, [
                'class' => Assembly::class,
                'choice_label' => 'name',
                'label' => 'label.assembly',
                'required' => false,
                'placeholder' => '– keine –',
            ])
            ->add('departments', EntityType::class, [
                'class' => Department::class,
                'choice_label' => 'name',
                'label' => 'label.department.planer',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'choices' => $options['available_departments'],
                'by_reference' => false,
            ])
            ->add('twoFactorRequired', CheckboxType::class, [
                'label' => 'label.2fa_required',
                'required' => false,
            ]);

        if (!$isNew) {
            $builder->add('plainPassword', PasswordType::class, [
                'label' => 'label.password.new',
                'mapped' => false,
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_new' => false,
            'translation_domain' => 'messages',
            'available_departments' => [],
        ]);
    }
}
