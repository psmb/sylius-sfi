<?php
namespace Psmb\Cloudpayments\Action\Api;

use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareTrait;
use Psmb\Cloudpayments\Keys;
use Psmb\Cloudpayments\Request\Api\CreateCharge;

class CreateChargeAction implements ActionInterface, ApiAwareInterface
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

        $transaction = $client->chargeCard($amount, $currency, $ipAddress, $cardHolderName, $cryptogram);
        if (true) {
            $model['AcsUrl'] = $transaction->getUrl();
            $model['MD'] = $transaction->getTransactionId();
            $model['PaReq'] = $transaction->getToken();
        }

        // Stripe::setApiKey($this->keys->getSecretKey());
        // $charge = Charge::create($model->toUnsafeArrayWithoutLocal());
        // $model->replace($charge->__toArray(true));
        // try {
        // } catch (\Exception $e) {
        //     $model->replace($e->getJsonBody());
        // }
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
