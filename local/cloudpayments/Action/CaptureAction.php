<?php
namespace Psmb\Cloudpayments\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Capture;
use Payum\Core\Exception\RequestNotSupportedException;
use Psmb\Cloudpayments\Request\Api\ObtainToken;
use Psmb\Cloudpayments\Request\Api\Obtain3ds;
use Psmb\Cloudpayments\Request\Api\CreateCharge;

class CaptureAction implements ActionInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;

    /**
     * {@inheritDoc}
     *
     * @param Capture $request
     */
    public function execute($request)
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        if (!$model['cryptogram']) {
            $obtainToken = new ObtainToken($request->getToken());
            $obtainToken->setModel($model);
            $this->gateway->execute($obtainToken);
            return;
        }
        if ($model['AcsUrl']) {
            $obtain3ds = new Obtain3ds($request->getToken());
            $obtain3ds->setModel($model);
            $this->gateway->execute($obtain3ds);

            return;
        }
        $this->gateway->execute(new CreateCharge($model));
    }

    /**
     * {@inheritDoc}
     */
    public function supports($request)
    {
        return
            $request instanceof Capture &&
            $request->getModel() instanceof \ArrayAccess
        ;
    }
}
