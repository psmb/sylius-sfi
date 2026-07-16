<?php

declare(strict_types=1);

namespace App\Tests\Cloudpayments\Action;

use Doctrine\Common\Collections\ArrayCollection;
use Payum\Core\Request\Convert;
use PHPUnit\Framework\TestCase;
use Psmb\Cloudpayments\Action\ConvertPaymentAction;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class ConvertPaymentActionTest extends TestCase
{
    public function testCreatesCorrelatedPaymentDetailsWithDocumentedReceiptWrapper(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('buyer@example.test');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getCustomer')->willReturn($customer);
        $order->method('getItems')->willReturn(new ArrayCollection());
        $order->method('getAdjustmentsTotal')->willReturn(0);
        $order->method('getNumber')->willReturn('ORDER-42');

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getId')->willReturn(42);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAmount')->willReturn(12345);
        $payment->method('getCurrencyCode')->willReturn('RUB');
        $payment->method('getDetails')->willReturn([]);
        $payment->method('getMethod')->willReturn(null);

        $request = new Convert($payment, 'array');
        (new ConvertPaymentAction())->execute($request);
        $result = $request->getResult();
        $jsonData = json_decode($result['jsonData'], true);

        self::assertSame('cp-payment-42', $result['cloudpaymentsInvoiceId']);
        self::assertArrayHasKey('cloudpayments', $jsonData);
        self::assertArrayHasKey('customerReceipt', $jsonData['cloudpayments']);
        self::assertArrayNotHasKey('cloudPayments', $jsonData);
    }
}
