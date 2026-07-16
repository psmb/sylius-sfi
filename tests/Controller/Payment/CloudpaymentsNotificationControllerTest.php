<?php

declare(strict_types=1);

namespace App\Tests\Controller\Payment;

use App\Controller\Payment\CloudpaymentsNotificationController;
use App\Entity\Customer\Customer;
use App\Entity\Order\Order;
use App\Entity\Payment\GatewayConfig;
use App\Entity\Payment\Payment;
use App\Entity\Payment\PaymentMethod;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SM\Factory\FactoryInterface;
use SM\StateMachine\StateMachineInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\HttpFoundation\Request;

final class CloudpaymentsNotificationControllerTest extends TestCase
{
    private const SECRET_KEY = 'test-secret';

    /** @var Payment */
    private $payment;

    /** @var CloudpaymentsNotificationController */
    private $controller;

    /** @var int */
    private $completedTransitions = 0;

    protected function setUp(): void
    {
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName('cloudpayments');
        $gatewayConfig->setGatewayName('cloudpayments_test');
        $gatewayConfig->setConfig([
            'publishable_key' => 'test-public',
            'secret_key' => self::SECRET_KEY,
            'payment_type' => 'sbp',
        ]);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setGatewayConfig($gatewayConfig);

        $customer = new Customer();
        $customer->setEmail('buyer@example.test');

        $order = new Order();
        $order->setCustomer($customer);

        $this->payment = new Payment();
        $this->setEntityId($this->payment, 42);
        $this->payment->setMethod($paymentMethod);
        $this->payment->setOrder($order);
        $this->payment->setAmount(12345);
        $this->payment->setCurrencyCode('RUB');
        $this->payment->setState(PaymentInterface::STATE_NEW);

        $paymentRepository = $this->createMock(RepositoryInterface::class);
        $paymentRepository->method('find')->willReturn($this->payment);

        $gatewayConfigRepository = $this->createMock(RepositoryInterface::class);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('transactional')->willReturnCallback(function ($callback) {
            return $callback() ?: true;
        });

        $stateMachine = $this->createMock(StateMachineInterface::class);
        $stateMachine->method('can')->with(PaymentTransitions::TRANSITION_COMPLETE)->willReturn(true);
        $stateMachine->method('apply')->willReturnCallback(function ($transition) {
            if ($transition === PaymentTransitions::TRANSITION_COMPLETE) {
                ++$this->completedTransitions;
                $this->payment->setState(PaymentInterface::STATE_COMPLETED);
            }

            return true;
        });

        $stateMachineFactory = $this->createMock(FactoryInterface::class);
        $stateMachineFactory->method('get')->willReturn($stateMachine);

        $this->controller = new CloudpaymentsNotificationController(
            $paymentRepository,
            $gatewayConfigRepository,
            $entityManager,
            $stateMachineFactory,
            new NullLogger()
        );
    }

