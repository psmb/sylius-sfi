<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use App\ISDEKservice;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;

final class CdekController extends Controller
{
    /**
     * @var \Doctrine\Common\Persistence\ObjectManager
     */
    protected $objectManager;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var FilesystemAdapter
     */
    private $cache;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(ContainerInterface $container, \Doctrine\Common\Persistence\ObjectManager $objectManager, LoggerInterface $logger)
    {
        $this->container = $container;
        $this->objectManager = $objectManager;
        $cacheDirectory = $this->getParameter('kernel.cache_dir') . '/cdek';
        $this->cache = new FilesystemAdapter('', 0, $cacheDirectory);
        $this->logger = $logger;
    }

    public function serviceAction(Request $request): Response
    {
        try {
            // Get credentials from environment
            $cdekAccount = \getenv('CDEK_ACCOUNT');
            $cdekKey = \getenv('CDEK_KEY');

            // Check if credentials are configured
            if (!$cdekAccount || !$cdekKey) {
                $this->logger->error("CDEK credentials not configured");
                return new Response(
                    json_encode(['error' => 'CDEK service not configured']),
                    500,
                    [
                        'Content-Type' => 'application/json',
                        'Access-Control-Allow-Origin' => '*'
                    ]
                );
            }

            $service = new ISDEKservice($cdekAccount, $cdekKey);

            $this->logger->info("Service: " . ($request->query->get('action') ?? 'unknown'));

            if ($request->query->get('action') === 'offices') {
                $cacheKey = 'get_offices_' . md5(json_encode($request->query->all()));
                $this->logger->info("Checking cache for key: $cacheKey");

                $responseContent = $this->cache->get($cacheKey, function (ItemInterface $item) use ($service, $request) {
                    $this->logger->info("Cache miss for key: " . $item->getKey());
                    $item->expiresAfter(4 * 604800);
                    return $service->process($request->query->all(), $request->getContent());
                });

                $this->logger->info("Returning response from cache or fresh content for key: $cacheKey");
                return new Response(
                    $responseContent['result'],
                    200,
                    [
                        'Content-Type' => 'application/json',
                        'Access-Control-Allow-Origin' => '*'
                    ]
                );
            }

            $responseContent = $service->process($request->query->all(), $request->getContent());
            return new Response(
                $responseContent['result'],
                200,
                [
                    'Content-Type' => 'application/json',
                    'Access-Control-Allow-Origin' => '*'
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error("CDEK service error: " . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->query->all()
            ]);
            return new Response(
                json_encode(['error' => 'CDEK service error: ' . $e->getMessage()]),
                500,
                [
                    'Content-Type' => 'application/json',
                    'Access-Control-Allow-Origin' => '*'
                ]
            );
        }
    }

    public function templateAction(Request $request): Response
    {
        $files = scandir($D = __DIR__ . '/tpl');
        unset($files[0]);
        unset($files[1]);

        $arTPL = array();

        foreach ($files as $filesname) {
            $file_tmp = explode('.', $filesname);
            $arTPL[strtolower($file_tmp[0])] = file_get_contents($D . '/' . $filesname);
        }

        return new Response(str_replace(array('\r', '\n', '\t', "\n", "\r", "\t"), '', json_encode($arTPL)));
    }
}
