<?php

declare(strict_types=1);

namespace App\Entity\Product;

use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;
use Sylius\Component\Core\Model\ProductImage as BaseProductImage;
use Doctrine\ORM\Mapping as ORM;

/**
 * @Entity
 * @Table(name="sylius_product_image")
 */
class ProductImage extends BaseProductImage
{
    /** @ORM\Column(type="string", nullable=true) */
    private $title;

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }
}
