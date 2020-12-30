<?php

declare(strict_types=1);

namespace Hoho5000\Monolog\JiraHandler;

use Http\Client\Common\Plugin\AuthenticationPlugin;
use Http\Client\Common\Plugin\ContentLengthPlugin;
use Http\Client\Common\Plugin\HeaderDefaultsPlugin;
use Http\Client\Common\PluginClient;
use Http\Client\HttpClient;
use Http\Discovery\HttpClientDiscovery;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Message\Authentication\BasicAuth;
use Monolog\Logger;
use function array_walk_recursive;
use function in_array;
use function serialize;

class JiraHandler extends BatchHandler
{
    private $hostname;
    private $jql;
    private $hashFieldName;
    private $projectKey;
    private $issueTypeName;
    private $withComments;
    private $counterFieldName;

    private $requestFactory;
    private $urlFactory;
    private $streamFactory;
    private $httpClient;

    private $createdIssueId;

    private $excludeHashData;

    public function __construct(
        string $hostname,
        string $username,
        string $password,
        string $jql,
        string $hashFieldName,
        string $projectKey,
        string $issueTypeName,
        bool $withComments = false,
        string $counterFieldName = null,
        array $excludeHashDataKeys = [],
        HttpClient $httpClient = null,
        $level = Logger::DEBUG,
        $bubble = true
    )
    {
        parent::__construct($level, $bubble);

        $this->hostname = $hostname;
        $this->jql = $jql;
        $this->hashFieldName = $hashFieldName;
        $this->projectKey = $projectKey;
        $this->issueTypeName = $issueTypeName;
        $this->withComments = $withComments;
        $this->counterFieldName = $counterFieldName;

        $authentication = new BasicAuth($username, $password);
        $authenticationPlugin = new AuthenticationPlugin($authentication);

        $contentLengthPlugin = new ContentLengthPlugin();
        $headerDefaultsPlugin = new HeaderDefaultsPlugin([
            'Content-Type' => 'application/json',
        ]);

        $this->requestFactory = Psr17FactoryDiscovery::findRequestFactory();
        $this->urlFactory = Psr17FactoryDiscovery::findUrlFactory();
        $this->streamFactory = Psr17FactoryDiscovery::findStreamFactory();

        $this->httpClient = new PluginClient(
            $httpClient ?: HttpClientDiscovery::find(),
            [$authenticationPlugin, $headerDefaultsPlugin, $contentLengthPlugin]
        );

        $this->excludeHashData = $excludeHashDataKeys;
    }


