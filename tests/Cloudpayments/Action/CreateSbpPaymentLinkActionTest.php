<?php

declare(strict_types=1);

namespace App\Tests\Cloudpayments\Action;

use Payum\Core\Model\Token;
use Payum\Core\Reply\HttpRedirect;
use PHPUnit\Framework\TestCase;
use Psmb\Cloudpayments\Action\Api\CreateSbpPaymentLinkAction;
use Psmb\Cloudpayments\CloudpaymentsApiClient;
use Psmb\Cloudpayments\Keys;
use Psmb\Cloudpayments\Request\Api\CreateSbpPaymentLink;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CreateSbpPaymentLinkActionTest extends TestCase
{
    public function testCreatesLinkWithAfterUrlTtlAndStableAttemptId(): void
    {
        $client = $this->createMock(CloudpaymentsApiClient::class);
        $client->expects(self::once())
            ->method('createSbpPaymentLink')
            ->with(
                self::callback(function (array $payload): bool {
                    return
                        $payload['SuccessRedirectUrl'] === 'https://shop.example.test/payment/capture/token?sbp_return=1' &&
                        $payload['TtlMinutes'] === 30 &&
                        $payload['InvoiceId'] === 'cp-payment-42' &&
                        isset($payload['JsonData']['cloudpayments']['customerReceipt']) &&
                        $payload['IsTest'] === true;
                }),
                'sbp-link-cp-payment-42-1'
            )
            ->willReturn($this->successfulResponse());

        $model = $this->validModel();
        $action = $this->createAction($client);

        try {
            $action->execute($this->createRequest($model));
            self::fail('The action should redirect to the SBP link.');
        } catch (HttpRedirect $redirect) {
            self::assertSame('https://bank.example.test/sbp', $redirect->getUrl());
        }

        self::assertSame('processing', $model['status']);
        self::assertSame(1, $model['sbpLinkAttempt']);
        self::assertNotEmpty($model['sbpQrExpiresAt']);
    }

    public function testReusesUnexpiredLinkWithoutCallingApi(): void
    {
        $client = $this->createMock(CloudpaymentsApiClient::class);
        $client->expects(self::never())->method('createSbpPaymentLink');

        $model = $this->validModel([
            'status' => 'processing',
            'sbpQrUrl' => 'https://bank.example.test/existing',
            'sbpQrExpiresAt' => (new \DateTimeImmutable('+10 minutes'))->format(DATE_ATOM),
            'sbpLinkAttempt' => 1,
        ]);

        try {
            $this->createAction($client)->execute($this->createRequest($model));
            self::fail('The action should redirect to the existing SBP link.');
        } catch (HttpRedirect $redirect) {
            self::assertSame('https://bank.example.test/existing', $redirect->getUrl());
        }
    }

    public function testExpiredLinkStartsANewIdempotentAttempt(): void
    {
        $client = $this->createMock(CloudpaymentsApiClient::class);
        $client->expects(self::once())
            ->method('createSbpPaymentLink')
            ->with(self::isType('array'), 'sbp-link-cp-payment-42-2')
            ->willReturn($this->successfulResponse());

        $model = $this->validModel([
            'status' => 'processing',
            'sbpQrUrl' => 'https://bank.example.test/expired',
            'sbpQrExpiresAt' => (new \DateTimeImmutable('-1 minute'))->format(DATE_ATOM),
            'sbpLinkAttempt' => 1,
        ]);

        try {
            $this->createAction($client)->execute($this->createRequest($model));
        } catch (HttpRedirect $redirect) {
            self::assertSame('https://bank.example.test/sbp', $redirect->getUrl());
        }

        self::assertSame(2, $model['sbpLinkAttempt']);
    }

    public function testTransportFailureKeepsPaymentRetryableWithSameAttempt(): void
    {
        $client = $this->createMock(CloudpaymentsApiClient::class);
        $client->expects(self::once())
            ->method('createSbpPaymentLink')
            ->with(self::isType('array'), 'sbp-link-cp-payment-42-1')
            ->willThrowException(new \RuntimeException('timeout'));

        $model = $this->validModel();
        $this->createAction($client)->execute($this->createRequest($model));

        self::assertNull($model['status']);
        self::assertSame(1, $model['sbpLinkAttempt']);
        self::assertSame('timeout', $model['sbpErrorMessage']);
    }

    public function testBankReturnDoesNotRedirectBackToExistingLink(): void
    {
        $client = $this->createMock(CloudpaymentsApiClient::class);
        $client->expects(self::never())->method('createSbpPaymentLink');

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('https://shop.example.test/payment/capture/token?sbp_return=1'));
        $action = new CreateSbpPaymentLinkAction($requestStack, new NullLogger(), $client);
        $action->setApi(new Keys('test-public', 'test-secret', Keys::PAYMENT_TYPE_SBP));

        $model = $this->validModel([
            'status' => 'processing',
            'sbpQrUrl' => 'https://bank.example.test/existing',
            'sbpQrExpiresAt' => (new \DateTimeImmutable('+10 minutes'))->format(DATE_ATOM),
        ]);

        $action->execute($this->createRequest($model));

        self::assertSame('processing', $model['status']);
    }

    private function createAction(CloudpaymentsApiClient $client): CreateSbpPaymentLinkAction
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('https://shop.example.test/payment/capture/token'));

        $action = new CreateSbpPaymentLinkAction($requestStack, new NullLogger(), $client);
        $action->setApi(new Keys('test-public', 'test-secret', Keys::PAYMENT_TYPE_SBP));

        return $action;
    }

    private function createRequest(\ArrayObject $model): CreateSbpPaymentLink
    {
        $token = new Token();
        $token->setTargetUrl('https://shop.example.test/payment/capture/token');
        $token->setAfterUrl('https://shop.example.test/payment/after/token');

        $request = new CreateSbpPaymentLink($token);
        $request->setModel($model);

        return $request;
    }

    private function validModel(array $overrides = []): \ArrayObject
    {
        return new \ArrayObject(array_replace([
            'status' => null,
            'currency' => 'RUB',
            'amount' => 123.45,
            'description' => 'Order 42',
            'accountId' => 'buyer@example.test',
            'email' => 'buyer@example.test',
            'cloudpaymentsInvoiceId' => 'cp-payment-42',
            'jsonData' => '{"cloudpayments":{"customerReceipt":{"Items":[]}}}',
            'testMode' => true,
            'sbpTtlMinutes' => 30,
            'sbpQrUrl' => null,
            'sbpQrExpiresAt' => null,
            'sbpLinkAttempt' => null,
        ], $overrides));
    }

    private function successfulResponse(): array
    {
        return [
            'Success' => true,
            'Model' => [
                'QrUrl' => 'https://bank.example.test/sbp',
                'TransactionId' => 123456,
                'ProviderQrId' => 'provider-qr',
                'MerchantOrderId' => 'cp-payment-42',
            ],
        ];
    }
}
