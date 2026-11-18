<?php

namespace App\Domain\Billing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class Quote
{
    public function calculate(array $input, array $pricing): array
    {
        $tokens = $pricing['tokens'];
        $text = (int) ceil(mb_strlen($input['prompt']) / $tokens['characters_per_text_token']);
        $range = $tokens['reasoning'][$input['quality']];
        $usage = [
            'input_text_tokens' => $text,
            'input_image_tokens' => count($input['reference_images']) * $tokens['per_reference_image'],
            'output_tokens' => $tokens['output'][$input['quality']][$input['size']],
            'reasoning_tokens' => intdiv($range['min'] + $range['max'], 2),
        ];
        $estimate = Money::markedUp(Money::tokenCost($usage, $pricing['rates']));
        $usage['input_text_tokens'] = BigDecimal::of($text)
            ->multipliedBy(BigDecimal::of((string) $tokens['text_token_variance'])->plus(1))
            ->toScale(0, RoundingMode::Ceiling)->toInt();
        $usage['reasoning_tokens'] = $range['max'];

        return ['estimate' => $estimate, 'maximum' => Money::markedUp(Money::tokenCost($usage, $pricing['rates']))];
    }
}
