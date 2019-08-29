<?php
declare(strict_types=1);
namespace Soatok\HeadlessLoungeBot\Endpoints;

use Interop\Container\Exception\ContainerException;
use ParagonIE\Certainty\Exception\CertaintyException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Container;
use Soatok\AnthroKit\Endpoint;
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
        $oauth = Symmetric::decrypt($state['oauth'], $this->encKey);

        return $this->json($oauth);
    }

    /**
     * @return ResponseInterface
     * @throws ContainerException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    protected function authorizeTwitch(): ResponseInterface
    {
        if (empty($_GET['access_token']) || empty($_GET['state'])) {
            /*
             * Let's not mince words: Twitch's API is stupid.
             *
             * Instead of passing this back as a request parameter, they pass it
             * as a URL fragment, so we have to fetch document.location.hash from
             * JavaScript and pass it in a follow-up request.
             *
             * Why? Because Twitch wants us to open our doors to Open Redirect
             * vulnerabilities apparently!
             *
             * So let's just kludge this...
             */
            return $this->view('twitch.twig');
        }
        $oauth = Symmetric::decrypt($_GET['state'], $this->encKey);
        return $this->json($oauth);
    }

    /**
     * @param RequestInterface $request
     * @param ResponseInterface|null $response
     * @param array $routerParams
     * @return ResponseInterface
     * @throws ContainerException
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
