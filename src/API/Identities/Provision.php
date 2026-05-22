<?php

declare(strict_types=1);

namespace App\API\Identities;

use App\Libs\Attributes\Route\Get;
use App\Libs\Attributes\Route\Post;
use App\Libs\Attributes\Route\Put;
use App\Libs\Config;
use App\Libs\DataUtil;
use App\Libs\Enums\Http\Status;
use App\Libs\Exceptions\RuntimeException;
use App\Libs\Identities\IdentityProvisionRequest;
use App\Libs\Identities\IdentityProvisionService;
use DateInterval;
use Psr\Http\Message\ResponseInterface as iResponse;
use Psr\Http\Message\ServerRequestInterface as iRequest;
use Psr\SimpleCache\CacheInterface as iCache;
use Psr\SimpleCache\InvalidArgumentException;
use Throwable;

final class Provision
{
    private const string CACHE_KEY = 'identities-provision-preview';

    public function __construct(
        private readonly IdentityProvisionService $service,
        private readonly iCache $cache,
    ) {}

    /**
     * Preview identity matching across configured backends.
     *
     * @throws InvalidArgumentException
     */
    #[Get(Index::URL . '/provision[/]', name: 'identities.provision.preview')]
    public function preview(iRequest $request): iResponse
    {
        $ignoreCache = (bool) ag($request->getQueryParams(), 'force', false);
        $cached = new DateInterval('PT5M');
        $mapping = $this->service->loadMappings();

        $data = cacheable_item(
            key: self::CACHE_KEY,
            function: function () use ($cached, $mapping): array {
                $preview = $this->service->preview($mapping);

                return [
                    ...$this->formatPreview($preview),
                    'expires' => (string) make_date()->add($cached),
                ];
            },
            ttl: $cached,
            ignoreCache: $ignoreCache,
            opts: [iCache::class => $this->cache],
        );

        return api_response(Status::OK, [
            'has_identities' => $this->service->hasIdentities(),
            'has_mapping' => count($mapping) > 0,
            ...$data,
        ]);
    }

    /**
     * Persist identity mapping rules.
     *
     * @throws InvalidArgumentException
     */
    #[Put(Index::URL . '/provision/mapping[/]', name: 'identities.provision.mapping.update')]
    public function updateMapping(iRequest $request): iResponse
    {
        $body = DataUtil::fromRequest($request);
        $version = (string) $body->get('version', '1.6');
        $identities = $body->get('identities', []);

        if (!is_array($identities)) {
            return api_error('Invalid identities data.', Status::BAD_REQUEST);
        }

        if (true === version_compare($version, '1.5', '<')) {
            return api_error('Invalid version. must be 1.5 or greater.', Status::BAD_REQUEST);
        }

        $mapping = $this->mappingPayloadToRows($identities, true);

        if (count($mapping) < 1) {
            return api_error('Empty identities mapping data.', Status::BAD_REQUEST);
        }

        $exists = file_exists(Config::get('mapper_file'));
        $this->service->saveMappings($mapping, $version);
        $this->clearPreviewCache();

        return api_message(
            r('Identity mapping successfully {state}.', [
                'state' => $exists ? 'updated' : 'created',
            ]),
            $exists ? Status::OK : Status::CREATED,
            body: [
                'version' => $version,
                'identities' => $identities,
            ],
        );
    }

    /**
     * Create, update, or recreate identities directly through the API.
     *
     * @throws InvalidArgumentException
     */
    #[Post(Index::URL . '/provision[/]', name: 'identities.provision.run')]
    public function provision(iRequest $request): iResponse
    {
        $body = DataUtil::fromRequest($request);
        $mode = strtolower((string) $body->get('mode', 'create'));
        $allowedModes = ['create', 'update', 'recreate'];

        if (false === in_array($mode, $allowedModes, true)) {
            return api_error('Invalid mode. Expected create, update, or recreate.', Status::BAD_REQUEST);
        }

        $allowSingleBackendIdentities = (bool) $body->get('allow_single_backend_identities', false);
        $mappingIdentities = $body->get('mapping.identities', []);

        if (!is_array($mappingIdentities)) {
            return api_error('Invalid mapping identities data.', Status::BAD_REQUEST);
        }

        $version = (string) $body->get('mapping.version', '1.6');

        if (true === version_compare($version, '1.5', '<')) {
            return api_error('Invalid mapping version. must be 1.5 or greater.', Status::BAD_REQUEST);
        }

        if ('create' === $mode && true === $this->service->hasIdentities()) {
            return api_error(
                'Identity configuration already exists. Use update or recreate mode instead.',
                Status::CONFLICT,
            );
        }

        $provisionRequest = new IdentityProvisionRequest(
            mode: $mode,
            dryRun: (bool) $body->get('dry_run', false),
            regenerateTokens: (bool) $body->get('regenerate_tokens', false),
            generateBackup: (bool) $body->get('generate_backup', false),
            allowSingleBackendIdentities: $allowSingleBackendIdentities,
            persistMapping: (bool) $body->get('save_mapping', true),
            mappingVersion: $version,
            mapping: $this->mappingPayloadToRows($mappingIdentities, $allowSingleBackendIdentities),
        );

        try {
            $result = $this->service->provision($provisionRequest);
            $this->clearPreviewCache();
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }

        $formatted = $this->formatMatchedIdentities(ag($result, 'identities', []));
        $warnings = ag($result, 'warnings', []);
        $status = true === $provisionRequest->dryRun
            ? Status::OK
            : match ($mode) {
                'update' => Status::OK,
                default => Status::CREATED,
            };
        $message = match ($mode) {
            'update' => $provisionRequest->dryRun
                ? 'Identity update dry run completed.'
                : 'Identities updated successfully.',
            'recreate' => $provisionRequest->dryRun
                ? 'Identity recreate dry run completed.'
                : 'Identities recreated successfully.',
            default => $provisionRequest->dryRun
                ? 'Identity creation dry run completed.'
                : 'Identities created successfully.',
        };

        return api_message($message, $status, body: [
            'mode' => $mode,
            'dry_run' => $provisionRequest->dryRun,
            'save_mapping' => $provisionRequest->persistMapping,
            'allow_single_backend_identities' => $provisionRequest->allowSingleBackendIdentities,
            'count' => count($formatted),
            'identities' => $formatted,
            'warning_count' => count($warnings),
            'warnings' => $warnings,
        ]);
    }

