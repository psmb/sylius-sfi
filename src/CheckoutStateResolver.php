<?php

declare(strict_types=1);

namespace App;

use SM\Factory\FactoryInterface;
use Sylius\Component\Core\Checker\OrderPaymentMethodSelectionRequirementCheckerInterface;
use Sylius\Component\Core\Checker\OrderShippingMethodSelectionRequirementCheckerInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Order\StateResolver\StateResolverInterface;

final class CheckoutStateResolver implements StateResolverInterface
{
    /** @var FactoryInterface */
    private $stateMachineFactory;

    /** @var OrderPaymentMethodSelectionRequirementCheckerInterface */
    private $orderPaymentMethodSelectionRequirementChecker;

    /** @var OrderShippingMethodSelectionRequirementCheckerInterface */
    private $orderShippingMethodSelectionRequirementChecker;

    public function __construct(
        FactoryInterface $stateMachineFactory,
        OrderPaymentMethodSelectionRequirementCheckerInterface $orderPaymentMethodSelectionRequirementChecker,
        OrderShippingMethodSelectionRequirementCheckerInterface $orderShippingMethodSelectionRequirementChecker
    ) {
        $this->stateMachineFactory = $stateMachineFactory;
        $this->orderPaymentMethodSelectionRequirementChecker = $orderPaymentMethodSelectionRequirementChecker;
        $this->orderShippingMethodSelectionRequirementChecker = $orderShippingMethodSelectionRequirementChecker;
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(OrderInterface $order): void
    {
        $stateMachine = $this->stateMachineFactory->get($order, OrderCheckoutTransitions::GRAPH);

        $user = $order->getUser();
        if ($user && $stateMachine->can('skip_registration')) {
            $stateMachine->apply('skip_registration');
        }

        if (
            !$this->orderShippingMethodSelectionRequirementChecker->isShippingMethodSelectionRequired($order)
            && $stateMachine->can(OrderCheckoutTransitions::TRANSITION_SKIP_SHIPPING)
        ) {
            $stateMachine->apply(OrderCheckoutTransitions::TRANSITION_SKIP_SHIPPING);
        }

        if (
            !$this->orderPaymentMethodSelectionRequirementChecker->isPaymentMethodSelectionRequired($order)
            && $stateMachine->can(OrderCheckoutTransitions::TRANSITION_SKIP_PAYMENT)
        ) {
            $stateMachine->apply(OrderCheckoutTransitions::TRANSITION_SKIP_PAYMENT);
        }

        $firstShipmentMethod = $order->getShipments()->get(0);
        $shipmentMethodCode = $firstShipmentMethod ? $firstShipmentMethod->getMethod()->getCode() : "NO_SHIPMENT";
        if (
            ($shipmentMethodCode === 'NO_SHIPMENT' || $shipmentMethodCode === 'SAM')
            && $stateMachine->can('skip_address')
        ) {
            $stateMachine->apply('skip_address');
        }
    }
}
