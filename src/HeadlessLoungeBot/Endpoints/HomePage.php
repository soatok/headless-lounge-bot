<?php
declare(strict_types=1);
namespace Soatok\HeadlessLoungeBot\Endpoints;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Container;
use Soatok\AnthroKit\Endpoint;

/**
 * Class HomePage
 * @package Soatok\HeadlessLoungeBot\Endpoints
 */
class HomePage extends Endpoint
{
    public function __construct(Container $container)
    {
        parent::__construct($container);
    }

    public function __invoke(
        RequestInterface $request,
        ?ResponseInterface $response = null,
        array $routerParams = []
    ): ResponseInterface {
        return $this->json(['success']);
    }
}
