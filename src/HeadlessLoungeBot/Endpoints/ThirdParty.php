<?php
declare(strict_types=1);
namespace Soatok\HeadlessLoungeBot\Endpoints;

use Interop\Container\Exception\ContainerException;
use ParagonIE\Certainty\Exception\CertaintyException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Container;
use Soatok\AnthroKit\Endpoint;
use Soatok\HeadlessLoungeBot\Telegram;
use Soatok\HeadlessLoungeBot\Twitch;

/**
 * Class ThirdParty
 * @package Soatok\HeadlessLoungeBot\Endpoints
 */
class ThirdParty extends Endpoint
{
    /** @var Telegram $telegram */
    protected $telegram;

    /** @var Twitch $twitch */
    protected $twitch;

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
        $this->telegram = new Telegram($container);
        $this->twitch = new Twitch($container);

        parent::__construct($container);
    }

    /**
     * @param RequestInterface $request
     * @param ResponseInterface|null $response
     * @param array $routerParams
     * @return ResponseInterface
     */
    public function __invoke(
        RequestInterface $request,
        ?ResponseInterface $response = null,
        array $routerParams = []
    ): ResponseInterface {


        return $this->json([]);
    }
}
