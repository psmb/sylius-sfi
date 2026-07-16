<?php

declare(strict_types=1);

namespace Psmb\Cloudpayments\Action\Api;

use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Reply\HttpRedirect;
use Psmb\Cloudpayments\CloudpaymentsApiClient;
use Psmb\Cloudpayments\Keys;
use Psmb\Cloudpayments\Request\Api\CreateSbpPaymentLink;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;

class CreateSbpPaymentLinkAction implements ActionInterface, ApiAwareInterface
{
    use ApiAwareTrait {
        setApi as _setApi;
    }

    /**
     * @var CloudpaymentsApiClient
     */
    private $client;

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

    public function execute($request)
    {
        /** @var $request CreateSbpPaymentLink */
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());
        $status = $model['status'] ?? null;

        if (in_array($status, ['captured', 'completed', 'rejected', 'failed'], true)) {
            return;
        }

        $currentRequest = $this->requestStack->getCurrentRequest();
        if ($currentRequest && $currentRequest->query->getBoolean('sbp_return')) {
            return;
        }

        $now = new \DateTimeImmutable();
        $linkAttempt = max(1, (int) ($model['sbpLinkAttempt'] ?? 0));
        if (!empty($model['sbpQrUrl']) && !empty($model['sbpQrExpiresAt'])) {
            $expiresAt = null;

            try {
                $expiresAt = new \DateTimeImmutable($model['sbpQrExpiresAt']);
            } catch (\Exception $exception) {
                $this->logger->warning('Discarded invalid CloudPayments SBP link expiry metadata.', [
                    'invoiceId' => $model['cloudpaymentsInvoiceId'],
                ]);
            }
            if ($expiresAt && $expiresAt > $now) {
                throw new HttpRedirect($model['sbpQrUrl']);
            }
        }

        if (!empty($model['sbpQrUrl'])) {
            foreach (['sbpQrUrl', 'sbpTransactionId', 'sbpProviderQrId', 'sbpMerchantOrderId', 'sbpQrCreatedAt', 'sbpQrExpiresAt'] as $linkField) {
                if (isset($model[$linkField])) {
                    unset($model[$linkField]);
                }
            }
            ++$linkAttempt;
        }

        if ($model['currency'] !== 'RUB') {
            $model['status'] = 'rejected';
            $this->addFlash('СБП поддерживает только оплату в рублях.');

            return;
        }

        if (!$model['cloudpaymentsInvoiceId']) {
            throw new LogicException('CloudPayments invoice id has to be set for SBP payments.');
        }

        $returnUrl = $request->getToken() ? $request->getToken()->getTargetUrl() : null;
        if ($returnUrl) {
            $returnUrl .= strpos($returnUrl, '?') === false ? '?sbp_return=1' : '&sbp_return=1';
        }
        $ttlMinutes = max(1, min(129600, (int) ($model['sbpTtlMinutes'] ?? 30)));
        $model['sbpLinkAttempt'] = $linkAttempt;

        $payload = [
            'PublicId' => $this->api->getPublishableKey(),
            'Amount' => $model['amount'],
            'Currency' => $model['currency'],
            'Description' => $model['description'] ?: 'Оплата заказа',
            'AccountId' => $model['accountId'],
            'Email' => $model['email'],
            'InvoiceId' => $model['cloudpaymentsInvoiceId'],
            'Scheme' => 'charge',
            'SaveCard' => false,
            'TtlMinutes' => $ttlMinutes,
        ];

        if ($returnUrl) {
            $payload['SuccessRedirectUrl'] = $returnUrl;
        }
        if ($currentRequest && $currentRequest->getClientIp()) {
            $payload['IpAddress'] = $currentRequest->getClientIp();
        }
        if ($model['jsonData']) {
            $jsonData = json_decode($model['jsonData'], true);
            $payload['JsonData'] = is_array($jsonData) ? $jsonData : $model['jsonData'];
        }
        if (!empty($model['testMode'])) {
            $payload['IsTest'] = true;
        }

        try {
            $response = $this->client->createSbpPaymentLink(
                $payload,
                sprintf('sbp-link-%s-%d', $model['cloudpaymentsInvoiceId'], $linkAttempt)
            );
        } catch (\RuntimeException $exception) {
            $model['status'] = null;
            $model['sbpErrorMessage'] = $exception->getMessage();
            $model['sbpLastErrorAt'] = $now->format(DATE_ATOM);
            $this->logger->error('CloudPayments SBP link request failed.', [
                'invoiceId' => $model['cloudpaymentsInvoiceId'],
                'linkAttempt' => $linkAttempt,
                'exception' => $exception,
            ]);
            $this->addFlash('Не удалось создать ссылку для оплаты по СБП. Попробуйте другой способ оплаты.');

            return;
        }

        $responseModel = $response['Model'] ?? [];
        if (empty($response['Success']) || empty($responseModel['QrUrl'])) {
            $model['status'] = 'rejected';
            $model['sbpErrorMessage'] = $response['Message'] ?? ($responseModel['Message'] ?? 'CloudPayments rejected SBP link creation.');
            $model['sbpReasonCode'] = $responseModel['ReasonCode'] ?? null;
            $this->addFlash('Не удалось создать ссылку для оплаты по СБП. Попробуйте другой способ оплаты.');

            return;
        }

        $model['status'] = 'processing';
        $model['sbpQrUrl'] = $responseModel['QrUrl'];
        $model['sbpQrCreatedAt'] = $now->format(DATE_ATOM);
        $model['sbpQrExpiresAt'] = $now->modify(sprintf('+%d minutes', $ttlMinutes))->format(DATE_ATOM);
        $model['sbpTransactionId'] = $responseModel['TransactionId'] ?? null;
        $model['sbpProviderQrId'] = $responseModel['ProviderQrId'] ?? null;
        $model['sbpMerchantOrderId'] = $responseModel['MerchantOrderId'] ?? null;
        foreach (['sbpErrorMessage', 'sbpLastErrorAt'] as $errorField) {
            if (isset($model[$errorField])) {
                unset($model[$errorField]);
            }
        }

        $this->logger->info('CloudPayments SBP payment link created.', [
            'invoiceId' => $model['cloudpaymentsInvoiceId'],
            'transactionId' => $model['sbpTransactionId'],
            'linkAttempt' => $linkAttempt,
            'expiresAt' => $model['sbpQrExpiresAt'],
        ]);

        throw new HttpRedirect($responseModel['QrUrl']);
    }

    public function supports($request)
    {
        return
            $request instanceof CreateSbpPaymentLink &&
            $request->getModel() instanceof \ArrayAccess;
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
