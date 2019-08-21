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
use Psmb\Cloudpayments\Keys;
use Psmb\Cloudpayments\Request\Api\CreateCharge;
use Psmb\Cloudpayments\Request\Api\Obtain3ds;

class CreateChargeAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
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

    /**
     * {@inheritDoc}
     */
    public function execute($request)
    {
        /** @var $request CreateCharge */
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());
        if (!$model['cryptogram']) {
            throw new LogicException('The cryptogram has to be set.');
        }
        if (is_array($model['card'])) {
            throw new LogicException('The token has already been used.');
        }
        $client = new \CloudPayments\Manager(
            $this->api->getPublishableKey(),
            $this->api->getSecretKey()
        );
        $amount = $model['amount'];
        $currency = $model['currency'];
        $cryptogram = $model['cryptogram'];
        $ipAddress = "212.42.55.10";
        $cardHolderName = "Dmitri Pisarev";

        if ($model['PaRes']) {
            if (!$model['MD']) {
                throw new LogicException('Something went wrong, MD got lost :-(');
            }
            $transaction = $client->confirm3DS($model['MD'], $model['PaRes']);
            if ($transaction->getStatus() === 'completed') {
                $model['status'] = 'captured';
            } else {
                die('Transaction rejected');
            }
            return;
        }

        $transaction = $client->chargeCard($amount, $currency, $ipAddress, $cardHolderName, $cryptogram);
        if ($transaction->getUrl()) {
            $model['AcsUrl'] = $transaction->getUrl();
            $model['MD'] = $transaction->getTransactionId();
            $model['PaReq'] = $transaction->getToken();

            $obtain3ds = new Obtain3ds($request->getToken());
            $obtain3ds->setModel($model);
            $this->gateway->execute($obtain3ds);
        } else if ($transaction->getStatus() === 'completed') {
            $model['status'] = 'captured';
        } else {
            die("Something went wrong");
        }
    }
    /**
     * {@inheritDoc}
     */
    public function supports($request)
    {
        return
            $request instanceof CreateCharge &&
            $request->getModel() instanceof \ArrayAccess
        ;
    }
}
