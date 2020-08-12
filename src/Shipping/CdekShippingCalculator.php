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
        $cityToId = $subject->getOrder()->getCityToId();
        if (!$postomat || !$cityToId) {
            return 26500;
        }

        $items = $subject->getShippables()->toArray();
        $widths = array_map(function ($item) {
            return $item->getWidth();
        }, $items);
        $heights = array_map(function ($item) {
            return $item->getHeight();
        }, $items);
        $depths = array_map(function ($item) {
            return $item->getDepth();
        }, $items);
        if ($subject->getShippingWeight() > 15000) {
            throw new \Exception("Суммарный вес доставки не должен привышать 15кг");
        }
        $shipmentDescription = [
            'shipment' => [
                'type' => strpos($postomat, 'cdek_courier_') === 0 ? 'courier' : 'pickup',
                'cityToId' => \intval($cityToId),
                'cityFromId' => 44,
                'timestamp' => time(),
                'goods' => [
                    [
                        'width' => max($widths) ?? 0,
                        'height' => max($heights) ?? 0,
                        'length' => array_sum($depths),
                        'weight' => $subject->getShippingWeight() / 1000 ?? 0
                    ]
                ]
            ],
        ];
        $cdekResponse = ISDEKservice::calc($shipmentDescription);
        if (isset($cdekResponse['result']['price'])) {
            $adjustedPrice = $cdekResponse['result']['price'] * (1 + 4 / 100);
            return \intval($adjustedPrice) * 100;
        }
        throw new \Exception("Something went wrong calculating shipment: " . json_encode($cdekResponse));
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return 'cdek';
    }
}
