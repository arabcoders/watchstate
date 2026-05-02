<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Path;
use App\Libs\TestCase;
use RuntimeException;

class PathTest extends TestCase
{
    protected function setUp(): void
    {
        $this->initTempDir();
    }

    public function test_make_join_and_string(): void
    {
        $path = Path::make(self::$tmpPath)->joinPath('foo', 'bar');

        $this->assertStringEndsWith('foo' . DIRECTORY_SEPARATOR . 'bar', (string) $path);
    }

    public function test_exists_dir_and_file(): void
    {
        $dir = Path::make(self::$tmpPath);
        $file = $this->path('file.txt');
        $file->write('data');

        $this->assertTrue($dir->exists());
        $this->assertTrue($dir->isDir());
        $this->assertFalse($dir->isFile());
        $this->assertTrue($file->exists());
        $this->assertTrue($file->isFile());
        $this->assertFalse($file->isDir());
    }

    public function test_mkdir_and_rmdir(): void
    {
        $dir = $this->path('sub_dir');

        $dir->mkdir();
        $this->assertTrue($dir->isDir());

        $dir->rmdir();
        $this->assertFalse($dir->exists());
    }

    public function test_mkdir_recursive_and_exist_ok(): void
    {
        $dir = $this->path('foo', 'bar');

        $dir->mkdir(0o777, true);
        $dir->mkdir(0o777, false, true);

        $this->assertTrue($dir->isDir());
    }

    public function test_mkdir_throws_when_exists(): void
    {
        $dir = $this->path('sub_dir');
        $dir->mkdir();

        $this->expectException(RuntimeException::class);
        $dir->mkdir();
    }

    public function test_read_write_and_unlink(): void
    {
        $file = $this->path('file.txt');

        $file->write('hello');
        $this->assertSame('hello', $file->read());

        $file->unlink();
        $this->assertFalse($file->exists());
    }

    public function test_read_throws_for_directory(): void
    {
        $this->expectException(RuntimeException::class);
        Path::make(self::$tmpPath)->read();
    }

    public function test_unlink_throws_for_directory(): void
    {
        $this->expectException(RuntimeException::class);
        Path::make(self::$tmpPath)->unlink();
    }

    public function test_rmdir_throws_for_file(): void
    {
        $file = $this->path('file.txt');
        $file->write('data');

        $this->expectException(RuntimeException::class);
        $file->rmdir();
    }

    public function test_absolute_and_path_parts(): void
    {
        $file = $this->path('foo.bar.txt');
        $file->write('abc');
        $absolute = $file->absolute();

        $this->assertTrue($absolute->exists());
        $this->assertSame('foo.bar.txt', $file->name());
        $this->assertSame('foo.bar', $file->stem());
        $this->assertSame('txt', $file->suffix());
        $this->assertSame(self::$tmpPath, (string) $file->parent());
        $this->assertStringEndsWith('bar.txt', (string) $file->withName('bar.txt'));
        $this->assertStringEndsWith('.md', (string) $file->withSuffix('.md'));
    }

    public function test_glob_and_iterdir(): void
    {
        $this->path('b.txt')->write('2');
        $this->path('a.txt')->write('1');

        $dir = Path::make(self::$tmpPath);
        $glob = $dir->glob('*.txt');
        $items = $dir->iterDir();

        $this->assertCount(2, $glob);
        $this->assertContainsOnlyInstancesOf(Path::class, $glob);
        $this->assertCount(2, $items);
        $this->assertContainsOnlyInstancesOf(Path::class, $items);

        $names = array_map(static fn(Path $path): string => $path->name, $items);
        sort($names);
        $this->assertSame(['a.txt', 'b.txt'], $names);
    }

    public function test_iterdir_throws_for_file(): void
    {
        $file = $this->path('file.txt');
        $file->write('abc');

        $this->expectException(RuntimeException::class);
        $file->iterDir();
    }

    public function test_chmod_and_stat(): void
    {
        $file = $this->path('file.txt');
        $file->write('abc');
        $file->chmod(0o644);

        $this->assertSame(3, $file->stat()['size']);
    }

    public function test_chmod_throws_for_missing_path(): void
    {
        $this->expectException(RuntimeException::class);
        Path::make('/nonexistent/path')->chmod(0o644);
    }

    public function test_owner_and_group(): void
    {
        if (false === extension_loaded('posix')) {
            $this->markTestSkipped('POSIX extension is not available.');
        }

        $file = $this->path('file.txt');
        $file->write('abc');

        $this->assertIsString($file->owner());
        $this->assertIsString($file->group());
    }

    public function test_absolute_relative_and_same_file(): void
    {
        $file = $this->path('file.txt');
        $other = $this->path('other.txt');
        $file->write('abc');
        $other->write('def');

        $this->assertTrue($file->isAbsolute());
        $this->assertFalse(Path::make('file.txt')->isAbsolute());
        $this->assertTrue(Path::make('file.txt')->isRelative());
        $this->assertTrue($file->sameFile($this->path('file.txt')));
        $this->assertFalse($file->sameFile($other));
    }

