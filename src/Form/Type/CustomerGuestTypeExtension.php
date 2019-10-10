<?php

declare(strict_types=1);

namespace App\Form\Type;

use Sylius\Bundle\CoreBundle\Form\Type\Customer\CustomerGuestType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\AbstractTypeExtension;

final class CustomerGuestTypeExtension extends AbstractTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('phoneNumber', TextType::class, [
                'required' => true,
                'label' => 'sylius.form.customer.phone_number',
            ])
            ->add('firstName', TextType::class, [
                'required' => false,
                'label' => 'sylius.form.customer.first_name',
            ])
            ->add('lastName', TextType::class, [
                'required' => false,
                'label' => 'sylius.form.customer.last_name',
            ]);
    }
    public static function getExtendedTypes(): iterable
    {
        return [CustomerGuestType::class];
    }
}
