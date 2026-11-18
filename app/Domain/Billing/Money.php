<?php

namespace App\Domain\Billing;

use Brick\Math\BigDecimal;

/** Integer picodollars preserve provider nanodollars AND an exact 25% markup. */
final class Money
{
    public const SCALE = 1_000_000_000_000;

    public static function fromUsd(string $usd): int
    {
        return BigDecimal::of($usd)->multipliedBy(self::SCALE)->toBigInteger()->toInt();
    }

    public static function usd(int $amount): string
    {
        return (string) BigDecimal::of($amount)->dividedBy(self::SCALE, 12);
    }

    public static function markedUp(int $providerCost): int
    {
        return BigDecimal::of($providerCost)->multipliedBy('1.25')->toBigInteger()->toInt();
    }

    public static function tokenCost(array $usage, array $rates): int
    {
        $total = BigDecimal::zero();
        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $type) {
            if (! isset($usage[$type], $rates[$type]) || ! is_int($usage[$type]) || $usage[$type] < 0) {
                throw new \UnexpectedValueException("Missing or invalid billing data: {$type}");
            }
            $rate = BigDecimal::of($rates[$type]);
            if ($rate->isNegative()) {
                throw new \UnexpectedValueException('Rates cannot be negative.');
            }
            $total = $total->plus($rate->multipliedBy($usage[$type]));
        }

        return $total->multipliedBy(1_000_000)->toBigInteger()->toInt();
    }
}
