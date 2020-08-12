<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Paweł Jędrzejewski
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Sylius\Bundle\AddressingBundle\Form\Type\AddressType as SyliusAddressType;
use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Valid;
use Sylius\Component\Order\Context\CartContextInterface;

final class CheckoutAddressType extends AbstractResourceType
{
    private $shipmentMethodCode = null;

    /**
     * @param string $dataClass FQCN
     * @param string[] $validationGroups
     */
    public function __construct(string $dataClass, array $validationGroups = [], CartContextInterface $cartContext)
    {
        parent::__construct($dataClass, $validationGroups);
        $shipment = $cartContext->getCart()->getShipments()->get(0);
        if ($shipment) {
            $this->shipmentMethodCode = $shipment->getMethod()->getCode();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($this->shipmentMethodCode === 'COURIER') {
            $builder
                ->add('shippingAddress', SyliusAddressType::class, [
                    'shippable' => true,
                    'constraints' => [new Valid()],
                ])
                ->add('billingAddress', SyliusAddressType::class, [
                    'constraints' => [new Valid()],
                ]);
        }
        if ($this->shipmentMethodCode === 'CDEK') {
            $builder->add('postomat', HiddenType::class, [
                'required' => true,
                'label' => 'Выберите постомат',
            ]);
            $builder->add('cityToId', HiddenType::class, [
                'required' => true,
                'label' => 'cityToId',
            ]);
        }
        if ($this->shipmentMethodCode === 'PICKPOINT') {
            $builder->add('postomat', HiddenType::class, [
                'required' => true,
                'label' => 'Выберите постомат',
            ]);
        }


        $builder
            ->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
                $orderData = $event->getData();

                if (isset($orderData['shippingAddress']) && (!isset($orderData['differentBillingAddress']) || false === $orderData['differentBillingAddress'])) {
                    $orderData['billingAddress'] = $orderData['shippingAddress'];

                    $event->setData($orderData);
                }
            });
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver
            ->setDefaults([
                'customer' => null,
                'cart' => null
            ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix(): string
    {
        return 'sylius_checkout_address';
    }
}
