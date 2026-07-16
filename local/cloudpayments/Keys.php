<?php

declare(strict_types=1);

namespace Psmb\Cloudpayments;

class Keys
{
    public const PAYMENT_TYPE_CARD = 'card';
    public const PAYMENT_TYPE_SBP = 'sbp';

    /**
     * @var string
     */
    protected $publishable;

    /**
     * @var string
     */
    protected $secret;

    /**
     * @var string
     */
    protected $paymentType;

    /**
     * @param string $publishable
     * @param string $secret
     */
    public function __construct($publishable, $secret, $paymentType = self::PAYMENT_TYPE_CARD)
    {
        $this->publishable = $publishable;
        $this->secret = $secret;
        $this->paymentType = $paymentType ?: self::PAYMENT_TYPE_CARD;
    }

    /**
     * @return string
     */
    public function getSecretKey()
    {
        return $this->secret;
    }

    /**
     * @return string
     */
    public function getPublishableKey()
    {
        return $this->publishable;
    }

    /**
     * @return string
     */
    public function getPaymentType()
    {
        return $this->paymentType;
    }
}
