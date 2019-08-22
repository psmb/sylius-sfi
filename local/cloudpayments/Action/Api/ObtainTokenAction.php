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
use Psmb\Cloudpayments\Request\Api\ObtainToken;
use Psmb\Cloudpayments\Request\Api\CreateCharge;

class ObtainTokenAction implements ActionInterface, GatewayAwareInterface
{
    use ApiAwareTrait {
        setApi as _setApi;
    }

    use GatewayAwareTrait;

    public function __construct()
    {
        $this->apiClass = Keys::class;
    }

    /**
     * {@inheritDoc}
     */
    public function setApi($api)
    {
        $this->_setApi($api);
    }

    protected $templateName = '@PsmbCloudpayments/Action/obtain_checkout_token.html.twig';

    public function execute($request)
    {
        /** @var $request ObtainToken */
        RequestNotSupportedException::assertSupports($this, $request);
        $model = ArrayObject::ensureArrayObject($request->getModel());
        if ($model['cryptogram']) {
            throw new LogicException('The token has already been set.');
        }
        $getHttpRequest = new GetHttpRequest();
        $this->gateway->execute($getHttpRequest);
        if ($getHttpRequest->method == 'POST' && isset($getHttpRequest->request['cryptogram'])) {
            $model['cryptogram'] = $getHttpRequest->request['cryptogram'];
            $createCharge = new CreateCharge($request->getToken());
            $createCharge->setModel($model);
            $this->gateway->execute($createCharge);
            return;
        }
        $this->gateway->execute($renderTemplate = new RenderTemplate(
            $this->templateName, [
                'model' => $model,
                'actionUrl' => $request->getToken() ? str_replace('http:', 'https:', $request->getToken()->getTargetUrl()) : null,
                'publishable_key' => $this->api->getPublishableKey()
            ])
        );
        throw new HttpResponse($renderTemplate->getResult());
    }


    public function supports($request)
    {
        return
            $request instanceof ObtainToken &&
            $request->getModel() instanceof \ArrayAccess
        ;
    }
}
