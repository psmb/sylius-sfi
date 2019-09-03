<?php

namespace App\Repository;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductRepository as BaseProductRepository;

class ProductRepository extends BaseProductRepository
{
    /**
     * {@inheritdoc}
     */
    public function findLatestByChannel(ChannelInterface $channel, string $locale, int $count): array
    {
        return $this->createQueryBuilder('o')
            ->addSelect('translation')
            ->innerJoin('o.translations', 'translation', 'WITH', 'translation.locale = :locale')
            ->leftJoin('o.attributes', 'attributeValue', 'WITH', 'attributeValue.localeCode = :locale')
            ->leftJoin('attributeValue.attribute', 'attribute', 'WITH', 'attribute.code = :code')
            ->andWhere(':channel MEMBER OF o.channels')
            ->andWhere('o.enabled = true')
            ->addOrderBy('attributeValue.integer', 'DESC')
            ->setParameter('channel', $channel)
            ->setParameter('locale', $locale)
            ->setParameter('code', 'publish_date')
            ->setMaxResults($count)
            ->getQuery()
            ->getResult();
    }
}
