<?php

declare(strict_types=1);

namespace Psmb\Cloudpayments\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Capture;
use Psmb\Cloudpayments\Keys;
use Psmb\Cloudpayments\Request\Api\CreateCharge;
use Psmb\Cloudpayments\Request\Api\CreateSbpPaymentLink;
use Psmb\Cloudpayments\Request\Api\Obtain3ds;
use Psmb\Cloudpayments\Request\Api\ObtainToken;

class CaptureAction implements ActionInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;

    /**
     * {@inheritdoc}
     *
     * @param Capture $request
     */
    public function execute($request)
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        if ($model['paymentType'] === Keys::PAYMENT_TYPE_SBP) {
            $createSbpPaymentLink = new CreateSbpPaymentLink($request->getToken());
            $createSbpPaymentLink->setModel($model);
            $this->gateway->execute($createSbpPaymentLink);

            return;
        }

        if (!$model['cryptogram']) {
            $obtainToken = new ObtainToken($request->getToken());
            $obtainToken->setModel($model);
            $this->gateway->execute($obtainToken);

            return;
        }

        if ($model['AcsUrl'] && !$model['PaRes']) {
            $obtain3ds = new Obtain3ds($request->getToken());
            $obtain3ds->setModel($model);
            $this->gateway->execute($obtain3ds);

            return;
        }

        $createCharge = new CreateCharge($request->getToken());
        $createCharge->setModel($model);
        $this->gateway->execute($createCharge);
    }

    /**
     * {@inheritdoc}
     */
    public function supports($request)
    {
        return
            $request instanceof Capture &&
            $request->getModel() instanceof \ArrayAccess
        ;
    }
}
