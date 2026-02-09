<?php

declare(strict_types=1);

namespace Tests\Model\Base\Transformers;

use App\Libs\TestCase;
use App\Model\Base\Enums\ScalarType;
use App\Model\Base\Enums\TransformType;
use App\Model\Base\Transformers\ArrayTransformer;
use App\Model\Base\Transformers\DateTransformer;
use App\Model\Base\Transformers\EnumTransformer;
use App\Model\Base\Transformers\JSONTransformer;
use App\Model\Base\Transformers\ScalarTransformer;
use App\Model\Base\Transformers\SerializeTransformer;
use App\Model\Base\Transformers\TimestampTransformer;
use App\Model\Events\EventStatus;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;

final class TransformersTest extends TestCase
{
    public function test_json_transformer_encode_decode_assoc(): void
    {
        $transformer = new JSONTransformer(isAssoc: true);
        $data = ['name' => 'watchstate', 'count' => 3];

        $encoded = $transformer(TransformType::ENCODE, $data);
        $this->assertIsString($encoded);

        $decoded = $transformer(TransformType::DECODE, $encoded);
        $this->assertSame($data, $decoded);
    }

    public function test_json_transformer_create_factory(): void
    {
        $transformer = JSONTransformer::create(isAssoc: true, nullable: true);
        $this->assertIsCallable($transformer);

        $this->assertNull($transformer(TransformType::ENCODE, null));
    }

    public function test_json_transformer_nullable_requires_flag(): void
    {
        $transformer = new JSONTransformer();

        $this->checkException(
            fn() => $transformer(TransformType::ENCODE, null),
            'JSON transformer rejects null when not nullable',
            InvalidArgumentException::class,
        );

        $nullableTransformer = new JSONTransformer(nullable: true);
        $this->assertNull($nullableTransformer(TransformType::ENCODE, null));
    }

    public function test_array_transformer_roundtrip(): void
    {
        $transformer = new ArrayTransformer();
        $payload = ['one' => 1, 'two' => 'b'];

        $encoded = $transformer(TransformType::ENCODE, $payload);
        $this->assertIsString($encoded);

        $decoded = $transformer(TransformType::DECODE, $encoded);
        $this->assertSame($payload, $decoded);
    }

    public function test_array_transformer_create_factory(): void
    {
        $transformer = ArrayTransformer::create(nullable: true);
        $this->assertIsCallable($transformer);

        $this->assertNull($transformer(TransformType::ENCODE, null));
    }

    public function test_date_transformer_encode_decode(): void
    {
        $transformer = new DateTransformer();
        $date = new DateTimeImmutable('2024-01-01T00:00:00+00:00');

        $encoded = $transformer(TransformType::ENCODE, $date);
        $this->assertSame($date->format(DateTimeInterface::ATOM), $encoded);

        $decoded = $transformer(TransformType::DECODE, $encoded);
        $this->assertInstanceOf(DateTimeInterface::class, $decoded);
    }

    public function test_date_transformer_nullable_allows_null(): void
    {
        $transformer = new DateTransformer(nullable: true);
        $this->assertNull($transformer(TransformType::ENCODE, null));
    }

    public function test_date_transformer_rejects_invalid_type(): void
    {
        $transformer = new DateTransformer();

        $this->checkException(
            fn() => $transformer(TransformType::ENCODE, ['bad' => 'data']),
            'Date transformer rejects arrays',
            RuntimeException::class,
        );
    }

    public function test_timestamp_transformer_encode_decode(): void
    {
        $transformer = new TimestampTransformer();
        $date = new DateTimeImmutable('2024-01-02T00:00:00+00:00');

        $encoded = $transformer(TransformType::ENCODE, $date);
        $this->assertIsInt($encoded);

        $decoded = $transformer(TransformType::DECODE, $encoded);
        $this->assertInstanceOf(DateTimeInterface::class, $decoded);
    }

    public function test_timestamp_transformer_nullable_allows_null(): void
    {
        $transformer = new TimestampTransformer(nullable: true);
        $this->assertNull($transformer(TransformType::ENCODE, null));
    }

    public function test_enum_transformer_roundtrip(): void
    {
        $transformer = new EnumTransformer(EventStatus::class);

        $encoded = $transformer(TransformType::ENCODE, EventStatus::SUCCESS);
        $this->assertSame(EventStatus::SUCCESS->value, $encoded);

        $decoded = $transformer(TransformType::DECODE, $encoded);
        $this->assertSame(EventStatus::SUCCESS, $decoded);
    }

    public function test_enum_transformer_create_factory(): void
    {
        $transformer = EnumTransformer::create(EventStatus::class);
        $this->assertIsCallable($transformer);

        $decoded = $transformer(TransformType::DECODE, EventStatus::FAILED->value);
        $this->assertSame(EventStatus::FAILED, $decoded);
    }

    public function test_scalar_transformer_casts_values(): void
    {
        $stringTransformer = new ScalarTransformer(ScalarType::STRING);
        $this->assertSame('42', $stringTransformer(TransformType::ENCODE, 42));

        $intTransformer = new ScalarTransformer(ScalarType::INT);
        $this->assertSame(7, $intTransformer(TransformType::DECODE, '7'));

        $floatTransformer = new ScalarTransformer(ScalarType::FLOAT);
        $this->assertSame(1.5, $floatTransformer(TransformType::ENCODE, '1.5'));

        $boolTransformer = new ScalarTransformer(ScalarType::BOOL);
        $this->assertTrue($boolTransformer(TransformType::DECODE, 1));
    }

    public function test_scalar_transformer_create_factory(): void
    {
        $transformer = ScalarTransformer::create(ScalarType::INT);
        $this->assertIsCallable($transformer);
        $this->assertSame(5, $transformer(TransformType::DECODE, '5'));
    }

    public function test_serialize_transformer_roundtrip(): void
    {
        $transformer = new SerializeTransformer();
        $payload = ['a' => 1, 'b' => 'c'];

        $encoded = $transformer(TransformType::ENCODE, $payload);
        $this->assertIsString($encoded);

        $decoded = $transformer(TransformType::DECODE, $encoded);
        $this->assertSame($payload, $decoded);
    }

}
