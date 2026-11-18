<?php

namespace Tests\Unit;

use App\Domain\Billing\Money;
use App\Domain\Billing\Quote;
use PHPUnit\Framework\TestCase;

final class QuoteTest extends TestCase
{
    public function test_estimate_uses_unicode_length_references_and_midpoint_reasoning(): void
    {
        $quote = (new Quote)->calculate($this->input(), $this->pricing());

        // 3 text + 20 reference + 100 output + 7 reasoning tokens, at $1/million.
        $this->assertSame('0.000162500000', Money::usd($quote['estimate']));
    }

    public function test_reservation_rounds_text_variance_up_and_uses_maximum_reasoning(): void
    {
        $quote = (new Quote)->calculate($this->input(), $this->pricing());

        // ceil(3 * 1.15) + 20 + 100 + 10 = 134 tokens, plus 25% markup.
        $this->assertSame('0.000167500000', Money::usd($quote['maximum']));
        $this->assertGreaterThan($quote['estimate'], $quote['maximum']);
    }

    public function test_reference_images_add_the_same_cost_to_estimate_and_reservation(): void
    {
        $withReferences = (new Quote)->calculate($this->input(), $this->pricing());
        $withoutReferences = (new Quote)->calculate([...$this->input(), 'reference_images' => []], $this->pricing());

        $this->assertSame(Money::fromUsd('0.000025'), $withReferences['estimate'] - $withoutReferences['estimate']);
        $this->assertSame(Money::fromUsd('0.000025'), $withReferences['maximum'] - $withoutReferences['maximum']);
    }

    private function input(): array
    {
        return [
            'prompt' => str_repeat('🌳', 9),
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ];
    }

    private function pricing(): array
    {
        return [
            'rates' => array_fill_keys(['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'], '1.00'),
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => '0.15',
                'per_reference_image' => 10,
                'output' => ['low' => ['1024x1024' => 100]],
                'reasoning' => ['low' => ['min' => 4, 'max' => 10]],
            ],
        ];
    }
}
