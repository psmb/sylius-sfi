<?php

declare(strict_types=1);

namespace Psmb\Cloudpayments\Action\Api;

use CloudPayments\Manager;
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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;

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

    public function __construct(RequestStack $requestStack, ?Manager $client = null)
    {
        $this->requestStack = $requestStack;
        $this->client = $client;
        $this->apiClass = Keys::class;
    }

    /**
     * {@inheritdoc}
     */
    public function setApi($api)
    {
        $this->_setApi($api);

        if (!$this->client) {
            $this->client = new Manager(
                $this->api->getPublishableKey(),
                $this->api->getSecretKey()
            );
            $this->client->setLocale('ru');
        }
    }

    /**
     * {@inheritdoc}
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
        $currentRequest = $this->requestStack->getCurrentRequest();
        $ipAddress = $currentRequest ? ($currentRequest->getClientIp() ?: '0.0.0.0') : '0.0.0.0';
        $cardHolderName = '';

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
                    $message = $e->getCardHolderMessage() . ' Код ошибки: ' . $e->getReasonCode();
                    /** @var FlashBagInterface $flashBag */
                    $flashBag = $this->requestStack->getCurrentRequest()->getSession()->getBag('flashes');
                    $flashBag->add('error', $message);
                } else {
                    throw $e;
                }
            }

            return;
        }

        $params = [
            'InvoiceId' => $model['cloudpaymentsInvoiceId'],
            'AccountId' => $model['accountId'],
            'Email' => $model['email'],
            'Description' => $model['description'],
            'JsonData' => $model['jsonData'],
        ];

        try {
            $transaction = $this->client->chargeCard($amount, $currency, $ipAddress, $cardHolderName, $cryptogram, $params);
            if ($transaction instanceof \CloudPayments\Model\Required3DS) {
                $model['AcsUrl'] = $transaction->getUrl();
                $model['MD'] = $transaction->getTransactionId();
                $model['PaReq'] = $transaction->getToken();

                $obtain3ds = new Obtain3ds($request->getToken());
                $obtain3ds->setModel($model);
                $this->gateway->execute($obtain3ds);
            } elseif ($transaction->getStatus() === 'completed') {
                $model['status'] = 'captured';
            } else {
                $model['status'] = 'rejected';
            }
        } catch (\Exception $e) {
            $model['status'] = 'rejected';

            if ($e instanceof \CloudPayments\Exception\PaymentException) {
                $message = $e->getCardHolderMessage() . ' Код ошибки: ' . $e->getReasonCode();
                /** @var FlashBagInterface $flashBag */
                $flashBag = $this->requestStack->getCurrentRequest()->getSession()->getBag('flashes');
                $flashBag->add('error', $message);
            } else {
                throw $e;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supports($request)
    {
        return
            $request instanceof CreateCharge &&
            $request->getModel() instanceof \ArrayAccess;
    }
}
