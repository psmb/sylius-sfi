<?php

declare(strict_types=1);

namespace App\Form\Type;

use Sylius\Bundle\CoreBundle\Form\Type\Product\ProductVariantType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\AbstractTypeExtension;

final class ProductVariantTypeExtension extends AbstractTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('originalPrice', NumberType::class, [
                'label' => 'Цена без скидки',
                'required' => false,
            ]);
    }
    public static function getExtendedTypes(): iterable
    {
        return [ProductVariantType::class];
    }
}
