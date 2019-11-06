<?php

declare(strict_types=1);

namespace App;

use Sylius\Component\Payment\Resolver\PaymentMethodsResolverInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

final class PaymentMethodsResolver implements PaymentMethodsResolverInterface
{
    /** @var RepositoryInterface */
    private $paymentMethodRepository;

    public function __construct(RepositoryInterface $paymentMethodRepository)
    {
        $this->paymentMethodRepository = $paymentMethodRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedMethods(PaymentInterface $payment): array
    {
        $allPaymentMethods = $this->paymentMethodRepository->findBy(['enabled' => true]);

        $firstShipmentMethod = $payment->getOrder()->getShipments()->get(0);
        $shipmentMethodCode = $firstShipmentMethod ? $firstShipmentMethod->getMethod()->getCode() : "NO_SHIPMENT";

        return array_filter($allPaymentMethods, function ($paymentMethod) use ($shipmentMethodCode) {
            $paymentMethodCode = $paymentMethod->getCode();
            // We only support online payments for NO_SHIPMENT shipment type
            if ($paymentMethodCode !== "cloudpayments" && $shipmentMethodCode === "NO_SHIPMENT") {
                return false;
            }
            // We only support online payments for PICKPOINT shipment type
            if ($paymentMethodCode !== "cloudpayments" && $shipmentMethodCode === "PICKPOINT") {
                return false;
            }
            return true;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function supports(PaymentInterface $payment): bool
    {
        return true;
    }
}
