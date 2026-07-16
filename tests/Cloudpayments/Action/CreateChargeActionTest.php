<?php

declare(strict_types=1);

namespace App\Tests\Cloudpayments\Action;

use CloudPayments\Manager;
use CloudPayments\Model\Required3DS;
use CloudPayments\Model\Transaction;
use Payum\Core\GatewayInterface;
use PHPUnit\Framework\TestCase;
use Psmb\Cloudpayments\Action\Api\CreateChargeAction;
use Psmb\Cloudpayments\Keys;
use Psmb\Cloudpayments\Request\Api\CreateCharge;
use Psmb\Cloudpayments\Request\Api\Obtain3ds;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CreateChargeActionTest extends TestCase
{
    public function testCardChargePassesNotificationCorrelationDataAndHandlesNon3dsSuccess(): void
    {
        $transaction = new Transaction();
        $transaction->setStatus('completed');

        $manager = $this->createMock(Manager::class);
        $manager->expects(self::once())
            ->method('chargeCard')
            ->with(
                123.45,
                'RUB',
                '203.0.113.10',
                '',
                'cryptogram',
                [
                    'InvoiceId' => 'cp-payment-42',
                    'AccountId' => 'buyer@example.test',
                    'Email' => 'buyer@example.test',
                    'Description' => 'Order 42',
                    'JsonData' => '{}',
                ]
            )
            ->willReturn($transaction);

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('https://shop.example.test/payment/capture', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
        ]));

        $action = new CreateChargeAction($requestStack, $manager);
        $action->setApi(new Keys('test-public', 'test-secret'));

        $model = new \ArrayObject([
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
        ]);
        $request = new CreateCharge($model);

        $action->execute($request);

        self::assertSame('captured', $model['status']);
    }

    public function testCardChargePreserves3dsRedirectFlow(): void
    {
        $required3ds = (new Required3DS())
            ->setUrl('https://acs.example.test/challenge')
            ->setTransactionId(123456)
            ->setToken('pa-request');

        $manager = $this->createMock(Manager::class);
        $manager->method('chargeCard')->willReturn($required3ds);

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

        $requestStack = new RequestStack();
        $requestStack->push(Request::create('https://shop.example.test/payment/capture', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
        ]));

        $action = new CreateChargeAction($requestStack, $manager);
        $action->setApi(new Keys('test-public', 'test-secret'));
        $action->setGateway($gateway);

        $model = new \ArrayObject([
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
        ]);

        $action->execute(new CreateCharge($model));

        self::assertSame('https://acs.example.test/challenge', $model['AcsUrl']);
        self::assertSame(123456, $model['MD']);
        self::assertSame('pa-request', $model['PaReq']);
    }
}
