<?php

declare(strict_types=1);

namespace App\Controller\Payment;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Payum\Core\Model\GatewayConfigInterface;
use Payum\Core\Security\CryptedInterface;
use Payum\Core\Security\CypherInterface;
use Psr\Log\LoggerInterface;
use SM\Factory\FactoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class CloudpaymentsNotificationController
{
    private const CODE_OK = 0;
    private const CODE_INVALID_INVOICE = 10;
    private const CODE_INVALID_ACCOUNT = 11;
    private const CODE_INVALID_AMOUNT = 12;
    private const CODE_NOT_ACCEPTED = 13;

    /**
     * @var RepositoryInterface
     */
    private $paymentRepository;

    /**
     * @var RepositoryInterface
     */
    private $gatewayConfigRepository;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var FactoryInterface
     */
    private $stateMachineFactory;

    /**
     * @var CypherInterface|null
     */
    private $gatewayConfigCypher;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        RepositoryInterface $paymentRepository,
        RepositoryInterface $gatewayConfigRepository,
        EntityManagerInterface $entityManager,
        FactoryInterface $stateMachineFactory,
        LoggerInterface $logger,
        ?CypherInterface $gatewayConfigCypher = null
    ) {
        $this->paymentRepository = $paymentRepository;
        $this->gatewayConfigRepository = $gatewayConfigRepository;
        $this->entityManager = $entityManager;
        $this->stateMachineFactory = $stateMachineFactory;
        $this->logger = $logger;
        $this->gatewayConfigCypher = $gatewayConfigCypher;
    }

    public function handleAction(Request $request, ?string $type = null): JsonResponse
    {
        $payload = $this->extractPayload($request);
        $notificationType = strtolower((string) ($type ?: ($payload['Type'] ?? $payload['type'] ?? '')));

        if (!in_array($notificationType, ['check', 'pay', 'fail'], true)) {
            $this->logger->warning('Rejected an unknown CloudPayments notification type.', [
                'type' => $notificationType,
            ]);

            return new JsonResponse(['code' => self::CODE_NOT_ACCEPTED], 400);
        }

        $payment = $this->findPayment($payload);
        if (!$this->requestHasValidHmac($request, $payment)) {
            return new JsonResponse(['code' => self::CODE_NOT_ACCEPTED], 403);
        }

        if (!$payment) {
            return new JsonResponse(['code' => self::CODE_INVALID_INVOICE]);
        }

        if ($notificationType === 'check') {
            return new JsonResponse(['code' => $this->handleCheck($payment, $payload)]);
        }

        if ($notificationType === 'pay') {
            $response = $this->entityManager->transactional(function () use ($payment, $payload) {
                $this->entityManager->lock($payment, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($payment);

                return ['code' => $this->handlePay($payment, $payload)];
            });

            return new JsonResponse($response);
        }

        $response = $this->entityManager->transactional(function () use ($payment, $payload) {
            $this->entityManager->lock($payment, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($payment);

            return ['code' => $this->handleFail($payment, $payload)];
        });

        return new JsonResponse($response);
    }

    private function handleCheck(PaymentInterface $payment, array $payload): int
    {
        $validationCode = $this->validatePayment($payment, $payload, true);
        if ($validationCode !== self::CODE_OK) {
            return $validationCode;
        }

        return self::CODE_OK;
    }

    private function handlePay(PaymentInterface $payment, array $payload): int
    {
        $validationCode = $this->validatePayment($payment, $payload, false);
        if ($validationCode !== self::CODE_OK) {
            return $validationCode;
        }

        if (strtolower((string) ($payload['Status'] ?? '')) !== 'completed') {
            $this->logger->error('CloudPayments sent a Pay notification with an unexpected status.', [
                'invoiceId' => $payload['InvoiceId'] ?? null,
                'transactionId' => $payload['TransactionId'] ?? null,
                'status' => $payload['Status'] ?? null,
            ]);

            return self::CODE_NOT_ACCEPTED;
        }

        $details = $payment->getDetails();
        $transactionId = (string) $payload['TransactionId'];
        if ($payment->getState() === PaymentInterface::STATE_COMPLETED) {
            if ((string) ($details['cloudpaymentsTransactionId'] ?? '') !== $transactionId) {
                $duplicateTransactionIds = $details['cloudpaymentsDuplicateTransactionIds'] ?? [];
                if (!in_array($transactionId, $duplicateTransactionIds, true)) {
                    $duplicateTransactionIds[] = $transactionId;
                    $details['cloudpaymentsDuplicateTransactionIds'] = array_slice($duplicateTransactionIds, -10);
                    $payment->setDetails($details);
                }

                $this->logger->critical('CloudPayments reported another successful transaction for a completed payment.', [
                    'paymentId' => $payment->getId(),
                    'transactionId' => $transactionId,
                ]);
            }

            return self::CODE_OK;
        }

        $details['status'] = 'captured';
        $details['cloudpaymentsTransactionId'] = $transactionId;
        $details['cloudpaymentsPaymentMethod'] = $payload['PaymentMethod'] ?? ($details['cloudpaymentsPaymentMethod'] ?? null);
        $details['cloudpaymentsOperationType'] = $payload['OperationType'] ?? ($details['cloudpaymentsOperationType'] ?? null);
        $details['cloudpaymentsOperationStatus'] = $payload['Status'] ?? 'Completed';
        $details['cloudpaymentsLastNotification'] = 'pay';
        $payment->setDetails($details);

        $stateMachine = $this->stateMachineFactory->get($payment, PaymentTransitions::GRAPH);
        if (!$stateMachine->can(PaymentTransitions::TRANSITION_COMPLETE)) {
            throw new \RuntimeException(sprintf('Payment %s cannot be completed from state %s.', $payment->getId(), $payment->getState()));
        }
        $stateMachine->apply(PaymentTransitions::TRANSITION_COMPLETE);

        $this->logger->info('CloudPayments payment completed.', [
            'paymentId' => $payment->getId(),
            'transactionId' => $transactionId,
            'paymentMethod' => $payload['PaymentMethod'] ?? null,
        ]);

        return self::CODE_OK;
    }

    private function handleFail(PaymentInterface $payment, array $payload): int
    {
        $validationCode = $this->validatePayment($payment, $payload, false, true);
        if ($validationCode !== self::CODE_OK) {
            return $validationCode;
        }

        if ($payment->getState() === PaymentInterface::STATE_COMPLETED) {
            return self::CODE_OK;
        }

        $details = $payment->getDetails();
        $transactionId = (string) $payload['TransactionId'];
        $failedAttempts = $details['cloudpaymentsFailedAttempts'] ?? [];
        $knownTransactionIds = array_column($failedAttempts, 'transactionId');
        if (!in_array($transactionId, $knownTransactionIds, true)) {
            $failedAttempts[] = [
                'transactionId' => $transactionId,
                'reasonCode' => $payload['ReasonCode'] ?? null,
                'reason' => $payload['Reason'] ?? null,
                'dateTime' => $payload['DateTime'] ?? null,
            ];
            $details['cloudpaymentsFailedAttempts'] = array_slice($failedAttempts, -10);
        }
        $details['cloudpaymentsLastFailedTransactionId'] = $transactionId;
        $details['cloudpaymentsLastNotification'] = 'fail';
        $payment->setDetails($details);

        $this->logger->info('CloudPayments reported a failed payment attempt.', [
            'paymentId' => $payment->getId(),
            'transactionId' => $transactionId,
            'reasonCode' => $payload['ReasonCode'] ?? null,
        ]);

        return self::CODE_OK;
    }

    private function validatePayment(PaymentInterface $payment, array $payload, bool $rejectCompleted, bool $acceptTerminalState = false): int
    {
        $invoiceId = (string) ($payload['InvoiceId'] ?? '');

        if ($invoiceId !== 'cp-payment-' . $payment->getId()) {
            return self::CODE_INVALID_INVOICE;
        }

        if ((string) ($payload['OperationType'] ?? '') !== 'Payment') {
            return self::CODE_NOT_ACCEPTED;
        }

        if (empty($payload['TransactionId'])) {
            return self::CODE_NOT_ACCEPTED;
        }

        $accountId = (string) ($payload['AccountId'] ?? '');
        $details = $payment->getDetails();
        $expectedAccountId = (string) ($details['accountId'] ?? '');
        $order = $payment->getOrder();
        if ($expectedAccountId === '' && $order && $order->getCustomer()) {
            $expectedAccountId = (string) $order->getCustomer()->getEmail();
        }
        if ($expectedAccountId !== '' && ($accountId === '' || strtolower($accountId) !== strtolower($expectedAccountId))) {
            return self::CODE_INVALID_ACCOUNT;
        }

        $currency = (string) ($payload['Currency'] ?? '');
        if ($currency === '' || strtoupper($currency) !== $payment->getCurrencyCode()) {
            return self::CODE_INVALID_AMOUNT;
        }

        if (!isset($payload['Amount']) || $this->amountToCents((string) $payload['Amount']) !== $payment->getAmount()) {
            return self::CODE_INVALID_AMOUNT;
        }

        if ($rejectCompleted && $payment->getState() === PaymentInterface::STATE_COMPLETED) {
            return self::CODE_NOT_ACCEPTED;
        }

        if (!$acceptTerminalState && in_array($payment->getState(), [PaymentInterface::STATE_FAILED, PaymentInterface::STATE_CANCELLED, PaymentInterface::STATE_REFUNDED], true)) {
            return self::CODE_NOT_ACCEPTED;
        }

        return self::CODE_OK;
    }

    private function findPayment(array $payload): ?PaymentInterface
    {
        $invoiceId = (string) ($payload['InvoiceId'] ?? '');
        if (!preg_match('/^cp-payment-(\d+)$/', $invoiceId, $matches)) {
            return null;
        }

        $payment = $this->paymentRepository->find((int) $matches[1]);
        if (!$payment instanceof PaymentInterface) {
            return null;
        }

        $paymentMethod = $payment->getMethod();
        $gatewayConfig = $paymentMethod ? $paymentMethod->getGatewayConfig() : null;
        if (!$gatewayConfig instanceof GatewayConfigInterface || $gatewayConfig->getFactoryName() !== 'cloudpayments') {
            return null;
        }

        return $payment;
    }

    private function requestHasValidHmac(Request $request, ?PaymentInterface $payment): bool
    {
        $rawPayload = $request->isMethod('GET') ? (string) $request->server->get('QUERY_STRING', '') : $request->getContent();
        if ($rawPayload === '') {
            return false;
        }

        $contentHmac = $request->headers->get('Content-HMAC');
        $decodedContentHmac = $request->headers->get('X-Content-HMAC');
        if (!$contentHmac && !$decodedContentHmac) {
            return false;
        }

        if ($payment && $payment->getMethod() && $payment->getMethod()->getGatewayConfig() instanceof GatewayConfigInterface) {
            return $this->gatewayConfigMatchesHmac($payment->getMethod()->getGatewayConfig(), $rawPayload, $contentHmac, $decodedContentHmac);
        }

        $gatewayConfigs = $this->gatewayConfigRepository->findBy(['factoryName' => 'cloudpayments']);
        foreach ($gatewayConfigs as $gatewayConfig) {
            if (!$gatewayConfig instanceof GatewayConfigInterface) {
                continue;
            }

            if ($this->gatewayConfigMatchesHmac($gatewayConfig, $rawPayload, $contentHmac, $decodedContentHmac)) {
                return true;
            }
        }

        return false;
    }

    private function gatewayConfigMatchesHmac(GatewayConfigInterface $gatewayConfig, string $rawPayload, ?string $contentHmac, ?string $decodedContentHmac): bool
    {
        $config = $this->getGatewayConfig($gatewayConfig);
        if (empty($config['secret_key'])) {
            return false;
        }

        if ($contentHmac && hash_equals($this->calculateHmac($rawPayload, $config['secret_key']), $contentHmac)) {
            return true;
        }

        return $decodedContentHmac && hash_equals($this->calculateHmac(urldecode($rawPayload), $config['secret_key']), $decodedContentHmac);
    }

    private function getGatewayConfig(GatewayConfigInterface $gatewayConfig): array
    {
        if ($this->gatewayConfigCypher && $gatewayConfig instanceof CryptedInterface) {
            $gatewayConfig->decrypt($this->gatewayConfigCypher);
        }

        return $gatewayConfig->getConfig();
    }

    private function extractPayload(Request $request): array
    {
        if ($request->isMethod('GET')) {
            return $request->query->all();
        }

        $payload = $request->request->all();
        if ($payload) {
            return $payload;
        }

        $jsonPayload = json_decode($request->getContent(), true);

        return is_array($jsonPayload) ? $jsonPayload : [];
    }

    private function amountToCents(string $amount): ?int
    {
        $amount = str_replace(',', '.', trim($amount));
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            return null;
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function calculateHmac(string $payload, string $secretKey): string
    {
        return base64_encode(hash_hmac('sha256', $payload, $secretKey, true));
    }
}
