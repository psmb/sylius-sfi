<?php
namespace Psmb\Cloudpayments\Action\Api;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\RenderTemplate;
use Psmb\Cloudpayments\Request\Api\Obtain3ds;
use Psmb\Cloudpayments\Request\Api\CreateCharge;

class Obtain3dsAction implements ActionInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;

    protected $templateName = '@PsmbCloudpayments/Action/obtain_3ds.html.twig';

    public function execute($request)
    {
        /** @var $request Obtain3ds */
        RequestNotSupportedException::assertSupports($this, $request);
        $model = ArrayObject::ensureArrayObject($request->getModel());
        if ($model['PaRes']) {
            throw new LogicException('The PaRes has already been set.');
        }
        if (!$model['AcsUrl']) {
            throw new LogicException('AcsUrl has not been set.');
        }
        $getHttpRequest = new GetHttpRequest();
        $this->gateway->execute($getHttpRequest);
        if ($getHttpRequest->method == 'POST' && isset($getHttpRequest->request['PaRes'])) {
            $model['PaRes'] = $getHttpRequest->request['PaRes'];

            $createCharge = new CreateCharge($request->getToken());
            $createCharge->setModel($model);
            $this->gateway->execute($createCharge);
            return;
        }
        $this->gateway->execute($renderTemplate = new RenderTemplate(
            $this->templateName, [
                'AcsUrl' => $model['AcsUrl'],
                'TermUrl' => $request->getToken() ? str_replace('http:', 'http:', $request->getToken()->getTargetUrl()) : null,
                'MD' => $model['MD'],
                'PaReq' => $model['PaReq']
            ])
        );
        throw new HttpResponse($renderTemplate->getResult());
    }


    public function supports($request)
    {
        return
            $request instanceof Obtain3ds &&
            $request->getModel() instanceof \ArrayAccess
        ;
    }
}
