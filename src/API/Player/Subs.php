<?php

declare(strict_types=1);

namespace App\API\Player;

use SplFileInfo;

final class Subs
{
    public static function list(string $path): array
    {
        if (false === file_exists($path) || false === is_file($path)) {
            return [];
        }

        $list = [];

        foreach (find_side_car_files(new SplFileInfo($path)) as $file) {
            $ext = strtolower((string) get_extension($file));

            if (false === isset(Subtitle::FORMATS[$ext])) {
                continue;
            }

            preg_match('#\.(\w{2,3})\.\w{3}$#', $file, $lang);

            $list[] = [
                'path' => $file,
                'title' => 'External',
                'language' => strtolower($lang[1] ?? 'und'),
                'forced' => false,
                'codec' => [
                    'short' => $ext,
                    'long' => 'text/' . $ext,
                ],
            ];
        }

        return array_values($list);
    }

    public static function has(string $path, ?string $sub): bool
    {
        if (null === $sub || '' === $sub) {
            return false;
        }

        foreach (self::list($path) as $item) {
            if ($sub === ag($item, 'path')) {
                return true;
            }
        }

        return false;
    }
}
