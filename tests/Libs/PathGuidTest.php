<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\PathGuid;
use App\Libs\TestCase;

class PathGuidTest extends TestCase
{
    public function test_movie_hash(): void
    {
        $this->assertSame(
            [
                iState::COLUMN_GUIDS => [
                    Guid::GUID_PATH => md5('movie:/movie_title/movie_title.mkv'),
                ],
            ],
            PathGuid::get(iState::TYPE_MOVIE, '/home/foo/media/movies/Movie_Title/Movie_Title.MKV'),
        );
    }

    public function test_episode_hash(): void
    {
        $this->assertSame(
            [
                iState::COLUMN_GUIDS => [
                    Guid::GUID_PATH => md5('episode:/show_title/season/s01e01.mp4/1/1'),
                ],
                iState::COLUMN_PARENT => [
                    Guid::GUID_PATH => md5('show:/show_title/season'),
                ],
            ],
            PathGuid::get(iState::TYPE_EPISODE, '/home/foo/media/tv/show_title/season/S01E01.MP4', 1, 1),
        );
    }

    public function test_normalizes_path(): void
    {
        $expected = PathGuid::get(iState::TYPE_EPISODE, '/media/tv/show/season/s01e01.mkv', 1, 1);

        $this->assertSame(
            $expected,
            PathGuid::get(iState::TYPE_EPISODE, 'Z:\\TV\\SHOW\\SEASON\\S01E01.MKV', 1, 1),
        );
        $this->assertSame(
            $expected,
            PathGuid::get(iState::TYPE_EPISODE, '/media//tv//SHOW//SEASON//S01E01.MKV', 1, 1),
        );
    }

    public function test_episode_coordinates(): void
    {
        $first = PathGuid::get(iState::TYPE_EPISODE, '/media/tv/show/season/S01E01-E02.mkv', 1, 1);
        $second = PathGuid::get(iState::TYPE_EPISODE, '/media/tv/show/season/S01E01-E02.mkv', 1, 2);

        $this->assertSame(
            $first[iState::COLUMN_PARENT][Guid::GUID_PATH],
            $second[iState::COLUMN_PARENT][Guid::GUID_PATH],
        );
        $this->assertNotSame(
            $first[iState::COLUMN_GUIDS][Guid::GUID_PATH],
            $second[iState::COLUMN_GUIDS][Guid::GUID_PATH],
        );
    }

    public function test_rejects_invalid(): void
    {
        $this->assertSame([], PathGuid::get(iState::TYPE_MOVIE, ''));
        $this->assertSame([], PathGuid::get(iState::TYPE_MOVIE, '/media/movies/movie_title'));
        $this->assertSame([], PathGuid::get(iState::TYPE_MOVIE, '/movie.mkv'));
        $this->assertSame([], PathGuid::get(iState::TYPE_EPISODE, '/season/s01e01.mkv', 1, 1));
        $this->assertSame([], PathGuid::get(iState::TYPE_EPISODE, '/tv/show/season/s01e01.mkv'));
    }
}
