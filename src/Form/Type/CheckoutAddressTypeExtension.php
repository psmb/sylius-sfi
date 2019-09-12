<?php

declare(strict_types=1);

namespace App\Form\Type;

use Sylius\Bundle\CoreBundle\Form\Type\Checkout\AddressType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

final class CheckoutAddressTypeExtension extends AbstractTypeExtension
{
    private $shipmentMethodCode = null;

    public function __construct(CartContextInterface $cartContext)
    {
        $shipment = $cartContext->getCart()->getShipments()->get(0);
        if ($shipment) {
            $this->shipmentMethodCode = $shipment->getMethod()->getCode();
        }
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($this->shipmentMethodCode === 'PICKPOINT') {
            $builder
                ->add('postomat', HiddenType::class, [
                    // 'mapped' => false,
                    'required' => true,
                    'label' => 'Выбирите постомат',
                ])
                ->remove('shippingAddress')
                ->remove('billingAddress');
            // ->add('phoneNumber', TextType::class, [
            //     'required' => true,
            //     'label' => 'sylius.form.address.phone_number',
            // ]);
        }
    }

    public static function getExtendedTypes(): iterable
    {
        return [AddressType::class];
    }
}
