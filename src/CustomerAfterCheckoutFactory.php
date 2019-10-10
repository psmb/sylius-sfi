<?php

declare(strict_types=1);

namespace App;

use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Core\Factory\CustomerAfterCheckoutFactoryInterface;

final class CustomerAfterCheckoutFactory implements CustomerAfterCheckoutFactoryInterface
{
    /** @var FactoryInterface */
    private $baseCustomerFactory;

    public function __construct(FactoryInterface $baseCustomerFactory)
    {
        $this->baseCustomerFactory = $baseCustomerFactory;
    }

    public function createNew(): CustomerInterface
    {
        /** @var CustomerInterface $customer */
        $customer = $this->baseCustomerFactory->createNew();

        return $customer;
    }

    public function createAfterCheckout(OrderInterface $order): CustomerInterface
    {
        $guest = $order->getCustomer();
        $address = $order->getBillingAddress();

        $customer = $this->createNew();
        $customer->setEmail($guest->getEmail());

        if ($address) {
            $customer->setFirstName($address->getFirstName());
            $customer->setLastName($address->getLastName());
            $customer->setPhoneNumber($address->getPhoneNumber());
        }

        return $customer;
    }
}
