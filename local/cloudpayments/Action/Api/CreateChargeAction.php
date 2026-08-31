<?php

declare(strict_types=1);

namespace Psmb\Cloudpayments\Action\Api;

use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Psmb\Cloudpayments\CloudpaymentsApiClient;
use Psmb\Cloudpayments\Keys;
use Psmb\Cloudpayments\Request\Api\CreateCharge;
use Psmb\Cloudpayments\Request\Api\Obtain3ds;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;

class CreateChargeAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait {
        setApi as _setApi;
    }

    use GatewayAwareTrait;

    /**
     * @var CloudpaymentsApiClient
     */
    protected $client;

    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(RequestStack $requestStack, LoggerInterface $logger, ?CloudpaymentsApiClient $client = null)
    {
        $this->requestStack = $requestStack;
        $this->logger = $logger;
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
            $this->client = new CloudpaymentsApiClient(
                $this->api->getPublishableKey(),
                $this->api->getSecretKey()
            );
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

        $invoiceId = $model['cloudpaymentsInvoiceId'];
        if (!$invoiceId) {
            throw new LogicException('CloudPayments invoice id has to be set for card payments.');
        }

        if ($model['PaRes']) {
            if (!$model['MD']) {
                throw new LogicException('Something went wrong, MD got lost :-(');
            }

            try {
                $response = $this->client->confirm3ds([
                    'TransactionId' => $model['MD'],
                    'PaRes' => $model['PaRes'],
                ], sprintf(
                    'card-post3ds-%s-%s-%s',
                    $invoiceId,
                    $model['MD'],
                    substr(hash('sha256', $model['PaRes']), 0, 16)
                ));
            } catch (\RuntimeException $exception) {
                $this->handleTransportFailure($model, $invoiceId, 'post3ds', $exception);

                return;
            }

            $this->applyTransactionResponse($model, $response, $invoiceId, false);

            return;
        }

        $payload = [
            'Amount' => $amount,
            'Currency' => $currency,
            'IpAddress' => $ipAddress,
            'Name' => $cardHolderName,
            'CardCryptogramPacket' => $cryptogram,
            'InvoiceId' => $model['cloudpaymentsInvoiceId'],
            'AccountId' => $model['accountId'],
            'Email' => $model['email'],
            'Description' => $model['description'],
            'JsonData' => $model['jsonData'],
        ];

        try {
            $response = $this->client->chargeCard($payload, 'card-charge-' . $invoiceId);
        } catch (\RuntimeException $exception) {
            $this->handleTransportFailure($model, $invoiceId, 'charge', $exception);

            return;
        }

        $this->applyTransactionResponse($model, $response, $invoiceId, true, $request);
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

    private function applyTransactionResponse(ArrayObject $model, array $response, $invoiceId, $allow3ds, CreateCharge $request = null): void
    {
        foreach (['cloudpaymentsErrorMessage', 'cloudpaymentsLastErrorAt'] as $errorField) {
            if (isset($model[$errorField])) {
                unset($model[$errorField]);
            }
        }

        $responseModel = isset($response['Model']) && is_array($response['Model']) ? $response['Model'] : [];
        $transactionId = $responseModel['TransactionId'] ?? null;
        if ($transactionId) {
            $model['cloudpaymentsTransactionId'] = $transactionId;
        }

        if (!empty($response['Success'])) {
            $status = strtolower((string) ($responseModel['Status'] ?? ''));
            $model['status'] = $status === 'completed' ? 'captured' : 'rejected';
            if ($model['status'] === 'rejected') {
                $this->addFlash('Платёж не был завершён. Попробуйте ещё раз или выберите другой способ оплаты.');
            }

            return;
        }

        if ($allow3ds && !empty($responseModel['AcsUrl']) && !empty($responseModel['PaReq']) && $transactionId) {
            $model['AcsUrl'] = $responseModel['AcsUrl'];
            $model['MD'] = $transactionId;
            $model['PaReq'] = $responseModel['PaReq'];

            $obtain3ds = new Obtain3ds($request ? $request->getToken() : null);
            $obtain3ds->setModel($model);
            $this->gateway->execute($obtain3ds);

            return;
        }

        if (isset($responseModel['ReasonCode']) && (int) $responseModel['ReasonCode'] !== 0) {
            $model['status'] = 'rejected';
            $model['cloudpaymentsReasonCode'] = $responseModel['ReasonCode'];
            $message = $responseModel['CardHolderMessage'] ?? 'Банк отклонил платёж.';
            $this->addFlash($message . ' Код ошибки: ' . $responseModel['ReasonCode']);

            return;
        }

        if (!empty($response['Message'])) {
            $model['status'] = 'rejected';
            $model['cloudpaymentsErrorMessage'] = $response['Message'];
            $this->logger->warning('CloudPayments rejected a card payment request.', [
                'invoiceId' => $invoiceId,
                'message' => $response['Message'],
            ]);
            $this->addFlash('Платёжный сервис отклонил запрос. Попробуйте другой способ оплаты.');

            return;
        }

        $this->handleTransportFailure(
            $model,
            $invoiceId,
            'invalid-response',
            new \RuntimeException('CloudPayments returned an incomplete response.')
        );
    }

    private function handleTransportFailure(ArrayObject $model, $invoiceId, $operation, \RuntimeException $exception): void
    {
        $model['status'] = null;
        $model['cloudpaymentsErrorMessage'] = $exception->getMessage();
        $model['cloudpaymentsLastErrorAt'] = (new \DateTimeImmutable())->format(DATE_ATOM);
        $this->logger->error('CloudPayments card payment request failed.', [
            'invoiceId' => $invoiceId,
            'operation' => $operation,
            'exception' => $exception,
        ]);
        $this->addFlash('Платёжный сервис временно недоступен. Списание не подтверждено; попробуйте ещё раз позже.');
    }

    private function addFlash($message): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request || !$request->hasSession()) {
            return;
        }

        /** @var FlashBagInterface $flashBag */
        $flashBag = $request->getSession()->getBag('flashes');
        $flashBag->add('error', $message);
    }
}
