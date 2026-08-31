<?php

declare(strict_types=1);

namespace App\Tests\Cloudpayments\Action;

use Payum\Core\GatewayInterface;
use PHPUnit\Framework\TestCase;
use Psmb\Cloudpayments\Action\Api\CreateChargeAction;
use Psmb\Cloudpayments\CloudpaymentsApiClient;
use Psmb\Cloudpayments\Keys;
use Psmb\Cloudpayments\Request\Api\CreateCharge;
use Psmb\Cloudpayments\Request\Api\Obtain3ds;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CreateChargeActionTest extends TestCase
{
    public function testCardChargeUsesIdempotencyAndHandlesNon3dsSuccess(): void
    {
        $client = $this->createMock(CloudpaymentsApiClient::class);
        $client->expects(self::once())
            ->method('chargeCard')
            ->with(
                [
                    'Amount' => 123.45,
                    'Currency' => 'RUB',
                    'IpAddress' => '203.0.113.10',
                    'Name' => '',
                    'CardCryptogramPacket' => 'cryptogram',
                    'InvoiceId' => 'cp-payment-42',
                    'AccountId' => 'buyer@example.test',
                    'Email' => 'buyer@example.test',
                    'Description' => 'Order 42',
                    'JsonData' => '{}',
                ],
                'card-charge-cp-payment-42'
            )
            ->willReturn([
                'Success' => true,
                'Model' => [
                    'TransactionId' => 123456,
                    'Status' => 'Completed',
                ],
            ]);

        $model = $this->validModel([
            'cloudpaymentsErrorMessage' => 'Previous timeout',
            'cloudpaymentsLastErrorAt' => '2026-08-25T10:00:00+00:00',
        ]);
        $this->createAction($client)->execute(new CreateCharge($model));

        self::assertSame('captured', $model['status']);
        self::assertSame(123456, $model['cloudpaymentsTransactionId']);
        self::assertArrayNotHasKey('cloudpaymentsErrorMessage', $model);
        self::assertArrayNotHasKey('cloudpaymentsLastErrorAt', $model);
    }

    public function testCardChargePreserves3dsRedirectFlow(): void
    {
        $client = $this->createMock(CloudpaymentsApiClient::class);
        $client->method('chargeCard')->willReturn([
            'Success' => false,
            'Message' => null,
            'Model' => [
                'AcsUrl' => 'https://acs.example.test/challenge',
                'TransactionId' => 123456,
                'PaReq' => 'pa-request',
            ],
        ]);

        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->expects(self::once())
            ->method('execute')
            ->with(self::callback(function ($request): bool {
                if (!$request instanceof Obtain3ds) {
                    return false;
                }

                $model = $request->getModel();

                return
                    $model['AcsUrl'] === 'https://acs.example.test/challenge' &&
                    $model['MD'] === 123456 &&
                    $model['PaReq'] === 'pa-request';
            }));

        $model = $this->validModel();
        $action = $this->createAction($client);
        $action->setGateway($gateway);
        $action->execute(new CreateCharge($model));

        self::assertSame('https://acs.example.test/challenge', $model['AcsUrl']);
        self::assertSame(123456, $model['MD']);
        self::assertSame('pa-request', $model['PaReq']);
    }

    public function testTransportFailureKeepsPaymentRetryable(): void
    {
        $client = $this->createMock(CloudpaymentsApiClient::class);
        $client->expects(self::once())
            ->method('chargeCard')
            ->with(self::isType('array'), 'card-charge-cp-payment-42')
            ->willThrowException(new \RuntimeException('DNS lookup failed'));

        $model = $this->validModel();
        $this->createAction($client)->execute(new CreateCharge($model));

        self::assertNull($model['status']);
        self::assertSame('DNS lookup failed', $model['cloudpaymentsErrorMessage']);
        self::assertNotEmpty($model['cloudpaymentsLastErrorAt']);
        self::assertArrayNotHasKey('AcsUrl', $model);
    }

    public function testIncompleteResponseKeepsPaymentRetryableWithoutStarting3ds(): void
    {
        $client = $this->createMock(CloudpaymentsApiClient::class);
        $client->method('chargeCard')->willReturn([
            'Success' => false,
            'Message' => null,
            'Model' => [],
        ]);
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->expects(self::never())->method('execute');

        $model = $this->validModel();
        $action = $this->createAction($client);
        $action->setGateway($gateway);
        $action->execute(new CreateCharge($model));

        self::assertNull($model['status']);
        self::assertSame('CloudPayments returned an incomplete response.', $model['cloudpaymentsErrorMessage']);
    }

    public function testBankDeclineFailsPaymentWithReasonCode(): void
    {
        $client = $this->createMock(CloudpaymentsApiClient::class);
        $client->method('chargeCard')->willReturn([
            'Success' => false,
            'Message' => null,
            'Model' => [
                'TransactionId' => 123456,
                'ReasonCode' => 5051,
                'CardHolderMessage' => 'Недостаточно средств',
            ],
        ]);

        $model = $this->validModel();
        $this->createAction($client)->execute(new CreateCharge($model));

        self::assertSame('rejected', $model['status']);
        self::assertSame(5051, $model['cloudpaymentsReasonCode']);
    }

    public function test3dsConfirmationUsesAStableRequestId(): void
    {
        $client = $this->createMock(CloudpaymentsApiClient::class);
        $client->expects(self::once())
            ->method('confirm3ds')
            ->with(
                ['TransactionId' => 123456, 'PaRes' => 'pa-response'],
                'card-post3ds-cp-payment-42-123456-' . substr(hash('sha256', 'pa-response'), 0, 16)
            )
            ->willReturn([
                'Success' => true,
                'Model' => [
                    'TransactionId' => 123456,
                    'Status' => 'Completed',
                ],
            ]);

        $model = $this->validModel([
            'PaRes' => 'pa-response',
            'MD' => 123456,
        ]);
        $this->createAction($client)->execute(new CreateCharge($model));

        self::assertSame('captured', $model['status']);
    }

    private function createAction(CloudpaymentsApiClient $client): CreateChargeAction
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('https://shop.example.test/payment/capture', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
        ]));

        $action = new CreateChargeAction($requestStack, new NullLogger(), $client);
        $action->setApi(new Keys('test-public', 'test-secret'));

        return $action;
    }

    private function validModel(array $overrides = []): \ArrayObject
    {
        return new \ArrayObject(array_replace([
            'status' => null,
            'amount' => 123.45,
            'currency' => 'RUB',
            'cryptogram' => 'cryptogram',
            'card' => null,
            'PaRes' => null,
            'MD' => null,
            'cloudpaymentsInvoiceId' => 'cp-payment-42',
            'accountId' => 'buyer@example.test',
            'email' => 'buyer@example.test',
            'description' => 'Order 42',
            'jsonData' => '{}',
        ], $overrides));
    }
}
