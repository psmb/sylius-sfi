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
use CloudPayments\Manager;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class CreateChargeAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait {
        setApi as _setApi;
    }

    use GatewayAwareTrait;

    /**
     * @var Manager
     */
    protected $client;

    /**
     * @var RequestStack
     */
    private $requestStack;

    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
        $this->apiClass = Keys::class;
    }

    /**
     * {@inheritDoc}
     */
    public function setApi($api)
    {
        $this->_setApi($api);

        $this->client = new \CloudPayments\Manager(
            $this->api->getPublishableKey(),
            $this->api->getSecretKey()
        );
        $this->client->setLocale('ru');
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

        $amount = $model['amount'];
        $currency = $model['currency'];
        $cryptogram = $model['cryptogram'];
        $ipAddress = "0.0.0.0";
        $cardHolderName = "";

        if ($model['PaRes']) {
            if (!$model['MD']) {
                throw new LogicException('Something went wrong, MD got lost :-(');
            }
            try {
                $transaction = $this->client->confirm3DS($model['MD'], $model['PaRes']);
                if ($transaction->getStatus() === 'completed') {
                    $model['status'] = 'captured';
                } else {
                    $model['status'] = 'rejected';
                }
            } catch (\Exception $e) {
                $model['status'] = 'rejected';

                if ($e instanceof \CloudPayments\Exception\PaymentException) {
                    /** @var FlashBagInterface $flashBag */
                    $flashBag = $this->requestStack->getCurrentRequest()->getSession()->getBag('flashes');
                    $flashBag->add('error', $e->getCardHolderMessage() . " Попробуйте повторить платеж заново внимательно вводя все детали");
                } else {
                    throw $e;
                }
            }

            return;
        }

        $params = [
            'JsonData' => $model['jsonData']
        ];

        try {
            $transaction = $this->client->chargeCard($amount, $currency, $ipAddress, $cardHolderName, $cryptogram, $params);
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
                $model['status'] = 'rejected';
            }
        } catch (\Exception $e) {
            $model['status'] = 'rejected';

            if ($e instanceof \CloudPayments\Exception\PaymentException) {
                /** @var FlashBagInterface $flashBag */
                $flashBag = $this->requestStack->getCurrentRequest()->getSession()->getBag('flashes');
                $flashBag->add('error', $e->getCardHolderMessage());
            } else {
                throw $e;
            }
        }
    }
    /**
     * {@inheritDoc}
     */
    public function supports($request)
    {
        return
            $request instanceof CreateCharge &&
            $request->getModel() instanceof \ArrayAccess;
    }
}