    public function test_normalize_paths(): void
    {
        $unix = Path::make('/foo/bar/../baz/./qux')->normalize('/foo/bar/../baz/./qux', '/');
        $windows = Path::make('C:\\foo\\bar\\..\\baz\\.\\qux')
            ->normalize('C:\\foo\\bar\\..\\baz\\.\\qux', '\\');
        $unc = Path::make('\\\\server\\share\\dir1\\dir2\\..\\..\\file')
            ->normalize('\\\\server\\share\\dir1\\dir2\\..\\..\\file', '\\');

        $this->assertSame('/foo/baz/qux', (string) $unix);
        $this->assertSame('C:\\foo\\baz\\qux', (string) $windows);
        $this->assertSame('\\\\server\\share\\file', (string) $unc);
    }

    public function test_symlink_helpers(): void
    {
        if (str_starts_with(PHP_OS, 'WIN')) {
            $this->markTestSkipped('Symlink creation is not supported or requires admin privileges on Windows.');
        }

        $target = $this->path('target.txt');
        $target->write('abc');
        $link = $this->path('link.txt');
        $link->symlinkTo($target);

        $this->assertTrue($link->isSymlink());
        $this->assertSame((string) $target, (string) $link->resolve());
        $this->assertTrue($link->sameFile($target));
    }

    public function test_symlink_to_throws_on_failure(): void
    {
        $link = Path::make('/sys/impossible_link_' . uniqid('', true));
        $target = $this->path('target.txt');
        $target->write('target');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not create symlink');
        $link->symlinkTo($target);
    }

    public function test_relative_to_and_match(): void
    {
        $base = Path::make(self::$tmpPath);
        $file = $this->path('foo.txt');
        $file->write('abc');

        $this->assertSame('foo.txt', (string) $file->relativeTo($base));
        $this->assertTrue($file->match('*.txt'));
        $this->assertFalse($file->match('*.md'));

        $this->expectException(RuntimeException::class);
        $file->relativeTo('/nonexistent');
    }

    public function test_rename_replace_and_touch(): void
    {
        $source = $this->path('source.txt');
        $source->write('source content');

        $renamed = $this->path('renamed.txt');
        $source->rename($renamed);
        $this->assertFalse($source->exists());
        $this->assertSame('source content', $renamed->read());

        $replacement = $this->path('replacement.txt');
        $replacement->write('replacement');
        $renamed->replace($replacement);
        $this->assertFalse($renamed->exists());
        $this->assertSame('source content', $replacement->read());

        $touched = $this->path('touched.txt');
        $touched->touch();
        $this->assertTrue($touched->isFile());
        $this->assertSame('', $touched->read());
    }

    public function test_virtual_properties(): void
    {
        $file = $this->path('foo.bar.txt');
        $file->write('abc');

        $this->assertSame($file->name(), $file->name);
        $this->assertSame($file->stem(), $file->stem);
        $this->assertSame($file->suffix(), $file->suffix);
        $this->assertInstanceOf(Path::class, $file->parent);
        $this->assertSame((string) $file->absolute(), (string) $file->absolute);
    }

    public function test_unknown_virtual_property_throws(): void
    {
        $file = $this->path('foo.txt');

        $this->expectException(RuntimeException::class);
        /** @noinspection PhpUndefinedFieldInspection */
        $file->unknown_property;
    }

    public function test_empty_path(): void
    {
        $path = Path::make('');

        $this->assertFalse($path->exists());
        $this->assertFalse($path->isDir());
        $this->assertFalse($path->isFile());
        $this->assertSame('', $path->name());
        $this->assertSame('', $path->stem());
        $this->assertSame('', $path->suffix());
    }

    public function test_children_files_match_sidecars(): void
    {
        $main = $this->path('my.movie.title.mp4');
        $main->write('video');
        $this->path('my.movie.title.srt')->write('subtitles');
        $this->path('my.movie.title.en.ass')->write('english subtitles');
        $this->path('my.movie.title.eng.forced.srt')->write('forced subtitles');
        $this->path('my.movie.title.nfo')->write('metadata');
        $this->path('my.movie.title.extras')->mkdir();

        $sidecars = $main->childrenFiles();
        $names = array_map(static fn(Path $path): string => $path->name, $sidecars);
        sort($names);

        $this->assertContainsOnlyInstancesOf(Path::class, $sidecars);
        $this->assertSame(
            ['my.movie.title.en.ass', 'my.movie.title.eng.forced.srt', 'my.movie.title.nfo', 'my.movie.title.srt'],
            $names
        );
    }

    public function test_children_files_escape_glob_characters(): void
    {
        $main = $this->path('movie?[2020]*.mkv');
        $main->write('video');
        $this->path('movie?[2020]*.srt')->write('subtitles');
        $this->path('moviex2020y.srt')->write('other subtitles');

        $sidecars = $main->childrenFiles();

        $this->assertCount(1, $sidecars);
        $this->assertSame('movie?[2020]*.srt', $sidecars[0]->name);
    }

    public function test_children_files_nfo_style_artwork(): void
    {
        $main = $this->path('movie.mkv');
        $main->write('video');
        $this->path('poster.jpg')->write('poster');
        $this->path('fanart.jpeg')->write('fanart');
        $this->path('movie.srt')->write('subtitles');

        $sidecars = $main->childrenFiles(true);
        $names = array_map(static fn(Path $path): string => $path->name, $sidecars);
        sort($names);

        $this->assertSame(['fanart.jpeg', 'movie.srt', 'poster.jpg'], $names);
    }

    private function path(string ...$segments): Path
    {
        return Path::make(self::$tmpPath)->joinPath(...$segments);
    }
}
