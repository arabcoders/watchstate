<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Stream;
use App\Libs\TestCase;
use App\Libs\VttConverter;

class VttConverterTest extends TestCase
{
    protected function getData(): string
    {
        return (string)Stream::make(__DIR__ . '/../Fixtures/subtitle.vtt', 'r');
    }

    protected function getExportedData(): string
    {
        return (string)Stream::make(__DIR__ . '/../Fixtures/subtitle.exported.vtt', 'r');
    }

    protected function getJSON(): array
    {
        return json_decode((string)Stream::make(__DIR__ . '/../Fixtures/subtitle.json', 'r'), true);
    }

    public function test_parse()
    {
        $data = VttConverter::parse($this->getData());

        $this->assertEquals($this->getJSON(), $data, 'Failed to parse VTT file');
        $this->assertEquals(
            trim($this->getExportedData()),
            trim(VttConverter::export($data)),
            'Failed to export VTT file'
        );
    }

    public function test_exceptions()
    {
        $this->checkException(
            closure: function () {
                $text = <<<VTT
                WEBVTT

                00:00:14 --> 00:00:21
                test

                VTT;

                $data = VttConverter::parse($text);
                dump($data);
                return $data;
            },
            reason: 'Invalid VTT file',
            exception: \InvalidArgumentException::class,
        );
    }
}
