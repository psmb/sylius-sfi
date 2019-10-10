<?php

declare(strict_types=1);

namespace App\Shipping;

use Sylius\Component\Shipping\Calculator\CalculatorInterface;
use Sylius\Component\Shipping\Model\ShipmentInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;

final class PickpointShippingCalculator implements CalculatorInterface
{
    private $cache = null;

    private $client = null;

    public function __construct()
    {
        $this->cache = new FilesystemAdapter(
            'pickpoint_auth'
        );
        $this->client = HttpClient::create();
    }

    private function getToken()
    {
        return $this->cache->get('pickpoint_token', function (ItemInterface $item) {
            $item->expiresAfter(3600 * 20);
            $response = $this->client->request('POST', 'https://e-solution.pickpoint.ru/api/login', [
                // these are demo passwords, no worries
                'json' => ['Login' => '2LzNqu', 'Password' => 'G5kvdGZjUrV1']
            ]);
            $statusCode = $response->getStatusCode();
            if ($statusCode > 400) {
                throw new \Exception("Invalid API response");
            }
            $content = $response->toArray();
            return $content['SessionId'];
        });
    }
    /**
     * {@inheritdoc}
     */
    public function calculate(ShipmentInterface $subject, array $configuration): int
    {
        $postomat = $subject->getOrder()->getPostomat();
        if (!$postomat) {
            return 20000;
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

        $response = $this->client->request('POST', 'https://e-solution.pickpoint.ru/api/calctariff', [
            'json' => [
                'SessionId' => $this->getToken(),
                'IKN' => '9990003041',
                'FromCity' => 'Москва',
                'FromRegion' => 'Москва',
                'PTNumber' => $postomat,
                'Length' => max($heights) ?? 0,
                'Depth' => array_sum($depths),
                'Width' => max($widths) ?? 0
            ]
        ]);
        $statusCode = $response->getStatusCode();
        if ($statusCode > 400) {
            throw new \Exception("Invalid API response");
        }
        $content = $response->toArray();

        if ($content["ErrorCode"] !== 0) {
            throw new \Exception($content["Error"]);
        }

        $pickpointPrice = $content["Services"][0]["Tariff"] + $content["Services"][0]["NDS"];
        $priceAdjustedForTax = $pickpointPrice * (1 + 7 / 100);

        return \intval($priceAdjustedForTax) * 100;
    }

    /**
     * {@inheritdoc}
     */
    public function getType(): string
    {
        return 'pickpoint';
    }
}
