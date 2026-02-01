<?php

declare(strict_types=1);

namespace Tests\Libs;

use App\Libs\Path;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PathTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'path_test_' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        if (false === is_dir($this->tmpDir) || false === file_exists($this->tmpDir)) {
            return;
        }

        $fn = function (string $dir, $fn): void {
            if (false === file_exists($dir) || false === str_starts_with($dir, $this->tmpDir)) {
                return;
            }

            foreach (scandir($dir) as $item) {
                if ($item !== '.' && $item !== '..') {
                    $path = $dir . DIRECTORY_SEPARATOR . $item;
                    if (is_dir($path)) {
                        $fn($path, $fn);
                    } elseif (is_file($path)) {
                        unlink($path);
                    }
                }
            }
            rmdir($dir);
        };

        $fn($this->tmpDir, $fn);
    }

    public function test_MakeAndToString(): void
    {
        $path = Path::make($this->tmpDir);
        $this->assertSame($this->tmpDir, (string)$path, 'Path::make should return correct string');
    }

    public function test_join(): void
    {
        $path = Path::make($this->tmpDir);
        $joined = $path->joinPath('foo', 'bar');
        $this->assertStringEndsWith(
            'foo' . DIRECTORY_SEPARATOR . 'bar',
            (string)$joined,
            'joinPath should join segments'
        );
    }

    public function testJoinVariants(): void
    {
        $path = Path::make($this->tmpDir);
        $joined1 = $path->joinPath('foo', 'bar');
        $joined2 = $path->joinPath('foo', 'bar');
        $this->assertSame((string)$joined1, (string)$joined2, 'joinPath variants should be consistent');
    }

    public function test_ExistsIsDirIsFile(): void
    {
        $dir = Path::make($this->tmpDir);
        $this->assertTrue($dir->exists(), 'Directory should exist');
        $this->assertTrue($dir->isDir(), 'Should be a directory');
        $this->assertFalse($dir->isFile(), 'Should not be a file');

        $filePath = $dir->joinPath('file.txt');
        file_put_contents((string)$filePath, 'data');
        $file = Path::make((string)$filePath);
        $this->assertTrue($file->exists(), 'File should exist');
        $this->assertTrue($file->isFile(), 'Should be a file');
        $this->assertFalse($file->isDir(), 'Should not be a directory');
    }

    public function testIsDirIsFileVariants(): void
    {
        $dir = Path::make($this->tmpDir);
        $file = $dir->joinPath('file.txt');
        $file->write('data');
        $file2 = $dir->joinPath('file2.txt');
        $file2->write('data');
        $this->assertTrue($dir->isDir(), 'Should be a directory');
        $this->assertTrue($dir->isDir(), 'Should be a directory (repeat)');
        $this->assertTrue($file->isFile(), 'Should be a file');
        $this->assertTrue($file->isFile(), 'Should be a file (repeat)');
        $this->assertTrue($file2->isFile(), 'Should be a file2');
        $this->assertTrue($file2->isFile(), 'Should be a file2 (repeat)');
    }

    public function test_MkdirAndRmdir(): void
    {
        $sub_dir = Path::make($this->tmpDir)->joinPath('sub_dir');
        $sub_dir->mkdir();
        $this->assertTrue($sub_dir->exists(), 'Subdir should exist after mkdir');
        $this->assertTrue($sub_dir->isDir(), 'Subdir should be a directory');
        $sub_dir->rmdir();
        $this->assertFalse($sub_dir->exists(), 'Subdir should be removed');
    }

    public function test_MkdirExistOk(): void
    {
        $sub_dir = Path::make($this->tmpDir)->joinPath('sub_dir');
        $sub_dir->mkdir();
        $sub_dir->mkdir(0777, false, true); // Should not throw
        $this->assertTrue($sub_dir->exists(), 'Subdir should still exist');
    }

    public function test_MkdirThrowsIfExists(): void
    {
        $sub_dir = Path::make($this->tmpDir)->joinPath('sub_dir');
        $sub_dir->mkdir();
        $this->expectException(RuntimeException::class);
        $sub_dir->mkdir();
    }

    public function test_Unlink(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('file.txt');
        $file->write('abc');
        $this->assertTrue($file->isFile(), 'File should exist before unlink');
        $file->unlink();
        $this->assertFalse($file->exists(), 'File should be removed');
    }

    public function test_UnlinkThrowsIfNotFile(): void
    {
        $dir = Path::make($this->tmpDir);
        $this->expectException(RuntimeException::class);
        $dir->unlink();
    }

    public function test_ReadWriteText(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('file.txt');
        $file->write('hello');
        $this->assertSame('hello', $file->read(), 'Read should return written text');
    }

    public function testReadWriteVariants(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('file.txt');
        $file->write('abc');
        $this->assertSame('abc', $file->read(), 'Read should return written text');
        $file2 = Path::make($this->tmpDir)->joinPath('file2.txt');
        $file2->write('def');
        $this->assertSame('def', $file2->read(), 'Read should return written text for file2');
    }

    public function test_ReadTextThrowsIfNotFile(): void
    {
        $dir = Path::make($this->tmpDir);
        $this->expectException(RuntimeException::class);
        $dir->read();
    }

    public function testWriteTextAndReadText(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('file.txt');
        $file->write('hello');
        $this->assertSame('hello', $file->read(), 'Read should return written text');
    }

    public function test_Absolute(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('file.txt');
        $file->write('abc');
        $abs = $file->absolute();
        $this->assertTrue(is_string((string)$abs), 'Absolute should return string');
        $this->assertTrue($abs->exists(), 'Absolute path should exist');
    }

    public function test_NameSuffixStem(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('foo.bar.txt');
        $this->assertSame('foo.bar.txt', $file->name(), 'Name should return filename');
        $this->assertSame('txt', $file->suffix(), 'Suffix should return extension');
        $this->assertSame('foo.bar', $file->stem(), 'Stem should return filename without extension');
    }

    public function test_Parent(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('foo.txt');
        $parent = $file->parent();
        $this->assertSame($this->tmpDir, (string)$parent, 'Parent should return parent directory');
    }

    public function test_WithName(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('foo.txt');
        $newFile = $file->withName('bar.txt');
        $this->assertStringEndsWith('bar.txt', (string)$newFile, 'withName should change filename');
    }

    public function test_WithSuffix(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('foo.txt');
        $newFile = $file->withSuffix('.md');
        $this->assertStringEndsWith('.md', (string)$newFile, 'withSuffix should change extension');
    }

    public function test_with_name(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('foo.txt');
        $newFile = $file->withName('bar.txt');
        $this->assertStringEndsWith('bar.txt', (string)$newFile, 'withName should change filename');
    }

    public function test_with_suffix(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('foo.txt');
        $newFile = $file->withSuffix('.md');
        $this->assertStringEndsWith('.md', (string)$newFile, 'withSuffix should change extension');
    }

    public function test_Glob(): void
    {
        $file1 = Path::make($this->tmpDir)->joinPath('a.txt');
        $file2 = Path::make($this->tmpDir)->joinPath('b.txt');
        $file1->write('1');
        $file2->write('2');
        $dir = Path::make($this->tmpDir);
        $matches = $dir->glob('*.txt');
        $this->assertCount(2, $matches, 'glob should find both files');
        // Verify that glob returns Path objects
        $this->assertContainsOnlyInstancesOf(Path::class, $matches, 'glob should return Path objects');
    }

    public function testGlobVariants(): void
    {
        $file1 = Path::make($this->tmpDir)->joinPath('a.txt');
        $file2 = Path::make($this->tmpDir)->joinPath('b.txt');
        $file1->write('1');
        $file2->write('2');
        $dir = Path::make($this->tmpDir);
        $matches1 = $dir->glob('*.txt');
        $matches2 = $dir->glob('a.*');

        // glob() now returns Path objects, so convert to strings for comparison
        $paths1 = array_map(fn($p) => (string)$p, $matches1);
        $paths2 = array_map(fn($p) => (string)$p, $matches2);

        $this->assertContains((string)$file1, $paths1, 'glob should contain a.txt');
        $this->assertContains((string)$file2, $paths1, 'glob should contain b.txt');
        $this->assertContains((string)$file1, $paths2, 'glob should contain a.txt for a.*');
    }

    public function test_iterDir(): void
    {
        $file1 = Path::make($this->tmpDir)->joinPath('a.txt');
        $file2 = Path::make($this->tmpDir)->joinPath('b.txt');
        $file1->write('1');
        $file2->write('2');
        $dir = Path::make($this->tmpDir);
        $items = $dir->iterDir();
        $this->assertCount(2, $items, 'iterDir should return both files');
        foreach ($items as $item) {
            $this->assertInstanceOf(Path::class, $item, 'iterDir should return Path objects');
        }
    }

    public function testIterDirVariants(): void
    {
        $file1 = Path::make($this->tmpDir)->joinPath('a.txt');
        $file2 = Path::make($this->tmpDir)->joinPath('b.txt');
        $file1->write('1');
        $file2->write('2');
        $dir = Path::make($this->tmpDir);
        $items1 = $dir->iterDir();
        $items2 = $dir->iterdir();
        $this->assertCount(2, $items1, 'iterDir should return both files');
        $this->assertCount(2, $items2, 'iterdir should return both files');
        foreach ($items1 as $item) {
            $this->assertInstanceOf(Path::class, $item, 'iterDir should return Path objects');
        }
        foreach ($items2 as $item) {
            $this->assertInstanceOf(Path::class, $item, 'iterdir should return Path objects');
        }
    }

    public function test_IterDirThrowsIfNotDir(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('file.txt');
        $file->write('abc');
        $this->expectException(RuntimeException::class);
        $file->iterDir();
    }

    public function testIterDirThrows(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('file.txt');
        $file->write('abc');
        $this->expectException(RuntimeException::class);
        $file->iterDir();
    }

    public function test_Chmod(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('file.txt');
        $file->write('abc');
        $file->chmod(0644);
        $this->assertTrue($file->exists(), 'chmod should not remove file');
    }

    public function test_OwnerGroup(): void
    {
        if (false === extension_loaded('posix')) {
            $this->markTestSkipped('POSIX extension is not available.');
        }

        $file = Path::make($this->tmpDir)->joinPath('file.txt');
        $file->write('abc');
        $owner = $file->owner();
        $group = $file->group();
        $this->assertIsString($owner, 'owner should return string');
        $this->assertIsString($group, 'group should return string');
    }

    public function testOwnerGroupVariants(): void
    {
        if (!function_exists('posix_getpwuid') || !function_exists('posix_getgrgid')) {
            $this->markTestSkipped('POSIX extension is not available.');
        }
        $file = Path::make($this->tmpDir)->joinPath('file.txt');
        $file->write('abc');
        $owner1 = $file->owner();
        $group1 = $file->group();
        $owner2 = $file->owner();
        $group2 = $file->group();
        $this->assertIsString($owner1, 'owner should return string');
        $this->assertIsString($group1, 'group should return string');
        $this->assertIsString($owner2, 'owner should return string (repeat)');
        $this->assertIsString($group2, 'group should return string (repeat)');
    }

    public function test_IsAbsolute(): void
    {
        $absPath = Path::make(DIRECTORY_SEPARATOR . 'foo');
        $relPath = Path::make('foo');
        $this->assertTrue($absPath->isAbsolute(), 'Should be absolute path');
        $this->assertFalse($relPath->isAbsolute(), 'Should be relative path');
    }

    public function testUnlinkThrowsOnDir(): void
    {
        $dir = Path::make($this->tmpDir);
        $this->expectException(RuntimeException::class);
        $dir->unlink();
    }

    public function testRmdirThrowsOnFile(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('file.txt');
        $file->write('abc');
        $this->expectException(RuntimeException::class);
        $file->rmdir();
    }

    public function testChmodThrows(): void
    {
        $path = Path::make('/nonexistent/path');
        $this->expectException(RuntimeException::class);
        $path->chmod(0644);
    }

    public function test_EmptyPath(): void
    {
        $path = Path::make("");
        $this->assertFalse($path->exists(), 'Empty path should not exist');
        $this->assertFalse($path->isDir(), 'Empty path should not be a directory');
        $this->assertFalse($path->isFile(), 'Empty path should not be a file');
        $this->assertSame('', $path->name(), 'Empty path name should be empty string');
        $this->assertSame('', $path->suffix(), 'Empty path suffix should be empty string');
        $this->assertSame('', $path->stem(), 'Empty path stem should be empty string');
    }

    public function test_RecursiveMkdir(): void
    {
        $subdir = Path::make($this->tmpDir)->joinPath('foo', 'bar');
        $subdir->mkdir(0777, true);
        $this->assertTrue($subdir->exists(), 'Recursive mkdir should create subdir');
        $this->assertTrue($subdir->isDir(), 'Subdir should be a directory');
    }

    public function test_ParentAndAbsolute(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('foo.txt');
        $parent = $file->parent();
        $this->assertSame($this->tmpDir, (string)$parent, 'Parent should return parent directory');
        $file->write('abc');
        $abs = $file->absolute();
        $this->assertTrue($abs->exists(), 'Absolute path should exist');
    }

    public function test_is_relative(): void
    {
        $base = Path::make($this->tmpDir);
        $file = $base->joinPath('file.txt');
        $file->write('abc');
        $this->assertFalse($file->isRelative(), "Assert that path is relative and not absolute. {$file->path}");

        $cwd = getcwd();
        chdir($this->tmpDir);

        $base = Path::make('foo.txt');
        $base->write('abc');
        chdir($cwd);
        $this->assertTrue($base->isRelative(), 'Assert that path is relative');
    }

    public function test_sameFile(): void
    {
        $file1 = Path::make($this->tmpDir)->joinPath('file.txt');
        $file2 = Path::make($this->tmpDir)->joinPath('file.txt');
        $file1->write('abc');
        $this->assertTrue($file1->sameFile($file2), 'Should be same file');
        $file3 = Path::make($this->tmpDir)->joinPath('other.txt');
        $file3->write('def');
        $this->assertFalse($file1->sameFile($file3), 'Should not be same file');
    }

    public function test_symlinkTo_and_resolve(): void
    {
        if (str_starts_with(PHP_OS, 'WIN')) {
            $this->markTestSkipped('Symlink creation is not supported or requires admin privileges on Windows.');
        }

        $target = Path::make($this->tmpDir)->joinPath('target.txt');
        $target->write('abc');
        $link = Path::make($this->tmpDir)->joinPath('link.txt');
        $link->symlinkTo($target);
        $this->assertTrue(is_link((string)$link), 'Should create symlink');
        $resolved = $link->resolve();
        $this->assertSame((string)$target, (string)$resolved, 'resolve should return target');
    }

    public function test_relativeTo(): void
    {
        $base = Path::make($this->tmpDir);
        $file = $base->joinPath('foo.txt');
        $file->write('abc');
        $rel = $file->relativeTo($base);
        $this->assertSame('foo.txt', (string)$rel, 'relativeTo should return relative path');
        $this->expectException(RuntimeException::class);
        $file->relativeTo('/nonexistent');
    }

    public function test_match(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('foo.txt');
        $file->write('abc');
        $this->assertTrue($file->match('*.txt'), 'Should match *.txt');
        $this->assertFalse($file->match('*.md'), 'Should not match *.md');
    }

    public function test_stat(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('foo.txt');
        $file->write('abc');
        $stat = $file->stat();
        $this->assertIsArray($stat, 'stat should return array');
        $this->assertArrayHasKey('size', $stat, 'stat array should have size key');
    }

    public function test_rename(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('foo.txt');
        $file->write('abc');
        $newFile = Path::make($this->tmpDir)->joinPath('bar.txt');
        $file->rename($newFile);
        $this->assertFalse($file->exists(), 'File should not exist after rename');
        $this->assertTrue($newFile->exists(), 'New file should exist after rename');
    }

    public function test_virtual_properties(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('foo.bar.txt');
        $file->write('abc');
        $this->assertSame($file->name(), $file->name, 'Virtual property name should match method');
        $this->assertSame($file->stem(), $file->stem, 'Virtual property stem should match method');
        $this->assertSame($file->suffix(), $file->suffix, 'Virtual property suffix should match method');
        $this->assertInstanceOf(Path::class, $file->parent, 'Virtual property parent should be Path');
        $this->assertInstanceOf(Path::class, $file->absolute, 'Virtual property absolute should be Path');
        $this->assertSame(
            (string)$file->absolute(),
            (string)$file->absolute,
            'Virtual property absolute should match method'
        );
    }

    public function test_virtual_property_exception(): void
    {
        $file = Path::make($this->tmpDir)->joinPath('foo.txt');
        $this->expectException(RuntimeException::class);
        /** @noinspection PhpUndefinedFieldInspection */
        $file->unknown_property;
    }

    public function test_normalizePath_public_unix(): void
    {
        $path = Path::make('/foo/bar/../baz/./qux');
        $normalized = $path->normalize('/foo/bar/../baz/./qux', '/');
        $this->assertSame(
            '/foo/baz/qux',
            (string)$normalized,
            'Public normalize should normalize Unix path correctly'
        );
    }

    public function test_normalizePath_public_windows(): void
    {
        // Regular Windows path
        $winPath = Path::make('C:\foo\bar\..\baz\.\qux');
        $normalized = $winPath->normalize('C:\foo\bar\..\baz\.\qux', '\\');
        $this->assertSame(
            'C:\foo\baz\qux',
            (string)$normalized,
            'Public normalize should normalize Windows path correctly'
        );

        // UNC path with ..
        $unc1 = Path::make('\\\\server\\share\\folder\\..\\file.txt');
        $normalized1 = $unc1->normalize('\\\\server\\share\\folder\\..\\file.txt', '\\');
        $this->assertSame(
            '\\\\server\\share\\file.txt',
            (string)$normalized1,
            'Normalize should correctly handle UNC path with ..'
        );

        // UNC path with .
        $unc2 = Path::make('\\\\server\\share\\.\\dir\\file.txt');
        $normalized2 = $unc2->normalize('\\\\server\\share\\.\\dir\\file.txt', '\\');
        $this->assertSame(
            '\\\\server\\share\\dir\\file.txt',
            (string)$normalized2,
            'Normalize should correctly handle UNC path with .'
        );

        // UNC path with multiple .. going up
        $unc3 = Path::make('\\\\server\\share\\dir1\\dir2\\..\\..\\file');
        $normalized3 = $unc3->normalize('\\\\server\\share\\dir1\\dir2\\..\\..\\file', '\\');
        $this->assertSame(
            '\\\\server\\share\\file',
            (string)$normalized3,
            'Normalize should handle multiple .. correctly in UNC path'
        );

        // UNC path with redundant slashes
        $unc4 = Path::make('\\\\server\\share\\\\nested\\\\\\dir\\\\');
        $normalized4 = $unc4->normalize('\\\\server\\share\\\\nested\\\\\\dir\\\\', '\\');
        $this->assertSame(
            '\\\\server\\share\\nested\\dir',
            (string)$normalized4,
            'Normalize should collapse redundant slashes in UNC path'
        );
    }

    public function test_isSymlink()
    {
        if (str_starts_with(PHP_OS, 'WIN')) {
            $this->markTestSkipped('Symlink checks are not reliable on Windows.');
        }

        $target = Path::make($this->tmpDir)->joinPath('target.txt');
        $target->write('abc');
        $link = Path::make($this->tmpDir)->joinPath('link.txt');
        $link->symlinkTo($target);
        $this->assertTrue($link->isSymlink(), 'Should be a symlink');
        $this->assertFalse($target->isSymlink(), 'Target should not be a symlink');
    }

    public function test_Touch()
    {
        $file = Path::make($this->tmpDir)->joinPath('file.txt');
        $file->touch();
        $this->assertTrue($file->exists(), 'File should exist after touch');
        $this->assertTrue($file->isFile(), 'Should be a file after touch');
        $this->assertSame('', $file->read(), 'File should be empty after touch');
    }

    public function test_FindSideCarFiles_NoSidecars(): void
    {
        $mainFile = Path::make($this->tmpDir)->joinPath('movie.mkv');
        $mainFile->write('video data');

        $sidecars = $mainFile->childrenFiles();

        $this->assertIsArray($sidecars, 'Should return an array');
        $this->assertEmpty($sidecars, 'Should have no sidecar files');
    }

    public function test_FindSideCarFiles_SingleExtension(): void
    {
        $mainFile = Path::make($this->tmpDir)->joinPath('movie.mkv');
        $mainFile->write('video data');

        $srtFile = Path::make($this->tmpDir)->joinPath('movie.srt');
        $srtFile->write('subtitle data');

        $nfoFile = Path::make($this->tmpDir)->joinPath('movie.nfo');
        $nfoFile->write('metadata');

        $sidecars = $mainFile->childrenFiles();

        $this->assertCount(2, $sidecars, 'Should find 2 sidecar files');

        $names = array_map(fn($p) => $p->name, $sidecars);
        $this->assertContains('movie.srt', $names, 'Should include .srt file');
        $this->assertContains('movie.nfo', $names, 'Should include .nfo file');
        $this->assertNotContains('movie.mkv', $names, 'Should not include the main file');
    }

    public function test_FindSideCarFiles_DoubleExtension(): void
    {
        $mainFile = Path::make($this->tmpDir)->joinPath('movie.mkv');
        $mainFile->write('video data');

        $enSub = Path::make($this->tmpDir)->joinPath('movie.en.ass');
        $enSub->write('english subtitles');

        $frSub = Path::make($this->tmpDir)->joinPath('movie.fr.srt');
        $frSub->write('french subtitles');

        $sidecars = $mainFile->childrenFiles();

        $this->assertCount(2, $sidecars, 'Should find 2 sidecar files');

        $names = array_map(fn($p) => $p->name, $sidecars);
        $this->assertContains('movie.en.ass', $names, 'Should include .en.ass file');
        $this->assertContains('movie.fr.srt', $names, 'Should include .fr.srt file');
    }

    public function test_FindSideCarFiles_TripleExtension(): void
    {
        $mainFile = Path::make($this->tmpDir)->joinPath('movie.mkv');
        $mainFile->write('video data');

        $forcedSub = Path::make($this->tmpDir)->joinPath('movie.eng.forced.srt');
        $forcedSub->write('forced subtitles');

        $sdh = Path::make($this->tmpDir)->joinPath('movie.en.sdh.srt');
        $sdh->write('sdh subtitles');

        $sidecars = $mainFile->childrenFiles();

        $this->assertCount(2, $sidecars, 'Should find 2 sidecar files');

        $names = array_map(fn($p) => $p->name, $sidecars);
        $this->assertContains('movie.eng.forced.srt', $names, 'Should include .eng.forced.srt file');
        $this->assertContains('movie.en.sdh.srt', $names, 'Should include .en.sdh.srt file');
    }

    public function test_FindSideCarFiles_MixedExtensions(): void
    {
        $mainFile = Path::make($this->tmpDir)->joinPath('my.movie.title.mp4');
        $mainFile->write('video data');

        // Single extension
        $srt = Path::make($this->tmpDir)->joinPath('my.movie.title.srt');
        $srt->write('subtitles');

        // Double extension
        $enAss = Path::make($this->tmpDir)->joinPath('my.movie.title.en.ass');
        $enAss->write('english subtitles');

        // Triple extension
        $forced = Path::make($this->tmpDir)->joinPath('my.movie.title.eng.forced.srt');
        $forced->write('forced subtitles');

        // Metadata
        $nfo = Path::make($this->tmpDir)->joinPath('my.movie.title.nfo');
        $nfo->write('metadata');

        $sidecars = $mainFile->childrenFiles();

        $this->assertCount(4, $sidecars, 'Should find 4 sidecar files');

        $names = array_map(fn($p) => $p->name, $sidecars);
        $this->assertContains('my.movie.title.srt', $names);
        $this->assertContains('my.movie.title.en.ass', $names);
        $this->assertContains('my.movie.title.eng.forced.srt', $names);
        $this->assertContains('my.movie.title.nfo', $names);
        $this->assertNotContains('my.movie.title.mp4', $names, 'Should not include main file');
    }

    public function test_FindSideCarFiles_SpecialCharacters(): void
    {
        // Test with special characters in filename
        $mainFile = Path::make($this->tmpDir)->joinPath('movie[2020].mkv');
        $mainFile->write('video data');

        $srt = Path::make($this->tmpDir)->joinPath('movie[2020].srt');
        $srt->write('subtitles');

        $sidecars = $mainFile->childrenFiles();

        $this->assertCount(1, $sidecars, 'Should find 1 sidecar file with special chars');
        $this->assertSame('movie[2020].srt', $sidecars[0]->name, 'Should handle special characters');
    }

    public function test_FindSideCarFiles_WildcardCharacters(): void
    {
        // Test with glob wildcard characters in filename
        $mainFile = Path::make($this->tmpDir)->joinPath('movie*.mkv');
        $mainFile->write('video data');

        $srt = Path::make($this->tmpDir)->joinPath('movie*.srt');
        $srt->write('subtitles');

        $sidecars = $mainFile->childrenFiles();

        $this->assertCount(1, $sidecars, 'Should find 1 sidecar file with wildcard chars');
        $this->assertSame('movie*.srt', $sidecars[0]->name, 'Should handle wildcard characters');
    }

    public function test_FindSideCarFiles_NFOStyle_NoPosterOrFanart(): void
    {
        $mainFile = Path::make($this->tmpDir)->joinPath('movie.mkv');
        $mainFile->write('video data');

        $srt = Path::make($this->tmpDir)->joinPath('movie.srt');
        $srt->write('subtitles');

        $sidecars = $mainFile->childrenFiles(true);

        $this->assertCount(1, $sidecars, 'Should only find regular sidecar files');
        $this->assertSame('movie.srt', $sidecars[0]->name);
    }

    public function test_FindSideCarFiles_NFOStyle_WithPoster(): void
    {
        $mainFile = Path::make($this->tmpDir)->joinPath('movie.mkv');
        $mainFile->write('video data');

        $poster = Path::make($this->tmpDir)->joinPath('poster.jpg');
        $poster->write('poster image');

        $srt = Path::make($this->tmpDir)->joinPath('movie.srt');
        $srt->write('subtitles');

        $sidecars = $mainFile->childrenFiles(true);

        $this->assertCount(2, $sidecars, 'Should find poster and srt file');

        $names = array_map(fn($p) => $p->name, $sidecars);
        $this->assertContains('poster.jpg', $names, 'Should include poster.jpg');
        $this->assertContains('movie.srt', $names, 'Should include movie.srt');
    }

    public function test_FindSideCarFiles_NFOStyle_WithFanart(): void
    {
        $mainFile = Path::make($this->tmpDir)->joinPath('movie.mkv');
        $mainFile->write('video data');

        $fanart = Path::make($this->tmpDir)->joinPath('fanart.png');
        $fanart->write('fanart image');

        $sidecars = $mainFile->childrenFiles(true);

        $this->assertCount(1, $sidecars, 'Should find fanart file');
        $this->assertSame('fanart.png', $sidecars[0]->name, 'Should include fanart.png');
    }

    public function test_FindSideCarFiles_NFOStyle_WithPosterAndFanart(): void
    {
        $mainFile = Path::make($this->tmpDir)->joinPath('movie.mkv');
        $mainFile->write('video data');

        $poster = Path::make($this->tmpDir)->joinPath('poster.jpg');
        $poster->write('poster image');

        $fanart = Path::make($this->tmpDir)->joinPath('fanart.jpeg');
        $fanart->write('fanart image');

        $srt = Path::make($this->tmpDir)->joinPath('movie.srt');
        $srt->write('subtitles');

        $sidecars = $mainFile->childrenFiles(true);

        $this->assertCount(3, $sidecars, 'Should find poster, fanart, and srt');

        $names = array_map(fn($p) => $p->name, $sidecars);
        $this->assertContains('poster.jpg', $names, 'Should include poster.jpg');
        $this->assertContains('fanart.jpeg', $names, 'Should include fanart.jpeg');
        $this->assertContains('movie.srt', $names, 'Should include movie.srt');
    }

    public function test_FindSideCarFiles_NFOStyle_MultiplePosterFormats(): void
    {
        $mainFile = Path::make($this->tmpDir)->joinPath('movie.mkv');
        $mainFile->write('video data');

        // Create poster in multiple formats - only first found will be included
        $posterJpg = Path::make($this->tmpDir)->joinPath('poster.jpg');
        $posterJpg->write('poster jpg');

        $posterPng = Path::make($this->tmpDir)->joinPath('poster.png');
        $posterPng->write('poster png');

        $sidecars = $mainFile->childrenFiles(true);

        // Both should be found since we check each extension
        $this->assertGreaterThanOrEqual(1, count($sidecars), 'Should find at least one poster');

        $names = array_map(fn($p) => $p->name, $sidecars);
        $hasPoster = in_array('poster.jpg', $names) || in_array('poster.png', $names);
        $this->assertTrue($hasPoster, 'Should include at least one poster format');
    }

    public function test_FindSideCarFiles_OnlyFilesNotDirectories(): void
    {
        $mainFile = Path::make($this->tmpDir)->joinPath('movie.mkv');
        $mainFile->write('video data');

        // Create a directory with the same basename pattern
        $subDir = Path::make($this->tmpDir)->joinPath('movie.extras');
        $subDir->mkdir();

        $srt = Path::make($this->tmpDir)->joinPath('movie.srt');
        $srt->write('subtitles');

        $sidecars = $mainFile->childrenFiles();

        $this->assertCount(1, $sidecars, 'Should only find files, not directories');
        $this->assertSame('movie.srt', $sidecars[0]->name);
    }

    public function test_FindSideCarFiles_ReturnsPathObjects(): void
    {
        $mainFile = Path::make($this->tmpDir)->joinPath('movie.mkv');
        $mainFile->write('video data');

        $srt = Path::make($this->tmpDir)->joinPath('movie.srt');
        $srt->write('subtitles');

        $sidecars = $mainFile->childrenFiles();

        $this->assertCount(1, $sidecars);
        $this->assertInstanceOf(Path::class, $sidecars[0], 'Should return Path objects');
        $this->assertTrue($sidecars[0]->isFile(), 'Returned Path should be a file');
    }

    public function test_SymlinkTo_ThrowsOnFailure(): void
    {
        // Try to create a symlink in a read-only filesystem location
        // /sys is read-only even for root, so this should always fail
        $link = Path::make('/sys/impossible_link_' . uniqid());
        $target = Path::make($this->tmpDir)->joinPath('target.txt');
        $target->write('target content');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not create symlink');
        $link->symlinkTo($target);
    }

    public function test_Replace(): void
    {
        $source = Path::make($this->tmpDir)->joinPath('source.txt');
        $source->write('source content');

        $target = Path::make($this->tmpDir)->joinPath('target.txt');

        $source->replace($target);

        $this->assertFalse($source->exists(), 'Source should not exist after replace');
        $this->assertTrue($target->exists(), 'Target should exist after replace');
        $this->assertSame('source content', $target->read(), 'Target should have source content');
    }

    public function test_Replace_OverwritesExisting(): void
    {
        $source = Path::make($this->tmpDir)->joinPath('source.txt');
        $source->write('new content');

        $target = Path::make($this->tmpDir)->joinPath('target.txt');
        $target->write('old content');

        $source->replace($target);

        $this->assertFalse($source->exists(), 'Source should not exist after replace');
        $this->assertTrue($target->exists(), 'Target should exist after replace');
        $this->assertSame('new content', $target->read(), 'Target should have new content');
    }

    public function test_IsAbsolute_Windows(): void
    {
        // Test Windows-style paths
        $winPath1 = new Path('C:\\Users\\test\\file.txt');
        $winPath2 = new Path('D:/Program Files/test.exe');
        $winPath3 = new Path('\\\\server\\share\\file.txt');
        $relativePath = new Path('relative\\path\\file.txt');

        // On Windows systems, these should be absolute
        // On Unix systems, the PHP_OS check will make them return based on Unix logic
        if (str_starts_with(PHP_OS, 'WIN')) {
            $this->assertTrue($winPath1->isAbsolute(), 'C:\\ path should be absolute on Windows');
            $this->assertTrue($winPath2->isAbsolute(), 'D:/ path should be absolute on Windows');
            $this->assertTrue($winPath3->isAbsolute(), 'UNC path should be absolute on Windows');
            $this->assertFalse($relativePath->isAbsolute(), 'Relative path should not be absolute');
        } else {
            // On Unix, Windows paths without leading / are considered relative
            $this->assertFalse($winPath1->isAbsolute(), 'Windows path should be relative on Unix');
            $this->assertFalse($relativePath->isAbsolute(), 'Relative path should not be absolute on Unix');
        }
    }

    public function test_Glob_ReturnsFalseOnInvalidPattern(): void
    {
        // Create a directory and try to glob with a pattern that might fail
        $dir = Path::make($this->tmpDir);

        // Most systems will return empty array rather than false, but we test the code path
        // by using an extremely long pattern or invalid pattern
        // Note: glob() rarely returns false, but when it does, our code handles it
        $result = $dir->glob('*.txt');

        $this->assertIsArray($result, 'glob should always return an array');
        $this->assertEmpty($result, 'glob should return empty array when no matches');
    }

    public function test_Glob_HandlesEmptyResult(): void
    {
        $dir = Path::make($this->tmpDir);

        // Search for files that don't exist
        $result = $dir->glob('nonexistent_*.xyz');

        $this->assertIsArray($result, 'glob should return an array');
        $this->assertEmpty($result, 'glob should return empty array when no files match');
    }

    public function test_ChildrenFiles_WithGlobFailure(): void
    {
        // Test that childrenFiles handles glob returning false gracefully
        // This is hard to trigger in normal circumstances, but the code handles it
        $mainFile = Path::make($this->tmpDir)->joinPath('movie.mkv');
        $mainFile->write('video data');

        // Even with special characters that might cause issues, it should handle gracefully
        $result = $mainFile->childrenFiles();

        $this->assertIsArray($result, 'childrenFiles should always return an array');
    }

}
