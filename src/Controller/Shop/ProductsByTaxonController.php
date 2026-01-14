<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Repository\ProductRepository;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final class ProductsByTaxonController extends AbstractController
{
    /** @var TaxonRepositoryInterface */
    private $taxonRepository;

    /** @var ProductRepository */
    private $productRepository;

    /** @var ChannelContextInterface */
    private $channelContext;

    /** @var LocaleContextInterface */
    private $localeContext;

    /** @var Environment */
    private $twig;

    public function __construct(
        TaxonRepositoryInterface $taxonRepository,
        ProductRepository $productRepository,
        ChannelContextInterface $channelContext,
        LocaleContextInterface $localeContext,
        Environment $twig
    ) {
        $this->taxonRepository = $taxonRepository;
        $this->productRepository = $productRepository;
        $this->channelContext = $channelContext;
        $this->localeContext = $localeContext;
        $this->twig = $twig;
    }

    public function latestAction(string $taxonSlug, int $count, string $template = '@SyliusShop/Product/_horizontalList.html.twig'): Response
    {
        $locale = $this->localeContext->getLocaleCode();
        $taxon = $this->taxonRepository->findOneBySlug($taxonSlug, $locale);

        if (null === $taxon) {
            return new Response('');
        }

        $channel = $this->channelContext->getChannel();
        $products = $this->productRepository->findLatestByTaxon($channel, $taxon, $locale, $count);

        return new Response($this->twig->render($template, ['products' => $products]));
    }
}
