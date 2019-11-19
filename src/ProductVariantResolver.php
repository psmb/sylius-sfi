<?php

declare(strict_types=1);

namespace App;

use Sylius\Component\Product\Model\ProductInterface;
use Sylius\Component\Product\Model\ProductVariantInterface;
use Sylius\Component\Product\Resolver\ProductVariantResolverInterface;

final class ProductVariantResolver implements ProductVariantResolverInterface
{
    /**
     * {@inheritdoc}
     */
    public function getVariant(ProductInterface $subject): ?ProductVariantInterface
    {
        if ($subject->getVariants()->isEmpty()) {
            return null;
        }

        return $subject->getVariants()->last();
    }
}
