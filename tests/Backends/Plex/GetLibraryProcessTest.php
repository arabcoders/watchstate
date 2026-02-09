<?php

declare(strict_types=1);

namespace Tests\Backends\Plex;

use App\Backends\Plex\Action\GetLibrary;
use App\Backends\Plex\PlexGuid;
use App\Libs\Entity\StateInterface as iState;
use ReflectionMethod;

class GetLibraryProcessTest extends PlexTestCase
{
    public function test_process_movie_metadata(): void
    {
        $context = $this->makeContext();
        $item = ag($this->fixture('library_movie_get_200'), 'response.body.MediaContainer.Metadata.0');

        $action = new GetLibrary($this->makeHttpClient(), $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);

        $result = $this->invokeProcess(
            $action,
            $context,
            $guid,
            $item,
            ['library' => ['title' => 'Movies']],
            [],
        );

        $this->assertSame((int) $item['ratingKey'], $result[iState::COLUMN_ID]);
        $this->assertStringContainsString('!/server/' . $context->backendId . '/details?key=', $result['webUrl']);
        $this->assertSame('Movies', $result[iState::COLUMN_META_LIBRARY]);
    }

    public function test_process_show_metadata(): void
    {
        $context = $this->makeContext();
        $item = [
            'ratingKey' => 'show-1',
            'type' => 'show',
            'title' => 'Test Show',
            'year' => 2020,
            'Location' => [
                ['path' => '/storage/shows/Test Show'],
            ],
            'Guid' => [
                ['id' => 'imdb://tt123'],
            ],
        ];

        $action = new GetLibrary($this->makeHttpClient(), $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);

        $result = $this->invokeProcess(
            $action,
            $context,
            $guid,
            $item,
            ['library' => ['title' => 'Shows']],
            [],
        );

        $this->assertSame('Test Show', $result[iState::COLUMN_TITLE]);
        $this->assertSame('Shows', $result[iState::COLUMN_META_LIBRARY]);
    }

    public function test_process_unsupported_type_throws(): void
    {
        $context = $this->makeContext();
        $item = [
            'ratingKey' => 'photo-1',
            'type' => 'photo',
            'title' => 'Test Photo',
        ];

        $action = new GetLibrary($this->makeHttpClient(), $this->logger);
        $guid = (new PlexGuid($this->logger))->withContext($context);

        $this->expectException(\App\Libs\Exceptions\Backends\RuntimeException::class);

        $this->invokeProcess(
            $action,
            $context,
            $guid,
            $item,
            ['library' => ['title' => 'Photos']],
            [],
        );
    }

    private function invokeProcess(
        GetLibrary $action,
        \App\Backends\Common\Context $context,
        \App\Backends\Common\GuidInterface $guid,
        array $item,
        array $logContext,
        array $opts,
    ): array {
        $method = new ReflectionMethod($action, 'process');
        return $method->invoke($action, $context, $guid, $item, $logContext, $opts);
    }
}