    /**
     * Safely sync existing identity backends from the main backend configuration.
     *
     * @throws InvalidArgumentException
     */
    #[Post(Index::URL . '/provision/sync-backends[/]', name: 'identities.provision.syncBackends')]
    public function syncBackends(iRequest $request): iResponse
    {
        $body = DataUtil::fromRequest($request);
        $dryRun = (bool) $body->get('dry_run', false);

        try {
            $result = $this->service->syncBackends($dryRun);
        } catch (RuntimeException $e) {
            return api_error($e->getMessage(), Status::BAD_REQUEST);
        } catch (Throwable $e) {
            return api_error($e->getMessage(), Status::INTERNAL_SERVER_ERROR);
        }

        $updated = count(ag($result, 'updated', []));
        $skipped = count(ag($result, 'skipped', []));
        $failed = count(ag($result, 'failed', []));

        if (true === $dryRun) {
            $message = 0 === $updated
                ? 'Sync backends dry run found no changes.'
                : r('Sync backends dry run found {count} backend change(s).', ['count' => $updated]);
        } else {
            $message = 0 === $updated
                ? 'All linked identity backends are already in sync.'
                : r('Synced {count} identity backend(s) successfully.', ['count' => $updated]);
        }

        return api_message($message, Status::OK, body: [
            'dry_run' => $dryRun,
            'updated_count' => $updated,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'updated' => ag($result, 'updated', []),
            'skipped' => ag($result, 'skipped', []),
            'failed' => ag($result, 'failed', []),
        ]);
    }

    /**
     * @param array{matched: array<int, array<string, mixed>>, unmatched: array<int, array<string, mixed>>, backends: array<int, string>} $preview
     *
     * @return array{matched: array<int, array<string, mixed>>, unmatched: array<int, array<string, mixed>>, backends: array<int, string>}
     */
    private function formatPreview(array $preview): array
    {
        $matched = [];

        foreach (ag($preview, 'matched', []) as $index => $identity) {
            $members = [];

            foreach (ag($identity, 'backends', []) as $backend => $backendData) {
                $members[] = $this->formatMember($backendData, $backend);
            }

            $matched[] = [
                'identity' => ag($identity, 'name', 'identity_group_' . ($index + 1)),
                'members' => $members,
            ];
        }

        $unmatched = [];

        foreach (ag($preview, 'unmatched', []) as $user) {
            $unmatched[] = [
                'id' => ag($user, 'id', null),
                'username' => ag($user, 'name', null),
                'backend' => ag($user, 'backend', null),
                'real_name' => ag($user, 'real_name', null),
                'type' => ag($user, 'client_data', null),
                'protected' => (bool) ag($user, 'protected', false),
                'options' => ag($user, 'options', (object) []),
            ];
        }

        return [
            'matched' => $matched,
            'unmatched' => $unmatched,
            'backends' => ag($preview, 'backends', []),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $matched
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatMatchedIdentities(array $matched): array
    {
        $formatted = [];

        foreach ($matched as $index => $identity) {
            $members = [];

            foreach (ag($identity, 'backends', []) as $backend => $backendData) {
                $members[] = $this->formatMember($backendData, $backend);
            }

            $formatted[] = [
                'identity' => ag($identity, 'name', 'identity_group_' . ($index + 1)),
                'backends' => array_values(array_map(static fn(array $member): string => (string) $member['backend'], $members)),
                'members' => $members,
            ];
        }

        return $formatted;
    }

    /**
     * @param array<string, mixed> $backendData
     *
     * @return array<string, mixed>
     */
    private function formatMember(array $backendData, string $backend): array
    {
        return [
            'id' => ag($backendData, 'id', null),
            'username' => ag($backendData, 'name', null),
            'backend' => $backend,
            'real_name' => ag($backendData, 'real_name', null),
            'type' => ag($backendData, 'client_data.type', null),
            'protected' => (bool) ag($backendData, 'protected', false),
            'options' => ag($backendData, 'options', (object) []),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $identities
     *
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function mappingPayloadToRows(array $identities, bool $allowSingleBackendIdentities): array
    {
        $rows = [];

        foreach ($identities as $identity) {
            $row = [];

            foreach (ag($identity, 'members', []) as $member) {
                $backend = ag($member, 'backend');
                $name = ag($member, 'username');

                if (null === $backend || null === $name) {
                    continue;
                }

                $row[(string) $backend] = [
                    'name' => (string) $name,
                    'options' => is_array(ag($member, 'options')) ? ag($member, 'options') : [],
                ];
            }

            if (count($row) < 1) {
                continue;
            }

            if (false === $allowSingleBackendIdentities && count($row) < 2) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function clearPreviewCache(): void
    {
        if ($this->cache->has(self::CACHE_KEY)) {
            $this->cache->delete(self::CACHE_KEY);
        }
    }
}
