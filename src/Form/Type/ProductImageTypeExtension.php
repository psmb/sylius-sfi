<?php

declare(strict_types=1);

namespace App\Form\Type;

use Sylius\Bundle\CoreBundle\Form\Type\Product\ProductImageType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\AbstractTypeExtension;

final class ProductImageTypeExtension extends AbstractTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Подпись',
                'required' => false,
            ]);
    }
    public static function getExtendedTypes(): iterable
    {
        return [ProductImageType::class];
    }
}
