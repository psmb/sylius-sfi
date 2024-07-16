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

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

define('CDEK_ACCOUNT', \getenv('CDEK_ACCOUNT'));
define('CDEK_KEY', \getenv('CDEK_KEY'));

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

    public function __construct(ContainerInterface $container, \Doctrine\Common\Persistence\ObjectManager $objectManager)
    {
        $this->container = $container;
        $this->objectManager = $objectManager;
        $this->cache = new FilesystemAdapter();
    }

    public function serviceAction(Request $request): Response
    {
        $service = new ISDEKservice(
            CDEK_ACCOUNT,
            CDEK_KEY
        );

        if (isset($_GET['action']) && $_GET['action'] === 'offices') {
            $cacheKey = 'get_offices_' . md5(json_encode($_GET));
            $responseContent = $this->cache->get($cacheKey, function (ItemInterface $item) use ($service) {
                $item->expiresAfter(604800); // Cache for 1 week (7 days * 24 hours * 60 minutes * 60 seconds)
                ob_start();
                $service->process($_GET, file_get_contents('php://input'));
                return ob_get_clean();
            });

            return new Response($responseContent, 200, ['Content-Type' => 'application/json']);
        }

        $service->process($_GET, file_get_contents('php://input'));
        return new Response('', 200, ['Content-Type' => 'application/json']);
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