    protected function send($content, array $records): void
    {
        $countFieldId = null;
        $highestRecord = $this->getHighestRecord($records);

        $hash = $this->generateHash($highestRecord);

        $uri = $this->urlFactory->createUri(sprintf('https://%s/rest/api/2/field', $this->hostname));
        $request = $this->requestFactory->createRequest('GET', $uri);
        $response = $this->httpClient->sendRequest($request);
        $data = json_decode($response->getBody()->getContents(), true);
        $hashFieldId = $this->parseCustomFieldId($data, 'values', $this->hashFieldName);

        if ($this->counterFieldName) {
            $countFieldId = $this->parseCustomFieldId($data, 'values', $this->counterFieldName);
        }

        $jql = sprintf('%s AND %s ~ \'%s\'', $this->jql, $this->hashFieldName, $hash);

        $fields = [
            'issuetype',
            'status',
            'summary',
            $hashFieldId,
            $this->counterFieldName,
        ];

        if ($countFieldId) {
            $fields[] = $countFieldId;
        }

        $body = json_encode([
            'jql' => $jql,
            'fields' => $fields,
        ]);

        $uri = $this->urlFactory->createUri(sprintf('https://%s/rest/api/2/search', $this->hostname));
        $request = $this->requestFactory->createRequest('POST', $uri)->withBody($this->streamFactory->createStream($body));
        $response = $this->httpClient->sendRequest($request);
        $data = json_decode($response->getBody()->getContents(), true);

        if ($data['total'] > 0) {
            $issueId = $data['issues'][0]['id'];

            if ($this->counterFieldName) {
                $countFieldValue = $data['issues'][0]['fields'][$countFieldId];

                $uri = $this->urlFactory->createUri(sprintf('https://%s/rest/api/2/issue/%d', $this->hostname, $issueId).'?'.http_build_query([
                    'notifyUsers' => false,
                ]));
                $rawBody = [
                    'fields' => [
                        $countFieldId => ++$countFieldValue,
                    ],
                ];
                $body = json_encode($rawBody);
                $request = $this->requestFactory->createRequest('PUT', $uri)->withBody($this->streamFactory->createStream($body));
                $this->httpClient->sendRequest($request);
            }

            if ($this->withComments) {
                $uri = $this->urlFactory->createUri(sprintf('https://%s/rest/api/2/issue/%d/comment', $this->hostname, $issueId));
                $body = json_encode([
                    'body' => $content,
                ]);
                $request = $this->requestFactory->createRequest('POST', $uri)->withBody($this->streamFactory->createStream($body));
                $this->httpClient->sendRequest($request);
            }

            return;
        }

        $uri = $this->urlFactory->createUri(sprintf('https://%s/rest/api/2/issue/createmeta', $this->hostname).'?'.http_build_query([
            'projectKeys' => $this->projectKey,
            'expand' => 'projects.issuetypes.fields',
        ]));
        $request = $this->requestFactory->createRequest('GET', $uri);
        $response = $this->httpClient->sendRequest($request);
        $data = json_decode($response->getBody()->getContents(), true);

        $projectId = $this->parseProjectId($data);
        $issueType = $this->parseIssueType($data, $this->issueTypeName);
        $issueTypeId = (int) $issueType['id'];
        $summary = sprintf('%s: %s', $highestRecord['level_name'], $highestRecord['message']);

        $body = json_encode([
            'fields' => [
                'project' => [
                    'id' => $projectId,
                ],
                'issuetype' => ['id' => $issueTypeId],
                'summary' => strlen($summary) > 255 ? substr($summary, 0, 252).'...' : $summary,
                'description' => $content,
                $hashFieldId => $hash,
                $countFieldId => 1,
            ],
        ]);
        $uri = $this->urlFactory->createUri(sprintf('https://%s/rest/api/2/issue', $this->hostname));
        $request = $this->requestFactory->createRequest('POST', $uri)->withBody($this->streamFactory->createStream($body));
        $response = $this->httpClient->sendRequest($request);
        $data = json_decode($response->getBody()->getContents(), true);
        $this->createdIssueId = $data['id'];
    }


    protected function parseProjectId(array $data): int
    {
        return (int) $data['projects'][0]['id'];
    }


    protected function parseIssueType(array $data, string $issueTypeName): array
    {
        return array_values(array_filter($data['projects'][0]['issuetypes'], function ($data) use ($issueTypeName) {
            return $data['name'] === $issueTypeName;
        }))[0];
    }


    protected function parseCustomFieldId(array $data, string $part, string $fieldName): string
    {
        return array_values(array_filter($data, function ($item) use ($fieldName) {
            return $item['name'] === $fieldName;
        }))[0]['id'];
    }


    public function getCreatedIssueId()
    {
        return $this->createdIssueId;
    }


    /**
     * Recursively removes keys from a provided array by using a callback.
     *
     * @param array $array The array to remove items from.
     * @param callable $callback The callback to use to filter items to remove.
     */
    private function arrayWalkRecursiveRemove(
        array $array,
        callable $callback
    ): array
    {
        foreach ($array as $k => $v)
        {
            if ($callback($v, $k))
            {
                unset($array[$k]);
            }
            elseif (is_array($v))
            {
                $array[$k] = $this->arrayWalkRecursiveRemove($v, $callback);
            }
        }
        return $array;
    }


    /**
     * Generates a hash from the provided data array.
     *
     * @param array $data The data to be used to generate a hash.
     * @return string
     */
    private function generateHash(array $data): string
    {
        $hasData = $data;

        // Convert exception data to an array for filtering
        if (isset($hasData['context']['exception']))
        {
            $hasData['context']['exception'] = (array)$hasData['context']['exception'];
        }

        $hasData = $this->arrayWalkRecursiveRemove($hasData, function($value, $key) {
            return in_array($key, $this->excludeHashData);
        });

        return md5(serialize($hasData));
    }
}
