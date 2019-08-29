<?php
declare(strict_types=1);
namespace Soatok\HeadlessLoungeBot\Endpoints;

use Interop\Container\Exception\ContainerException;
use ParagonIE\Certainty\Exception\CertaintyException;
use ParagonIE\ConstantTime\Base32;
use Patreon\AuthUrl;
use Patreon\OAuth;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Container;
use Soatok\AnthroKit\Endpoint;
use Soatok\HeadlessLoungeBot\Exceptions\UserNotFoundException;
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
            empty($_SESSION['patreon_oauth_id'])
                ||
            empty($_GET['code'])
                ||
            empty($_GET['state'])
        ) {
            return $this->redirect('/');
        }

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
        if (empty($_SESSION['twitch_oauth_id'])) {
            return $this->redirect('/');
        }

        if (empty($_GET['access_token'])) {
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
