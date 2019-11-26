<?php

declare(strict_types=1);

namespace App\Entity\Product;

use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;
use Sylius\Component\Core\Model\ProductVariant as BaseProductVariant;
use Sylius\Component\Product\Model\ProductVariantTranslationInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * @Entity
 * @Table(name="sylius_product_variant")
 */
class ProductVariant extends BaseProductVariant
{
    protected function createTranslation(): ProductVariantTranslationInterface
    {
        return new ProductVariantTranslation();
    }

    /** @ORM\Column(type="string", nullable=true) */
    private $originalPrice;

    public function getOriginalPrice(): ?string
    {
        return $this->originalPrice;
    }

    public function setOriginalPrice(string $originalPrice): void
    {
        $this->originalPrice = $originalPrice;
    }
}