    public function testCheckUsesPersistedPaymentDataBeforePayumDetailsAreSaved(): void
    {
        self::assertSame([], $this->payment->getDetails());

        $response = $this->controller->handleAction($this->createSignedRequest($this->validPayload()), 'check');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['code' => 0], json_decode($response->getContent(), true));
    }

    public function testFailedAttemptRemainsRetryableAndLaterPayCompletesPayment(): void
    {
        $failPayload = $this->validPayload([
            'TransactionId' => '1001',
            'Reason' => 'Declined',
            'ReasonCode' => '5051',
        ]);

        $failResponse = $this->controller->handleAction($this->createSignedRequest($failPayload), 'fail');

        self::assertSame(['code' => 0], json_decode($failResponse->getContent(), true));
        self::assertSame(PaymentInterface::STATE_NEW, $this->payment->getState());
        self::assertSame('1001', $this->payment->getDetails()['cloudpaymentsFailedAttempts'][0]['transactionId']);

        $payPayload = $this->validPayload([
            'TransactionId' => '1002',
            'Status' => 'Completed',
        ]);
        $payResponse = $this->controller->handleAction($this->createSignedRequest($payPayload), 'pay');

        self::assertSame(['code' => 0], json_decode($payResponse->getContent(), true));
        self::assertSame(PaymentInterface::STATE_COMPLETED, $this->payment->getState());
        self::assertSame('captured', $this->payment->getDetails()['status']);
        self::assertSame('1002', $this->payment->getDetails()['cloudpaymentsTransactionId']);
        self::assertSame(1, $this->completedTransitions);
    }

    public function testRepeatedPayNotificationIsIdempotent(): void
    {
        $payload = $this->validPayload([
            'TransactionId' => '2001',
            'Status' => 'Completed',
        ]);

        $this->controller->handleAction($this->createSignedRequest($payload), 'pay');
        $response = $this->controller->handleAction($this->createSignedRequest($payload), 'pay');

        self::assertSame(['code' => 0], json_decode($response->getContent(), true));
        self::assertSame(1, $this->completedTransitions);
    }

    public function testDifferentSuccessfulTransactionIsRecordedWithoutRepeatingTransition(): void
    {
        $firstPayload = $this->validPayload([
            'TransactionId' => '2001',
            'Status' => 'Completed',
        ]);
        $this->controller->handleAction($this->createSignedRequest($firstPayload), 'pay');

        $duplicatePayload = $this->validPayload([
            'TransactionId' => '2002',
            'Status' => 'Completed',
        ]);
        $response = $this->controller->handleAction($this->createSignedRequest($duplicatePayload), 'pay');

        self::assertSame(['code' => 0], json_decode($response->getContent(), true));
        self::assertSame(['2002'], $this->payment->getDetails()['cloudpaymentsDuplicateTransactionIds']);
        self::assertSame(1, $this->completedTransitions);
    }

    public function testFailNotificationForAlreadyFailedCardPaymentIsAcknowledged(): void
    {
        $this->payment->setState(PaymentInterface::STATE_FAILED);
        $payload = $this->validPayload([
            'TransactionId' => '3001',
            'Reason' => 'Declined',
            'ReasonCode' => '5051',
        ]);

        $response = $this->controller->handleAction($this->createSignedRequest($payload), 'fail');

        self::assertSame(['code' => 0], json_decode($response->getContent(), true));
        self::assertSame(PaymentInterface::STATE_FAILED, $this->payment->getState());
        self::assertSame('3001', $this->payment->getDetails()['cloudpaymentsFailedAttempts'][0]['transactionId']);
    }

    public function testRejectsIncorrectAmountAndInvalidHmac(): void
    {
        $wrongAmount = $this->validPayload(['Amount' => '123.44']);
        $response = $this->controller->handleAction($this->createSignedRequest($wrongAmount), 'check');
        self::assertSame(['code' => 12], json_decode($response->getContent(), true));

        $wrongCurrency = $this->validPayload(['Currency' => 'USD']);
        $response = $this->controller->handleAction($this->createSignedRequest($wrongCurrency), 'check');
        self::assertSame(['code' => 12], json_decode($response->getContent(), true));

        $wrongAccount = $this->validPayload(['AccountId' => 'another-buyer@example.test']);
        $response = $this->controller->handleAction($this->createSignedRequest($wrongAccount), 'check');
        self::assertSame(['code' => 11], json_decode($response->getContent(), true));

        $missingAccount = $this->validPayload(['AccountId' => '']);
        $response = $this->controller->handleAction($this->createSignedRequest($missingAccount), 'check');
        self::assertSame(['code' => 11], json_decode($response->getContent(), true));

        $request = $this->createSignedRequest($this->validPayload());
        $request->headers->set('Content-HMAC', 'invalid');
        $response = $this->controller->handleAction($request, 'check');
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['code' => 13], json_decode($response->getContent(), true));
    }

    public function testRejectsUnknownNotificationType(): void
    {
        $response = $this->controller->handleAction($this->createSignedRequest($this->validPayload()), 'unknown');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['code' => 13], json_decode($response->getContent(), true));
    }

    public function testAcceptsDecodedContentHmac(): void
    {
        $payload = $this->validPayload();
        $body = http_build_query($payload, '', '&');
        $request = new Request([], $payload, [], [], [], ['REQUEST_METHOD' => 'POST'], $body);
        $request->headers->set('X-Content-HMAC', base64_encode(hash_hmac('sha256', urldecode($body), self::SECRET_KEY, true)));

        $response = $this->controller->handleAction($request, 'check');

        self::assertSame(['code' => 0], json_decode($response->getContent(), true));
    }

    public function testAcceptsSignedGetNotification(): void
    {
        $query = http_build_query($this->validPayload(), '', '&');
        $request = Request::create('/payment/cloudpayments/notify/check?' . $query, 'GET');
        $request->headers->set('Content-HMAC', base64_encode(hash_hmac('sha256', $query, self::SECRET_KEY, true)));

        $response = $this->controller->handleAction($request, 'check');

        self::assertSame(['code' => 0], json_decode($response->getContent(), true));
    }

    private function validPayload(array $overrides = []): array
    {
        return array_replace([
            'InvoiceId' => 'cp-payment-42',
            'AccountId' => 'buyer@example.test',
            'Amount' => '123.45',
            'Currency' => 'RUB',
            'TransactionId' => '1000',
            'OperationType' => 'Payment',
            'PaymentMethod' => 'Sbp',
        ], $overrides);
    }

    private function createSignedRequest(array $payload): Request
    {
        $body = http_build_query($payload, '', '&');
        $request = new Request([], $payload, [], [], [], ['REQUEST_METHOD' => 'POST'], $body);
        $request->headers->set('Content-HMAC', base64_encode(hash_hmac('sha256', $body, self::SECRET_KEY, true)));

        return $request;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionObject($entity);
        while (!$reflection->hasProperty('id')) {
            $reflection = $reflection->getParentClass();
        }

        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($entity, $id);
    }
}
