<?php

namespace Tests\Unit;

use App\Support\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    private PhoneNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new PhoneNormalizer('98');
    }

    #[DataProvider('numbers')]
    public function test_it_normalizes_known_numbers(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, $this->normalizer->normalize($input));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function numbers(): array
    {
        return [
            'mobile with trunk zero' => ['09121234567', '9121234567'],
            'mobile without trunk zero' => ['9121234567', '9121234567'],
            'international plus and spaces' => ['+98 912 123 4567', '9121234567'],
            'international 00 prefix' => ['00989121234567', '9121234567'],
            'landline with trunk zero' => ['02188776655', '2188776655'],
            'three digit extension' => ['101', '101'],
            'extension is too short' => ['1001', null],
            'letters' => ['abc', null],
            'empty' => ['', null],
            'null' => [null, null],
            'email-shaped' => ['0912@example.com', null],
            'punctuation only' => ['+ - ()', null],
        ];
    }
}
