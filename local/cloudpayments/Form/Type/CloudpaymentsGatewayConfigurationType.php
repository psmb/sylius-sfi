<?php

declare(strict_types=1);

namespace Psmb\Cloudpayments\Form\Type;

use Psmb\Cloudpayments\Keys;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

final class CloudpaymentsGatewayConfigurationType extends AbstractType
{
    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('publishable_key', TextType::class, [
                'label' => 'Public ID',
                'constraints' => [
                    new NotBlank([
                        'message' => 'sylius.gateway_config.paypal.username.not_blank',
                        'groups' => 'sylius',
                    ]),
                ],
            ])
            ->add('secret_key', TextType::class, [
                'label' => 'Secret Key',
                'constraints' => [
                    new NotBlank([
                        'message' => 'sylius.gateway_config.paypal.password.not_blank',
                        'groups' => 'sylius',
                    ]),
                ],
            ])
            ->add('payment_type', ChoiceType::class, [
                'label' => 'Payment type',
                'choices' => [
                    'Bank card' => Keys::PAYMENT_TYPE_CARD,
                    'SBP' => Keys::PAYMENT_TYPE_SBP,
                ],
                'required' => true,
            ])
            ->add('sbp_ttl_minutes', IntegerType::class, [
                'label' => 'SBP link lifetime (minutes)',
                'required' => true,
                'empty_data' => '30',
                'constraints' => [
                    new Range([
                        'min' => 1,
                        'max' => 129600,
                    ]),
                ],
            ])
            ->add('test_mode', CheckboxType::class, [
                'label' => 'Test mode',
                'required' => false,
            ])
        ;
    }
}
