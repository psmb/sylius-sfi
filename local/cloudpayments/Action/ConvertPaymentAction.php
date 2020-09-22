<?php

namespace Psmb\Cloudpayments\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareTrait;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Payum\Core\Request\Convert;
use Payum\Core\Bridge\Spl\ArrayObject;

class ConvertPaymentAction implements ActionInterface
{
    use GatewayAwareTrait;

    /**
     * {@inheritDoc}
     *
     * @param Convert $request
     */
    public function execute($request)
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getSource();

        /** @var OrderInterface $order */
        $order = $payment->getOrder();

        $customer = $order->getCustomer();
        $email = $customer->getEmail() ?? $customer->getUser()->getEmail();

        $orderItems = $order->getItems()->toArray();

        $items = array_map(function ($item) {
            return [
                "label" => $item->getProductName(),
                "price" => ($item->getTotal() / $item->getQuantity()) / 100,
                "quantity" => $item->getQuantity(),
                "amount" => $item->getTotal() / 100,
                "method" => 4,
                "object" => 1,
                "measurementUnit" => "шт"
            ];
        }, $orderItems);
        if ($order->getAdjustmentsTotal() > 0) {
            $items[] = [
                "label" => "Доставка",
                "price" => $order->getAdjustmentsTotal() / 100,
                "quantity" => 1,
                "amount" => $order->getAdjustmentsTotal() / 100,
                "method" => 4,
                "object" => 4,
                "measurementUnit" => "шт"
            ];
        }

        $json = [
            "cloudPayments" => [
                "customerReceipt" => [
                    "Items" => $items,
                    "calculationPlace" => "books.sfi.ru",
                    "taxationSystem" => 1,
                    "email" => $email,
                    "amounts" => [
                        "electronic" => $payment->getAmount() / 100,
                        "advancePayment" => 0,
                        "credit" => 0,
                        "provision" => 0
                    ]
                ]
            ]
        ];

        $details = ArrayObject::ensureArrayObject($payment->getDetails());
        $details["amount"] = $payment->getAmount() / 100;
        $details["currency"] = $payment->getCurrencyCode();
        $details["jsonData"] = json_encode($json);
        $request->setResult((array) $details);
    }

    /**
     * {@inheritDoc}
     */
    public function supports($request)
    {
        return
            $request instanceof Convert &&
            $request->getSource() instanceof PaymentInterface &&
            $request->getTo() == 'array';
    }
}
