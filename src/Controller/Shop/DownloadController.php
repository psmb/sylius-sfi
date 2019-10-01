<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class DownloadController
{

    /**
     * @var ContainerInterface
     */
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function showAction(Request $request): Response
    {
        $routeParameters = $request->attributes->get('_route_params');
        $code = $routeParameters["code"];

        $customer = $this->container->get('security.token_storage')->getToken()->getUser()->getCustomer();
        $orderRepository = $this->container->get('sylius.repository.order');
        $orders = $orderRepository->findByCustomer($customer);

        $isBought = false;
        foreach ($orders as $order) {
            if ($order->getPaymentState() === 'paid') {
                $items = $order->getItems()->toArray();
                foreach ($items as $orderItem) {
                    if ($code === $orderItem->getVariant()->getProduct()->getCode()) {
                        $isBought = true;
                        break 2;
                    }
                }
            }
        }

        if (!$isBought) {
            throw new \Error('This item doesn\'t belong to you!');
        }

        $productRepository = $this->container->get('sylius.repository.product');
        $product = $productRepository->findOneByCode($code);
        $pdfs = array_filter($product->getImages()->toArray(), function ($item) {
            if ($item->getType() === 'pdf') {
                return true;
            }
            return false;
        });
        $pdf = reset($pdfs);
        $projectDir = $this->container->getParameter('kernel.project_dir');
        return new BinaryFileResponse($projectDir . '/public/media/image/' . $pdf->getPath());
    }
}
