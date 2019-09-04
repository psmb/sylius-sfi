<?php

declare(strict_types=1);

namespace App\Form\Type;

use Sylius\Bundle\AddressingBundle\Form\Type\AddressType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\AbstractTypeExtension;

final class AddressTypeExtension extends AbstractTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('phoneNumber', TextType::class, [
                'required' => true,
                'label' => 'sylius.form.address.phone_number',
            ]);
    }
    public static function getExtendedTypes(): iterable
    {
        return [AddressType::class];
    }
}
