<?php
declare(strict_types=1);
namespace Soatok\HeadlessLoungeBot\Endpoints;

use Interop\Container\Exception\ContainerException;
use ParagonIE\Certainty\Exception\CertaintyException;
use ParagonIE\ConstantTime\Base32;
use ParagonIE\HiddenString\HiddenString;
use Patreon\AuthUrl;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Container;
use Soatok\AnthroKit\Endpoint;
use Soatok\DholeCrypto\Key\SymmetricKey;
use Soatok\DholeCrypto\Symmetric;
use Soatok\HeadlessLoungeBot\Exceptions\UserNotFoundException;
use Soatok\HeadlessLoungeBot\Telegram;
use Soatok\HeadlessLoungeBot\Twitch;
use Soatok\HeadlessLoungeBot\Splices\Users;

/**
 * Class ThirdParty
 * @package Soatok\HeadlessLoungeBot\Endpoints
 */
class ThirdParty extends Endpoint
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
        $this->baseUrl = $container['settings']['base-url'];
        $this->encKey = $container['settings']['encryption-key'];
        $this->oauthSettings = [
            'patreon' => $container['settings']['patreon'],
            'twitch' => $container['settings']['twitch'],
        ];
        $this->telegram = new Telegram($container);
        $this->twitch = new Twitch($container);

        parent::__construct($container);
        $this->users = $this->splice('Users');
    }

    /**
     * @param array $row
     * @return ResponseInterface
     * @throws \Exception
     */
    protected function handleTwitchOauth(array $row): ResponseInterface
    {
        $cipher = Symmetric::encrypt(
            new HiddenString(json_encode([
                'expires' => (new \DateTime())
                    ->add(new \DateInterval('PT10M'))
                    ->format(\DateTime::ATOM),
                'url_token' => $row['url_token'],
                'service' => 'Twitch',
                'userid' => $row['userid']
            ])),
            $this->encKey
        );
        $url = 'https://id.twitch.tv/oauth2/authorize?' . http_build_query([
            'client_id' => $this->oauthSettings['twitch']['client-id'],
            'redirect_uri' => $this->baseUrl . '/authorize/twitch',
            'response_type' => 'code',
            'scope' => implode(' ', [
                'channel:read:subscriptions',
                'channel_check_subscription',
                'channel_subscriptions',
                'user:subscriptions',
                'user_subscriptions'
            ]),
            'state' => $cipher
        ]);
        return $this->redirect($url, 302, true);
    }

    /**
     * @param array $row
     * @return ResponseInterface
     * @throws \Exception
     */
    protected function handlePatreonOauth(array $row): ResponseInterface
    {
        $cipher = Symmetric::encrypt(
            new HiddenString(json_encode([
                'expires' => (new \DateTime())
                    ->add(new \DateInterval('PT10M'))
                    ->format(\DateTime::ATOM),
                'url_token' => $row['url_token'],
                'service' => 'Patreon',
                'userid' => $row['userid']
            ])),
            $this->encKey
        );
        $oauth = (new AuthUrl($this->oauthSettings['patreon']['client-id']))
            ->withRedirectUri($this->baseUrl . '/authorize/patreon')
            ->withState(['oauth' => $cipher]);
        return $this->redirect($oauth->buildUrl(), 302, true);
    }

    /**
     * @param RequestInterface $request
     * @param ResponseInterface|null $response
     * @param array $routerParams
     * @return ResponseInterface
     * @throws \Exception
     */
    public function __invoke(
        RequestInterface $request,
        ?ResponseInterface $response = null,
        array $routerParams = []
    ): ResponseInterface {
        if (empty($routerParams['token'])) {
            return $this->redirect('/');
        }

        try {
            $oauth = $this->users->getByThirdPartyUrl($routerParams['token']);
        } catch (UserNotFoundException $ex) {
            return $this->redirect('/');
        }

        switch ($oauth['service']) {
            case 'Twitch':
                return $this->handleTwitchOauth($oauth);
            case 'Patreon':
                return $this->handlePatreonOauth($oauth);
            default:
                return $this->json([]);
        }
    }
}
