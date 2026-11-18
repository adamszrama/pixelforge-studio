<?php

namespace Tests\Unit;

use App\Domain\Billing\Money;
use Brick\Math\Exception\RoundingNecessaryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class MoneyTest extends TestCase
{
    #[DataProvider('dollarAmounts')]
    public function test_dollar_conversion_preserves_every_picodollar(string $dollars, int $units): void
    {
        $this->assertSame($units, Money::fromUsd($dollars));
        $this->assertSame($dollars, Money::usd($units));
    }

    public static function dollarAmounts(): array
    {
        return [
            'zero' => ['0.000000000000', 0],
            'one picodollar' => ['0.000000000001', 1],
            'fraction of a cent' => ['0.001234567890', 1234567890],
            'negative ledger amount' => ['-10.000000000000', -10000000000000],
        ];
    }

    public function test_markup_is_exact_for_a_single_provider_nanodollar(): void
    {
        $this->assertSame(1250, Money::markedUp(1000));
        $this->assertSame(0, Money::markedUp(0));
    }

    public function test_unrepresentable_dollar_amounts_are_not_silently_rounded(): void
    {
        $this->expectException(RoundingNecessaryException::class);
        Money::fromUsd('0.0000000000001');
    }

    public function test_each_token_category_uses_its_own_per_million_rate(): void
    {
        $usage = [
            'input_text_tokens' => 1,
            'input_image_tokens' => 2,
            'output_tokens' => 3,
            'reasoning_tokens' => 4,
        ];
        $rates = [
            'input_text_tokens' => '0.10',
            'input_image_tokens' => '0.20',
            'output_tokens' => '0.30',
            'reasoning_tokens' => '0.40',
        ];

        $this->assertSame('0.000003000000', Money::usd(Money::tokenCost($usage, $rates)));
    }

    #[DataProvider('invalidBillingData')]
    public function test_invalid_billing_data_cannot_produce_a_charge(array $usage, array $rates): void
    {
        $this->expectException(UnexpectedValueException::class);
        Money::tokenCost($usage, $rates);
    }

    public static function invalidBillingData(): array
    {
        $usage = array_fill_keys(['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'], 1);
        $rates = array_fill_keys(array_keys($usage), '1.00');
        $missingUsage = $usage;
        unset($missingUsage['reasoning_tokens']);
        $missingRates = $rates;
        unset($missingRates['reasoning_tokens']);

        return [
            'missing usage' => [$missingUsage, $rates],
            'missing rate' => [$usage, $missingRates],
            'negative tokens' => [[...$usage, 'output_tokens' => -1], $rates],
            'fractional tokens' => [[...$usage, 'output_tokens' => 1.5], $rates],
            'string tokens' => [[...$usage, 'output_tokens' => '1'], $rates],
            'negative rate' => [$usage, [...$rates, 'output_tokens' => '-0.01']],
        ];
    }
}
