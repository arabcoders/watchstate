<?php

declare(strict_types=1);

namespace App\Commands\Backend\Ignore;

use App\Command;
use App\Libs\Config;
use App\Libs\Container;
use App\Libs\Database\DatabaseInterface as iDB;
use App\Libs\Entity\StateInterface as iState;
use App\Libs\Guid;
use App\Libs\Routable;
use PDO;
use Psr\Http\Message\UriInterface;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[Routable(command: self::ROUTE)]
final class ListCommand extends Command
{
    public const ROUTE = 'backend:ignore:list';

    private array $cache = [];

    private PDO $db;

    public function __construct(iDB $db)
    {
        $this->db = $db->getPdo();

        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter based on type.')
            ->addOption('backend', null, InputOption::VALUE_REQUIRED, 'Filter based on backend.')
            ->addOption('db', null, InputOption::VALUE_REQUIRED, 'Filter based on db.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Filter based on id.')
            ->setDescription('List Ignored external ids.')
            ->setHelp(
                r(
                    <<<HELP

                    This command display list of ignored external ids. You can filter the list by
                    using one or more of the provided options.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># List all ignore rules that relate to specific backend.</question>

                    {cmd} <cmd>{route}</cmd> <flag>--backend</flag> <value>backend_name</value>

                    <question># Appending more filters to narrow down list</question>

                    {cmd} <cmd>{route}</cmd> <flag>--backend</flag> <value>backend_name</value> <flag>--db</flag> <value>tvdb</value>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                    ]
                )
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
                'title' => null !== $scope ? ($this->getinfo($rule) ?? 'Unknown') : '** Global Rule **',
                'Scoped' => null === $scope ? 'No' : 'Yes',
            ];

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
