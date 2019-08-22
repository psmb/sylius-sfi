<?php
namespace Psmb\Cloudpayments;

use Psmb\Cloudpayments\Action\AuthorizeAction;
use Psmb\Cloudpayments\Action\CancelAction;
use Psmb\Cloudpayments\Action\ConvertPaymentAction;
use Psmb\Cloudpayments\Action\CaptureAction;
use Psmb\Cloudpayments\Action\NotifyAction;
use Psmb\Cloudpayments\Action\RefundAction;
use Psmb\Cloudpayments\Action\StatusAction;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;
use Psmb\Cloudpayments\Action\Api\ObtainTokenAction;
use Psmb\Cloudpayments\Action\Api\Obtain3dsAction;
use Psmb\Cloudpayments\Action\Api\CreateChargeAction;

class CloudpaymentsGatewayFactory extends GatewayFactory
{
    /**
     * {@inheritDoc}
     */
    protected function populateConfig(ArrayObject $config)
    {
        $config->defaults([
            'payum.factory_name' => 'cloudpayments',
            'payum.factory_title' => 'cloudpayments',
            'payum.action.capture' => new CaptureAction(),
            'payum.action.authorize' => new AuthorizeAction(),
            'payum.action.refund' => new RefundAction(),
            'payum.action.cancel' => new CancelAction(),
            'payum.action.notify' => new NotifyAction(),
            'payum.action.status' => new StatusAction(),
            'payum.action.convert_payment' => new ConvertPaymentAction(),
            'payum.action.obtain_token' => new ObtainTokenAction(),
            'payum.action.obtain_3ds' => new Obtain3dsAction(),
            'payum.action.create_charge' => new CreateChargeAction()
        ]);

        if (false == $config['payum.api']) {
            $config['payum.default_options'] = [
                'publishable_key' => '',
                'secret_key' => ''
            ];
            $config->defaults($config['payum.default_options']);
            $config['payum.required_options'] = ['publishable_key', 'secret_key'];
            $config['payum.api'] = function (ArrayObject $config) {
                $config->validateNotEmpty($config['payum.required_options']);
                return new Keys($config['publishable_key'], $config['secret_key']);
            };
        }

        $config['payum.paths'] = array_replace([
            'PsmbCloudpayments' => __DIR__ . '/Resources/views',
        ], $config['payum.paths'] ?: []);
    }
}
