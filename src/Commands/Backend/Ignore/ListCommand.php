<?php

declare(strict_types=1);

namespace App\Commands\Backend\Ignore;

use App\Command;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\Storage\StorageInterface;
use PDO;
use Psr\Http\Message\UriInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ListCommand extends Command
{
    private const CACHE_KEY = 'ignorelist_titles';

    private array $cache = [];

    private PDO $db;
    private CacheInterface $cacheIO;

    public function __construct(StorageInterface $storage, CacheInterface $cacheIO)
    {
        $this->cacheIO = $cacheIO;
        $this->db = $storage->getPdo();

        try {
            if ($this->cacheIO->has(self::CACHE_KEY)) {
                $this->cache = $this->cacheIO->get(self::CACHE_KEY);
            }
        } catch (InvalidArgumentException) {
            $this->cache = [];
        }

        parent::__construct();
    }

    protected function configure(): void
    {
        $cmdContext = trim(commandContext());

        $this->setName('backend:ignore:list')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter based on type.')
            ->addOption('backend', null, InputOption::VALUE_REQUIRED, 'Filter based on backend.')
            ->addOption('db', null, InputOption::VALUE_REQUIRED, 'Filter based on db.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Filter based on id.')
            ->addOption('with-title', null, InputOption::VALUE_NONE, 'Include entity title in response. Slow operation')
            ->setDescription('List Ignored external ids.')
            ->setHelp(
                <<<HELP

This command display list of ignored external ids.

You can filter the results by using one or more of the provided options like <info>--type</info>, <info>--backend</info>, <info>--db</info>

For example, To list all ids that are being ignored for specific <info>backend</info>, You can do something like

{$cmdContext} backend:ignore:list --backend plex_home

You can append more filters to narrow down the list. For example, to filter on both <info>backend</info> and <info>db</info>:

{$cmdContext} backend:ignore:list --backend plex_home --db tvdb

HELP

            );
    }

    protected function runCommand(InputInterface $input, OutputInterface $output): int
    {
        $path = Config::get('path') . '/config/ignore.yaml';

        if (false === file_exists($path)) {
            touch($path);
        }

        $list = [];

        $fBackend = $input->getOption('backend');
        $fType = $input->getOption('type');
        $fDb = $input->getOption('db');
        $fId = $input->getOption('id');

        $ids = Config::get('ignore', []);

        foreach ($ids as $guid => $date) {
            $urlParts = parse_url($guid);

            $backend = ag($urlParts, 'host');
            $type = ag($urlParts, 'scheme');
            $db = ag($urlParts, 'user');
            $id = ag($urlParts, 'pass');
            $scope = ag($urlParts, 'query');

            if (null !== $fBackend && $backend !== $fBackend) {
                continue;
            }

            if (null !== $fType && $type !== $fType) {
                continue;
            }

            if (null !== $fDb && $db !== $fDb) {
                continue;
            }

            if (null !== $fId && $id !== $fId) {
                continue;
            }

            $rule = makeIgnoreId($guid);

            $builder = [
                'type' => ucfirst($type),
                'backend' => $backend,
                'db' => $db,
                'id' => $id,
                'Scoped' => null === $scope ? 'No' : 'Yes',
            ];

            if (!empty($this->cache) || $input->getOption('with-title')) {
                $builder['title'] = null !== $scope ? ($this->getinfo($rule) ?? 'Unknown') : '** Global Rule **';
            }

            if ('table' !== $input->getOption('output')) {
                $builder = ['rule' => (string)$rule] + $builder;
                $builder['scope'] = [];
                if (null !== $scope) {
                    parse_str($scope, $builder['scope']);
                }
                $builder['created'] = makeDate($date);
            } else {
                $builder['created'] = makeDate($date)->format('Y-m-d H:i:s T');
            }
            $list[] = $builder;
        }
        
        if (empty($list)) {
            $hasIds = count($ids) >= 1;

            $output->writeln(
                $hasIds ? '<comment>Filters did not return any results.</comment>' : '<info>Ignore list is empty.</info>'
            );

            if (true === $hasIds) {
                return self::FAILURE;
            }
        }

        $this->displayContent($list, $output, $input->getOption('output'));

        return self::SUCCESS;
    }

    private function getInfo(UriInterface $uri): string|null
    {
        if (empty($uri->getQuery())) {
            return null;
        }

        $params = [];
        parse_str($uri->getQuery(), $params);

        $key = sprintf('%s://%s@%s', $uri->getScheme(), $uri->getHost(), $params['id']);

        if (true === array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $sql = sprintf(
            "SELECT * FROM state WHERE JSON_EXTRACT(metadata, '$.%s.%s') = :id LIMIT 1",
            $uri->getHost(),
            $uri->getScheme() === iState::TYPE_SHOW ? 'show' : 'id'
        );

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $params['id']]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (empty($item)) {
            $this->cache[$key] = null;
            return null;
        }

        $this->cache[$key] = Container::get(iState::class)->fromArray($item)->getName(
            iState::TYPE_SHOW === $uri->getScheme()
        );

        return $this->cache[$key];
    }

    public function __destruct()
    {
        if (empty($this->cache)) {
            return;
        }

        try {
            $this->cacheIO->set(self::CACHE_KEY, $this->cache, new \DateInterval('P3D'));
        } catch (InvalidArgumentException) {
        }
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestOptionValuesFor('backend')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (array_keys(Config::get('servers', [])) as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
        if ($input->mustSuggestOptionValuesFor('type')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (iState::TYPES_LIST as $name) {
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
        if ($input->mustSuggestOptionValuesFor('db')) {
            $currentValue = $input->getCompletionValue();

            $suggest = [];

            foreach (array_keys(Guid::getSupported(includeVirtual: false)) as $name) {
                $name = after($name, 'guid_');
                if (empty($currentValue) || str_starts_with($name, $currentValue)) {
                    $suggest[] = $name;
                }
            }

            $suggestions->suggestValues($suggest);
        }
    }
}
