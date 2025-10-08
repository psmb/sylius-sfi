<?php

namespace App\Repository;

use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Bundle\CoreBundle\Doctrine\ORM\ProductRepository as BaseProductRepository;

class ProductRepository extends BaseProductRepository
{
    /**
     * {@inheritdoc}
     */
    public function createShopListQueryBuilder(
        ChannelInterface $channel,
        TaxonInterface $taxon,
        string $locale,
        array $sorting = [],
        bool $includeAllDescendants = false
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('o')
            ->distinct()
            ->addSelect('translation')
            ->addSelect('productTaxon')
            ->innerJoin('o.translations', 'translation', 'WITH', 'translation.locale = :locale')
            ->innerJoin('o.productTaxons', 'productTaxon');

        if ($includeAllDescendants) {
            $queryBuilder
                ->innerJoin('productTaxon.taxon', 'taxon')
                ->leftJoin('taxon.translations', 'taxonTranslation', 'WITH', 'taxonTranslation.locale = :locale')
                ->andWhere('taxon.left >= :taxonLeft')
                ->andWhere('taxon.right <= :taxonRight')
                ->andWhere('taxon.root = :taxonRoot')
                ->setParameter('taxonLeft', $taxon->getLeft())
                ->setParameter('taxonRight', $taxon->getRight())
                ->setParameter('taxonRoot', $taxon->getRoot())
            ;
        } else {
            $queryBuilder
                ->innerJoin('productTaxon.taxon', 'taxon')
                ->leftJoin('taxon.translations', 'taxonTranslation', 'WITH', 'taxonTranslation.locale = :locale')
                ->andWhere('productTaxon.taxon = :taxon')
                ->setParameter('taxon', $taxon)
            ;
        }

        $queryBuilder
            ->andWhere(':channel MEMBER OF o.channels')
            ->andWhere('o.enabled = true')
            ->setParameter('locale', $locale)
            ->setParameter('channel', $channel)
        ;

        // Grid hack, we do not need to join these if we don't sort by price
        if (isset($sorting['price'])) {
            // Another hack, the subquery to get the first position variant
            $subQuery = $this->createQueryBuilder('m')
                 ->select('min(v.position)')
                 ->innerJoin('m.variants', 'v')
                 ->andWhere('m.id = :product_id')
             ;

            $queryBuilder
                ->addSelect('variant')
                ->addSelect('channelPricing')
                ->innerJoin('o.variants', 'variant')
                ->innerJoin('variant.channelPricings', 'channelPricing')
                ->andWhere('channelPricing.channelCode = :channelCode')
                ->andWhere(
                    $queryBuilder->expr()->in(
                        'variant.position',
                        str_replace(':product_id', 'o.id', $subQuery->getDQL())
                    )
                )
                ->setParameter('channelCode', $channel->getCode())
            ;
        }

        return $queryBuilder;
    }

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
