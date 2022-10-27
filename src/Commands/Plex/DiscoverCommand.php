<?php

declare(strict_types=1);

namespace App\Commands\Plex;

use App\Command;
use App\Libs\Routable;
use Psr\Log\LoggerInterface as iLogger;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

#[Routable(command: self::ROUTE), Routable(command: 'servers:edit')]
final class DiscoverCommand extends Command
{
    public const ROUTE = 'plex:discover';

    public function __construct(private iHttp $http, protected iLogger $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::ROUTE)
            ->setDescription('Discover servers linked to plex token.')
            ->addArgument('token', InputArgument::REQUIRED, 'Plex token')
            ->setHelp(
                r(
                    <<<HELP

                    This command allow you to <notice>discover</notice> servers associated with plex <notice>token</notice>.

                    HELP,
                    []
                )
            );
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ServerExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ClientExceptionInterface
     */
    protected function runCommand(InputInterface $input, OutputInterface $output, null|array $rerun = null): int
    {
        try {
            $response = $this->http->request('GET', 'https://plex.tv/api/resources?includeHttps=1&includeRelay=1', [
                'headers' => [
                    'X-Plex-Token' => $input->getArgument('token'),
                ],
            ]);
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException(
                r(
                    'Exception [{exception}] was thrown during call for servers list, likely network related? [{error}]',
                    [
                        'exception' => $e::class,
                        'error' => $e->getMessage(),
                    ]
                )
            );
        }

        $payload = $response->getContent(false);

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(
                r('Request for servers list returned with unexpected [{status_code}] status code. {context}', [
                    'status_code' => $response->getStatusCode(),
                    'context' => arrayToString(['payload' => $payload]),
                ])
            );
        }

        $xml = simplexml_load_string($payload);

        $list = [];

        foreach ($xml->Device as $device) {
            if (null === ($attr = $device->attributes())) {
                continue;
            }

            $attr = ag((array)$attr, '@attributes');

            if ('server' !== ag($attr, 'provides')) {
                continue;
            }

            foreach ($device->Connection as $uri) {
                if (null === ($cAttr = $uri->attributes())) {
                    continue;
                }

                $cAttr = ag((array)$cAttr, '@attributes');

                $list[] = [
                    'name' => ag($attr, 'name'),
                    'identifier' => ag($attr, 'clientIdentifier'),
                    'proto' => ag($cAttr, 'protocol'),
                    'address' => ag($cAttr, 'address'),
                    'port' => (int)ag($cAttr, 'port'),
                    'uri' => ag($cAttr, 'uri'),
                ];
            }
        }

        $this->displayContent($list, $output, $input->getOption('output'));

        return self::SUCCESS;
    }
}
