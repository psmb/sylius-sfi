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

    private function getDeliverables($product)
    {
        return $product->getImagesByType('pdf')->toArray();
    }

    private function getProductsWithDeliverables($user)
    {
        $orders = $this->getPaidOrders($user);
        $products = [];
        foreach ($orders as $order) {
            $items = $order->getItems()->toArray();
            foreach ($items as $orderItem) {
                $product = $orderItem->getVariant()->getProduct();
                $deliverables = $this->getDeliverables($product);
                if (count($deliverables) > 0) {
                    $products[$product->getCode()] = [
                        "product" => $product,
                        "deliverables" => $deliverables
                    ];
                }
            }
        }
        return $products;
    }

    public function listAction(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirect('/ru_RU/login', 307);
        }

        $products = $this->getProductsWithDeliverables($user);

        return $this->render('downloadList.html.twig', ['products' => $products]);
    }

    public function showAction(Request $request): Response
    {
        $routeParameters = $request->attributes->get('_route_params');
        $productCode = $routeParameters["code"];

        $user = $this->getUser();
        if (!$user) {
            return $this->redirect('/ru_RU/login', 307);
        }

        $productRepository = $this->container->get('sylius.repository.product');

        $product = $productRepository->findOneByCode($productCode);

        $deliverables = $this->getDeliverables($product);

        return $this->render('downloadShow.html.twig', [
            'product' => $product,
            'deliverables' => $deliverables
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

        $product = $document->getOwner();
        $productCode = $product->getCode();

        $isFound = false;
        foreach ($orders as $order) {
            if ($order->getPaymentState() === 'paid') {
                $items = $order->getItems()->toArray();
                foreach ($items as $orderItem) {
                    if ($productCode === $orderItem->getVariant()->getProduct()->getCode()) {
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
