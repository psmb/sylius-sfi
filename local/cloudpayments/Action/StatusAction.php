<?php

declare(strict_types=1);

namespace Psmb\Cloudpayments\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\GetStatusInterface;

class StatusAction implements ActionInterface
{
    /**
     * {@inheritdoc}
     *
     * @param GetStatusInterface $request
     */
    public function execute($request)
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());
        $status = $model['status'] ?? null;
        $cryptogram = $model['cryptogram'] ?? null;
        $sbpQrUrl = $model['sbpQrUrl'] ?? null;

        if (!$status && $cryptogram) {
            $request->markPending();

            return;
        }
        if (!$status && $sbpQrUrl) {
            $request->markPending();

            return;
        }
        if (!$status && !$cryptogram) {
            $request->markNew();

            return;
        }
        if ($status === 'processing') {
            $request->markPending();

            return;
        }
        if ($status === 'captured') {
            $request->markCaptured();

            return;
        }
        if ($status === 'completed') {
            $request->markCaptured();

            return;
        }
        if ($status === 'rejected') {
            $request->markFailed();

            return;
        }
        if ($status === 'failed') {
            $request->markFailed();

            return;
        }
        $request->markUnknown();
    }

    /**
     * {@inheritdoc}
     */
    public function supports($request)
    {
        return
            $request instanceof GetStatusInterface &&
            $request->getModel() instanceof \ArrayAccess
        ;
    }
}
