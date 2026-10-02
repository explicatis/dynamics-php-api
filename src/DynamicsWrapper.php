<?php declare(strict_types=1);

namespace Explicatis\DynamicsPhpApi;

use BenjaminFavre\OAuthHttpClient\OAuthHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use SaintSystems\OData\Entity;
use SaintSystems\OData\IODataClient;
use SaintSystems\OData\IODataResponse;
use SaintSystems\OData\ODataClient;
use SaintSystems\OData\Psr17HttpProvider;
use SaintSystems\OData\Query\Builder;
use SaintSystems\OData\RequestHeader;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DynamicsWrapper
{
    private IODataClient $oDataClient;

    public function __construct(
        string $dynamicsAuthBaseUrl,
        string $dynamicsApiBaseUrl,
        string $dynamicsTenantId,
        string $dynamicsAppId,
        string $dynamicsClientKey,
        HttpClientInterface $httpClient
    ) {
        $tokenUrl = $dynamicsAuthBaseUrl . $dynamicsTenantId . '/oauth2/v2.0/token';
        $url = parse_url($dynamicsApiBaseUrl);
        if (!$url || !array_key_exists('scheme', $url) || !array_key_exists('host', $url)) {
            throw new \InvalidArgumentException('Error parsing Dynamics API base URL');
        }
        $scope = $url['scheme'] . '://' . $url['host'] . '/.default';

        $grantType = new ScopedClientCredentialsGrantType(
            $httpClient,
            $tokenUrl,
            $dynamicsAppId,
            $dynamicsClientKey,
            $scope
        );

        $oauthClient = new OAuthHttpClient($httpClient, $grantType);
        $psrClient = new Psr18Client($oauthClient);
        $requestFactory = new Psr17Factory();
        $streamFactory = new Psr17Factory();
        $httpProvider = new Psr17HttpProvider($psrClient, $requestFactory, $streamFactory);

        $this->oDataClient = new ODataClient($dynamicsApiBaseUrl, httpProvider: $httpProvider)
            ->addHeader(RequestHeader::PREFER, 'odata.include-annotations="OData.Community.Display.V1.FormattedValue"')
        ;
    }

    public function getClient(): IODataClient
    {
        return $this->oDataClient;
    }

    /**
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @return Entity[]
     */
    public function fetchXmlEntities(string $table, string $fetchXml): array
    {
        return $this->fetchXml($table, $fetchXml, null);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws \UnexpectedValueException
     * @throws \ErrorException if Dynamics reports an error in the response body
     */
    public function fetchXmlRaw(string $table, string $fetchXml): IODataResponse
    {
        $result = $this->fetchXml($table, $fetchXml, false);
        if (!is_array($result) || !array_key_exists(0, $result)) {
            throw new \UnexpectedValueException('Result should have been an array with an IODataResponse');
        }
        $body = $result[0]->getBody();
        if (is_array($body) && array_key_exists('error', $body)) {
            throw new \ErrorException($body['error']['message'] ?? 'Unknown Dynamics API error');
        }

        return $result[0];
    }

    private function fetchXml(string $table, string $fetchXml, bool|string|null $entityReturnType = null): array|IODataResponse
    {
        // Remove unnecessary whitespace from FetchXML string
        $fetchXml = trim(preg_replace('/\s+/', ' ', $fetchXml));

        $this->oDataClient->setEntityReturnType($entityReturnType);

        // TODO Check size limit for GET parameter. Maybe send via POST?
        return $this->oDataClient->get("$table?fetchXml=" . urlencode($fetchXml));
    }

    /**
     * @throws ODataException
     * @return Entity[]
     */
    public function oDataEntities(string $request, string $method = 'GET'): array
    {
        return (array) $this->oDataClient->request($method, $request);
    }

    /**
     * @throws ODataException
     * @throws \UnexpectedValueException
     */
    public function oDataRaw(string $request, string $method = 'GET'): IODataResponse
    {
        $this->oDataClient->setEntityReturnType(false);

        $result = $this->oDataClient->request($method, $request);
        if (!is_array($result) || !array_key_exists(0, $result)) {
            throw new \UnexpectedValueException('Result should have been an array with an IODataResponse');
        }

        return $result[0];
    }

    /**
     * @param string $table
     * @param array<string> $fields
     * @param array<string> $filters
     * @param array<string> $expandFields
     * @return Builder
     */
    public function getQueryBuilder(
        string $table,
        array $fields,
        array $filters,
        array $expandFields = [],
    ): Builder {
        $queryBuilder = $this->getClient()->from($table)->select($fields)->where($filters);
        if (!empty($expandFields)) {
            $queryBuilder->expand($expandFields);
        }

        return $queryBuilder;
    }

    /**
     * @param string $table
     * @param array<string> $fields
     * @param array<string> $filters
     * @param array<string> $expandFields
     * @return string
     */
    public function getRequestString(
        string $table,
        array $fields,
        array $filters,
        array $expandFields = [],
    ): string {
        return $this->getQueryBuilder($table, $fields, $filters, $expandFields)->toRequest();
    }

    /**
     * @param string $table
     * @param array<string> $fields
     * @param array<string> $filters
     * @param array<string> $expandFields
     * @return string
     * @deprecated Use getRequestString or the query builder
     */
    public function buildRequest(
        string $table,
        array $fields,
        array $filters,
        array $expandFields = [],
    ): string {
        $requestParts = [];
        if (!empty($fields)) {
            $requestParts[] = '$select=' . implode(',', $fields);
        }
        if (!empty($expandFields)) {
            $requestParts[] = '$expand=' . implode(',', $expandFields);
        }
        if (!empty($filters)) {
            $requestParts[] = '$filter=(' . implode(') and (', $filters) . ')';
        }

        return $table . '?' . implode('&', $requestParts);
    }
}