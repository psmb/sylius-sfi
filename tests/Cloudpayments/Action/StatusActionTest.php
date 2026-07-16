<?php

declare(strict_types=1);

namespace App\Tests\Cloudpayments\Action;

use Payum\Core\Request\GetHumanStatus;
use PHPUnit\Framework\TestCase;
use Psmb\Cloudpayments\Action\StatusAction;

final class StatusActionTest extends TestCase
{
    public function testEmptyPaymentDetailsAreNewWithoutProducingArrayAccessNotices(): void
    {
        $request = new GetHumanStatus(new \ArrayObject());

        (new StatusAction())->execute($request);

        self::assertTrue($request->isNew());
    }
}
