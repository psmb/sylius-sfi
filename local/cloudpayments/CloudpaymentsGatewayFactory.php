<?php

declare(strict_types=1);

namespace Psmb\Cloudpayments;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;

class CloudpaymentsGatewayFactory extends GatewayFactory
{
    /**
     * {@inheritdoc}
     */
    protected function populateConfig(ArrayObject $config)
    {
        $config->defaults([
            'payum.factory_name' => 'cloudpayments',
            'payum.factory_title' => 'cloudpayments',
        ]);

        if (false == $config['payum.api']) {
            $config['payum.default_options'] = [
                'publishable_key' => '',
                'secret_key' => '',
                'payment_type' => Keys::PAYMENT_TYPE_CARD,
                'sbp_ttl_minutes' => 30,
                'test_mode' => false,
            ];
            $config->defaults($config['payum.default_options']);
            $config['payum.required_options'] = ['publishable_key', 'secret_key'];
            $config['payum.api'] = function (ArrayObject $config) {
                $config->validateNotEmpty($config['payum.required_options']);

                return new Keys($config['publishable_key'], $config['secret_key'], $config['payment_type']);
            };
        }

        $config['payum.paths'] = array_replace([
            'PsmbCloudpayments' => __DIR__ . '/Resources/views',
        ], $config['payum.paths'] ?: []);
    }
}
