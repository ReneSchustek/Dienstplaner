<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Assembly;
use App\Service\AssemblyService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** Formular zum Anlegen und Bearbeiten von Versammlungen. */
class AssemblyType extends AbstractType
{
    private const WEEKDAY_CHOICES = [
        'Montag' => 1,
        'Dienstag' => 2,
        'Mittwoch' => 3,
        'Donnerstag' => 4,
        'Freitag' => 5,
        'Samstag' => 6,
        'Sonntag' => 0,
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'label.name',
                'constraints' => [new NotBlank()],
            ])
            ->add('street', TextType::class, [
                'label' => 'label.street',
                'required' => false,
            ])
            ->add('zip', TextType::class, [
                'label' => 'label.zip',
                'required' => false,
            ])
            ->add('city', TextType::class, [
                'label' => 'label.city',
                'required' => false,
            ])
            ->add('weekdays', ChoiceType::class, [
                'label'       => 'label.weekdays',
                'choices'     => self::WEEKDAY_CHOICES,
                'multiple'    => true,
                'expanded'    => true,
                'constraints' => [
                    new Callback(function (mixed $value, ExecutionContextInterface $context): void {
                        if (!AssemblyService::validateWeekdays((array) $value)) {
                            $context->addViolation('assembly.weekdays.invalid');
                        }
                    }),
                ],
            ])
            ->add('planName', TextType::class, [
                'label' => 'label.plan_name',
                'required' => false,
                'attr' => ['placeholder' => 'z. B. Dienstplan Monat Jahr'],
            ])
            ->add('lineColor', ColorType::class, [
                'label' => 'label.line_color',
            ])
            ->add('footerText', TextareaType::class, [
                'label' => 'label.footer_text',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('teamupCalendarUrl', UrlType::class, [
                'label' => 'label.teamup_url',
                'required' => false,
                'default_protocol' => 'https',
            ])
            ->add('twoFactorPolicy', ChoiceType::class, [
                'label' => 'assembly.2fa_policy',
                'choices' => [
                    'assembly.2fa_policy.user_choice' => 'user_choice',
                    'assembly.2fa_policy.disabled'    => 'disabled',
                    'assembly.2fa_policy.totp'        => 'totp',
                    'assembly.2fa_policy.email'       => 'email',
                ],
            ])
            ->add('pageSize', IntegerType::class, [
                'label'       => 'label.page_size',
                'attr'        => ['min' => 5, 'max' => 100],
                'constraints' => [new Range(['min' => 5, 'max' => 100])],
            ])
            ->add('mailInvitationSubject', TextType::class, [
                'label'    => 'label.mail_invitation_subject',
                'required' => false,
                'help'     => 'Platzhalter: {name}, {email}, {password}, {login_url}, {assembly}',
            ])
            ->add('mailInvitationBody', TextareaType::class, [
                'label'    => 'label.mail_invitation_body',
                'required' => false,
                'attr'     => ['rows' => 6],
                'help'     => 'Platzhalter: {name}, {email}, {password}, {login_url}, {assembly}',
            ])
            ->add('mailPasswordResetSubject', TextType::class, [
                'label'    => 'label.mail_password_reset_subject',
                'required' => false,
                'help'     => 'Platzhalter: {name}, {reset_url}, {assembly}',
            ])
            ->add('mailPasswordResetBody', TextareaType::class, [
                'label'    => 'label.mail_password_reset_body',
                'required' => false,
                'attr'     => ['rows' => 6],
                'help'     => 'Platzhalter: {name}, {reset_url}, {assembly}',
            ])
            ->add('mailCalendarLinkSubject', TextType::class, [
                'label'    => 'label.mail_calendar_link_subject',
                'required' => false,
                'help'     => 'Platzhalter: {name}, {calendar_url}, {assembly}',
            ])
            ->add('mailCalendarLinkBody', TextareaType::class, [
                'label'    => 'label.mail_calendar_link_body',
                'required' => false,
                'attr'     => ['rows' => 6],
                'help'     => 'Platzhalter: {name}, {calendar_url}, {assembly}',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Assembly::class,
            'translation_domain' => 'messages',
        ]);
    }
}
