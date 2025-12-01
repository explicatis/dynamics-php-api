<?php declare(strict_types=1);

namespace Explicatis\DynamicsPhpApi;

use BenjaminFavre\OAuthHttpClient\GrantType\GrantTypeInterface;
use BenjaminFavre\OAuthHttpClient\GrantType\Tokens;
use BenjaminFavre\OAuthHttpClient\GrantType\TokensExtractor;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ScopedClientCredentialsGrantType implements GrantTypeInterface
{
    use TokensExtractor;

    public function __construct(
        private HttpClientInterface $client,
        private string $tokenUrl,
        private string $clientId,
        private string $clientSecret,
        private string $scope
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function getTokens(): Tokens
    {
        $response = $this->client->request('POST', $this->tokenUrl, [
            'headers' => ['Authorization' => sprintf(
                'Basic %s',
                base64_encode("{$this->clientId}:{$this->clientSecret}")
            )],
            'body' => http_build_query(['grant_type' => 'client_credentials', 'scope' => $this->scope]),
        ]);

        return $this->extractTokens($response);
    }
}
