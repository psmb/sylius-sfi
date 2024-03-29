<?php

declare(strict_types=1);

namespace App\Form\Type;

use Sylius\Bundle\CoreBundle\Form\Type\Product\ProductImageType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\AbstractTypeExtension;

final class ProductImageTypeExtension extends AbstractTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'Тип файла',
                'required' => false,
                'choices' => ['Обложка' => 'main', 'Предпросмотр PDF' => 'pdf', 'Платный файл' => 'paid'],
            ]);
        $builder
            ->add('fileType', ChoiceType::class, [
                'label' => 'Формат файла',
                'required' => false,
                'choices' => ['pdf' => 'pdf', 'epub' => 'epub', 'mobi' => 'mobi', 'mp3' => 'mp3', 'видео' => 'видео'],
            ]);
        $builder
            ->add('title', TextType::class, [
                'label' => 'Подпись',
                'required' => false,
            ]);
        $builder
            ->add('youtubeId', TextType::class, [
                'label' => 'Идентификтор YouTube Видео (вида "JhzaogGQNFU")',
                'required' => false,
            ]);
    }
    public static function getExtendedTypes(): iterable
    {
        return [ProductImageType::class];
    }
}
