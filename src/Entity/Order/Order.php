<?php

declare(strict_types=1);

namespace App\Entity\Order;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;
use Sylius\Component\Core\Model\Order as BaseOrder;

/**
 * @Entity
 * @Table(name="sylius_order")
 */
class Order extends BaseOrder
{
    /** @ORM\Column(type="string", nullable=true) */
    private $postomat;

    /** @ORM\Column(type="string", nullable=true) */
    private $cityToId;

    public function getPostomat(): ?string
    {
        return $this->postomat;
    }

    public function setPostomat(string $postomat): void
    {
        $this->postomat = $postomat;
    }

    public function getCityToId(): ?string
    {
        return $this->cityToId;
    }

    public function setCityToId(string $cityToId): void
    {
        $this->cityToId = $cityToId;
    }
}
