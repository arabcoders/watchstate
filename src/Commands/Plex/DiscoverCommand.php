<?php

declare(strict_types=1);

namespace App\Commands\Plex;

use App\Command;
use App\Libs\Routable;
use Psr\Log\LoggerInterface as iLogger;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as iHttp;

#[Routable(command: self::ROUTE)]
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
            ->addOption('with-tokens', 't', InputOption::VALUE_NONE, 'Include access tokens in response.')
            ->addArgument('token', InputArgument::REQUIRED, 'Plex token')
            ->addOption('include-raw-response', null, InputOption::VALUE_NONE, 'Include unfiltered raw response.')
            ->setHelp(
                r(
                    <<<HELP

                    This command allow you to <notice>discover</notice> servers associated with plex <notice>token</notice>.

                    -------
                    <notice>[ FAQ ]</notice>
                    -------

                    <question># How to get access tokens?</question>

                    {cmd} <cmd>{route}</cmd> <flag>--with-tokens</flag> -- <value>backend_name</value>

                    <question># How to see the raw response?</question>

                    {cmd} <cmd>{route}</cmd> <flag>--output</flag> <value>yaml</value> <flag>--include-raw-response</flag> -- <value>backend_name</value>

                    HELP,
                    [
                        'cmd' => trim(commandContext()),
                        'route' => self::ROUTE,
                    ]
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
                    'Unexpected exception [{exception}] was thrown during request for servers list, likely network related error. [{error}]',
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

        if (false === $xml->Device) {
            throw new RuntimeException(
                r('No devices found associated with the given token.', [
                    'status_code' => $response->getStatusCode(),
                    'context' => arrayToString(['payload' => $xml]),
                ])
            );
        }

        foreach ($xml->Device as $device) {
            if (null === ($attr = $device->attributes())) {
                continue;
            }

            $attr = ag((array)$attr, '@attributes');

            if ('server' !== ag($attr, 'provides')) {
                continue;
            }

            if (!property_exists($device, 'Connection') || false === $device->Connection) {
                $this->logger->notice('Server [%(name)] has no reported connections.');
                continue;
            }

            foreach ($device->Connection as $uri) {
                if (null === ($cAttr = $uri->attributes())) {
                    continue;
                }

                $cAttr = ag((array)$cAttr, '@attributes');

                $arr = [
                    'name' => ag($attr, 'name'),
                    'identifier' => ag($attr, 'clientIdentifier'),
                    'proto' => ag($cAttr, 'protocol'),
                    'address' => ag($cAttr, 'address'),
                    'port' => (int)ag($cAttr, 'port'),
                    'uri' => ag($cAttr, 'uri'),
                    'online' => 1 === (int)ag($attr, 'presence') ? 'Yes' : 'No',
                ];

                if ($input->getOption('with-tokens')) {
                    $arr['AccessToken'] = ag($attr, 'accessToken');
                }

                $list[] = $arr;
            }
        }

        if ('table' !== $input->getOption('output') && $input->getOption('include-raw-response')) {
            $list['raw'] = json_decode(json_encode((array)$xml), true);
        }

        $this->displayContent($list, $output, $input->getOption('output'));

        return self::SUCCESS;
    }
}
