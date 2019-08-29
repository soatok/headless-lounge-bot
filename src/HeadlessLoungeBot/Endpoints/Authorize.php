<?php
declare(strict_types=1);
namespace Soatok\HeadlessLoungeBot\Endpoints;

use GuzzleHttp\Client;
use Interop\Container\Exception\ContainerException;
use ParagonIE\Certainty\Exception\CertaintyException;
use ParagonIE\HiddenString\HiddenString;
use Patreon\API as PatreonAPI;
use Patreon\OAuth as PatreonOAuth;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Container;
use Soatok\AnthroKit\Endpoint;
use Soatok\DholeCrypto\Exceptions\CryptoException;
use Soatok\DholeCrypto\Key\SymmetricKey;
use Soatok\DholeCrypto\Symmetric;
use Soatok\HeadlessLoungeBot\Telegram;
use Soatok\HeadlessLoungeBot\Twitch;
use Soatok\HeadlessLoungeBot\Splices\Users;

/**
 * Class ThirdParty
 * @package Soatok\HeadlessLoungeBot\Endpoints
 */
class Authorize extends Endpoint
{
    /** @var string $baseUrl */
    protected $baseUrl;

    /** @var SymmetricKey $encKey */
    protected $encKey;

    /** @var array $oauthSettings */
    protected $oauthSettings;

    /** @var Telegram $telegram */
    protected $telegram;

    /** @var Twitch $twitch */
    protected $twitch;

    /** @var Users $users */
    protected $users;

    /**
     * Start constructor.
     *
     * @param Container $container
     * @throws ContainerException
     * @throws CertaintyException
     * @throws \SodiumException
     */
    public function __construct(Container $container)
    {
        $this->encKey = $container['settings']['encryption-key'];
        $this->oauthSettings = [
            'patreon' => $container['settings']['patreon'],
            'twitch' => $container['settings']['twitch'],
        ];
        $this->telegram = new Telegram($container);
        $this->twitch = new Twitch($container);
        $this->baseUrl = $container['settings']['base-url'];

        parent::__construct($container);
        $this->users = $this->splice('Users');
    }

    /**
     * @return ResponseInterface
     * @throws ContainerException
     * @throws CryptoException
     * @throws \Patreon\Exceptions\APIException
     * @throws \Patreon\Exceptions\CurlException
     * @throws \SodiumException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    protected function authorizePatreon(): ResponseInterface
    {
        if (
            empty($_GET['code'])
                ||
            empty($_GET['state'])
        ) {
            return $this->redirect('/');
        }
        $state = json_decode(base64_decode($_GET['state'], true));
        if (empty($state['oauth'])) {
            // Error:
            return $this->redirect('/');
        }
        $oauth = json_decode(
            Symmetric::decrypt($state['oauth'], $this->encKey)->getString(),
            true
        );
        if ($this->checkExpired($oauth)) {
            return $this->redirect('/');
        }
        if (!\hash_equals('Patreon', $oauth['service'])) {
            return $this->redirect('/');
        }

        $patreonOauth = new PatreonOAuth(
            $this->oauthSettings['patreon']['client-id'],
            $this->oauthSettings['patreon']['client-secret']
        );
        // Get the refresh and access tokens...
        $tokens = $patreonOauth->get_tokens(
            $_GET['code'],
            $this->baseUrl . '/authorize/patreon'
        );

        $user = (new PatreonAPI(new HiddenString($tokens['access_token'])))
            ->fetch_user();
        if (empty($user['data']['id'])) {
            return $this->redirect('/#invalid-patreon-account');
        }
        $tokens['account_id'] = (int) $user['data']['id'];

        try {
            if ($this->users->linkPatreon($tokens, $oauth)) {
                return $this->view('link-success.twig');
            }
        } catch (\Throwable $ex) {
        }
        return $this->redirect('/');
    }

    /**
     * @return ResponseInterface
     * @throws ContainerException
     * @throws CryptoException
     * @throws \SodiumException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    protected function authorizeTwitch(): ResponseInterface
    {
        if (empty($_GET['code']) || empty($_GET['state'])) {
            return $this->redirect('/');
        }
        $oauth = json_decode(
            Symmetric::decrypt($_GET['state'], $this->encKey)->getString(),
            true
        );
        if ($this->checkExpired($oauth)) {
            return $this->redirect('/');
        }
        if (!\hash_equals('Twitch', $oauth['service'])) {
            return $this->redirect('/');
        }
        $http = new Client();

        // Get the refresh and access tokens...
        $query = http_build_query([
            'client_id' => $this->oauthSettings['twitch']['client-id'],
            'client_secret' => $this->oauthSettings['twitch']['client-secret'],
            'code' => $_GET['code'],
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->baseUrl . '/authorize/twitch'
        ]);
        $response = $http->post('https://id.twitch.tv/oauth2/token?' . $query);
        $tokens = json_decode($response->getBody()->getContents(), true);

        // Get the user ID:
        $response = $http->get('https://api.twitch.tv/helix/users', [
            'headers' => [
                'Authorization' => 'Bearer' . $tokens['access_token']
            ]
        ]);
        $ident = json_decode($response->getBody()->getContents(), true);
        if (empty($ident['data'][0]['id'])) {
            return $this->redirect('/#invalid-twitch-account');
        }
        $tokens['account_id'] = $ident['data'][0]['id'];

        try {
            if ($this->users->linkTwitch($tokens, $oauth)) {
                return $this->view('link-success.twig');
            }
        } catch (\Throwable $ex) {
        }
        return $this->redirect('/');
    }

    /**
     * @param array $oauth
     * @return bool
     */
    protected function checkExpired(array $oauth): bool
    {
        if (empty($exp['expires'])) {
            return true;
        }
        try {
            $exp = new \DateTime($oauth['expires']);
            $now = new \DateTime();
        } catch (\Exception $ex) {
            return true;
        }
        return $exp <= $now;
    }

    /**
     * @param RequestInterface $request
     * @param ResponseInterface|null $response
     * @param array $routerParams
     * @return ResponseInterface
     * @throws ContainerException
     * @throws CryptoException
     * @throws \Patreon\Exceptions\APIException
     * @throws \Patreon\Exceptions\CurlException
     * @throws \SodiumException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public function __invoke(
        RequestInterface $request,
        ?ResponseInterface $response = null,
        array $routerParams = []
    ): ResponseInterface {
        if (empty($routerParams['service'])) {
            return $this->redirect('/');
        }

        switch (strtolower($routerParams['service'])) {
            case 'twitch':
                return $this->authorizeTwitch();
            case 'patreon':
                return $this->authorizePatreon();
            default:
                return $this->redirect('/');
        }
    }
}
