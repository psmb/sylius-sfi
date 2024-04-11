<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use App\ISDEKservice;

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

    public function __construct(ContainerInterface $container, \Doctrine\Common\Persistence\ObjectManager $objectManager)
    {
        $this->container = $container;
        $this->objectManager = $objectManager;
    }

    public function serviceAction(Request $request): Response
    {

        $service = new ISDEKservice(
            CDEK_ACCOUNT,
            CDEK_KEY
        );
        $service->process($_GET, file_get_contents('php://input'));
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
