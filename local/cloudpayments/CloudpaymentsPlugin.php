<?php
declare(strict_types=1);

namespace Psmb\Cloudpayments;

use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class CloudpaymentsPlugin extends Bundle
{
    use SyliusPluginTrait;
}
