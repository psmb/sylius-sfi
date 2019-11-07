<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use App\Entity\Product\ProductImage;

final class DownloadController extends Controller
{
    /**
     * @var \Doctrine\Common\Persistence\ObjectManager
     */
    protected $objectManager;

    /**
     * @var ContainerInterface
     */
    protected $container;

    public function __construct(ContainerInterface $container, \Doctrine\Common\Persistence\ObjectManager $objectManager)
    {
        $this->container = $container;
        $this->objectManager = $objectManager;
    }

    private function getPaidOrders($user)
    {
        $customer = $user->getCustomer();
        $orderRepository = $this->container->get('sylius.repository.order');
        $orders = $orderRepository->findByCustomer($customer);
        return array_filter($orders, function ($order) {
            return $order->getPaymentState() === 'paid';
        });
    }

    private function getDeliverables($variant)
    {
        return $variant->getImagesByType('paid')->toArray();
    }

    private function getProductVariantsWithDeliverables($user)
    {
        $orders = $this->getPaidOrders($user);
        $variants = [];
        foreach ($orders as $order) {
            $items = $order->getItems()->toArray();
            foreach ($items as $orderItem) {
                $variant = $orderItem->getVariant();
                $deliverables = $this->getDeliverables($variant);
                if (count($deliverables) > 0) {
                    $variants[$variant->getCode()] = [
                        "productVariant" => $variant,
                        "deliverables" => $deliverables
                    ];
                }
            }
        }
        return $variants;
    }

    public function listAction(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirect('/ru_RU/login', 307);
        }

        $productVariants = $this->getProductVariantsWithDeliverables($user);

        return $this->render('downloadList.html.twig', ['productVariants' => $productVariants]);
    }

    public function showOrderAction(Request $request): Response
    {
        $routeParameters = $request->attributes->get('_route_params');
        $token = $routeParameters["token"];

        $user = $this->getUser();
        if (!$user) {
            return $this->redirect('/ru_RU/login', 307);
        }

        $orderRepository = $this->container->get('sylius.repository.order');
        $order = $orderRepository->findOneByTokenValue($token);
        $items = $order->getItems()->toArray();
        if ($user != $order->getUser()) {
            throw new \Exception('Этот заказ оказ оформлен на другого пользователя!');
        }

        $deliverablesByProduct = array_filter(array_map(function ($orderItem) {
            $productVariant = $orderItem->getVariant();
            $deliverables = $this->getDeliverables($productVariant);
            if (count($deliverables) > 0) {
                return [
                    'productVariant' => $productVariant,
                    'deliverables' => $deliverables
                ];
            }
            return null;
        }, $items));
        return $this->render('downloadOrderShow.html.twig', [
            'order' => $order,
            'deliverablesByProduct' => count($deliverablesByProduct) > 0 ? $deliverablesByProduct : null
        ]);
    }

    public function downloadAction(Request $request): Response
    {
        $routeParameters = $request->attributes->get('_route_params');
        $documentId = $routeParameters["code"];

        $user = $this->container->get('security.token_storage')->getToken()->getUser();
        if (is_string($user)) {
            return $this->redirect('/ru_RU/login', 307);
        }
        $customer = $user->getCustomer();
        $orderRepository = $this->container->get('sylius.repository.order');
        $orders = $orderRepository->findByCustomer($customer);

        $document = $this->objectManager->getRepository(ProductImage::class)->findOneById($documentId);

        $variantCodes = array_map(function ($variant) {
            return $variant->getCode();
        }, $document->getProductVariants()->toArray());

        $isFound = false;
        foreach ($orders as $order) {
            if ($order->getPaymentState() === 'paid') {
                $items = $order->getItems()->toArray();
                foreach ($items as $orderItem) {
                    if (in_array($orderItem->getVariant()->getCode(), $variantCodes)) {
                        $isFound = true;
                        break 2;
                    }
                }
            }
        }

        if (!$isFound) {
            return new Response('Вы не оплатили этот файл', 402, array('Content-Type' => 'text/html'));
        }

        $projectDir = $this->container->getParameter('kernel.project_dir');
        return new BinaryFileResponse($projectDir . '/public/media/image/' . $document->getPath());
    }
}
