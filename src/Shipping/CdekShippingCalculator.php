<?php

declare(strict_types=1);

namespace App\Shipping;

use App\ISDEKservice;
use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Sylius\Component\Shipping\Model\ShipmentInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

final class CdekShippingCalculator implements CalculatorInterface
{

    public function __construct(ContainerBagInterface $params)
    {
        $this->params = $params;
    }

    /**
     * {@inheritdoc}
     */
    public function calculate(ShipmentInterface $subject, array $configuration): int
    {
        $postomat = $subject->getOrder()->getPostomat();

        if (!$postomat) {
            return 26500;
        }

        $delivery = json_decode($postomat, true);
        if (isset($delivery['delivery']['delivery_sum'])) {
            return $delivery['delivery']['delivery_sum'];
        }
        throw new \Exception("Something went wrong calculating shipment: " . json_encode($delivery));
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return 'cdek';
    }
}
