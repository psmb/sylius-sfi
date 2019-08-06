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
        ]);

        if (false == $config['payum.api']) {
            $config['payum.default_options'] = array(
                'sandbox' => true,
            );
            $config->defaults($config['payum.default_options']);
            $config['payum.required_options'] = [];

            $config['payum.api'] = function (ArrayObject $config) {
                $config->validateNotEmpty($config['payum.required_options']);

                return new Api((array) $config, $config['payum.http_client'], $config['httplug.message_factory']);
            };
        }
    }
}
