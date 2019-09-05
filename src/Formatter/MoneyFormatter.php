<?php

declare(strict_types=1);

namespace App\Formatter;

use Webmozart\Assert\Assert;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;

final class MoneyFormatter implements MoneyFormatterInterface
{
    /**
     * {@inheritdoc}
     */
    public function format(int $amount, string $currency, ?string $locale = null): string
    {
        $formatter = new \NumberFormatter($locale ?? 'en', \NumberFormatter::CURRENCY);

        if ($amount % 1000 === 0) {
            $formatter->setAttribute(\NumberFormatter::FRACTION_DIGITS, 0);
        }

        $result = $formatter->formatCurrency(abs($amount / 100), $currency);

        Assert::notSame(
            false,
            $result,
            sprintf('The amount "%s" of type %s cannot be formatted to currency "%s".', $amount, gettype($amount), $currency)
        );

        return $amount >= 0 ? $result : '-' . $result;
    }
}
