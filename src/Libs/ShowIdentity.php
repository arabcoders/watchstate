<?php

declare(strict_types=1);

namespace App\Libs;

use App\Libs\Entity\StateEntity;
use App\Libs\Entity\StateInterface as iState;

final class ShowIdentity
{
    /** @return array<string> */
    public static function tokens(StateEntity $entity): array
    {
        $tokens = [];
        $parentSets = [$entity->getParentGuids()];
        foreach ($entity->getMetadata() as $backend => $metadata) {
            if (false === is_array($metadata)) {
                continue;
            }
            $parentSets[] = (array) ag($metadata, iState::COLUMN_PARENT, []);
            $id = ag($metadata, iState::COLUMN_META_SHOW, '');
            if (false === is_scalar($id)) {
                continue;
            }
            $id = trim((string) $id);
            if ('' !== $id && false === in_array(mb_strtolower($id), ['??', '0', '-1', 'unknown'], true)) {
                $tokens[] = json_encode(['backend', (string) $backend, $id], JSON_THROW_ON_ERROR);
            }
        }
        foreach ($parentSets as $parents) {
            foreach (array_intersect_key($parents, Guid::getSupported()) as $source => $id) {
                if (false === is_scalar($id)) {
                    continue;
                }
                $id = trim((string) $id);
                if ('' !== $id && false === in_array(mb_strtolower($id), ['??', '0', '-1', 'unknown'], true)) {
                    $tokens[] = json_encode(['guid', $source, $id], JSON_THROW_ON_ERROR);
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /** @param array<string,string> $parents */
    public static function root(array &$parents, string $alias): string
    {
        if ($parents[$alias] !== $alias) {
            $parents[$alias] = self::root($parents, $parents[$alias]);
        }

        return $parents[$alias];
    }
}
