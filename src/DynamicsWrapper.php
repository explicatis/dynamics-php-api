<?php declare(strict_types=1);

namespace Explicatis\DynamicsPhpApi;

use BenjaminFavre\OAuthHttpClient\OAuthHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use SaintSystems\OData\IODataClient;
use SaintSystems\OData\ODataClient;
use SaintSystems\OData\Psr17HttpProvider;
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

        $this->oDataClient = new ODataClient(
            $dynamicsApiBaseUrl,
            null,
            $httpProvider
        )->setHeaders([
            'OData-MaxVersion' => '4.0',
            'OData-Version' => '4.0',
            'Prefer' => 'odata.include-annotations="OData.Community.Display.V1.FormattedValue"'
        ]);
    }

    public function getClient(): IODataClient
    {
        return $this->oDataClient;
    }

    /**
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @return mixed[]
     */
    public function executeFetchXmlRequest(string $table, string $fetchXml): array
    {
        // TODO Check size limit for GET parameter. Maybe send via POST?

        return $this->oDataClient->get("$table?fetchXml=" . urlencode($fetchXml));
    }

    /**
     * @throws ODataException
     */
    public function executeODataRequest(string $request, string $method = 'GET'): array
    {
        return (array) $this->oDataClient->request($method, $request);
    }

    /**
     * @param string $table
     * @param array<string> $fields
     * @param array<string> $filters
     * @param array<string> $expandFields
     * @return string
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