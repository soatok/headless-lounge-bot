<?php
declare(strict_types=1);
namespace Soatok\HeadlessLoungeBot\Tests;

use PHPUnit\Framework\TestCase;
use Soatok\HeadlessLoungeBot\Telegram;
use Soatok\HeadlessLoungeBot\TestHelper;

class TelegramTest extends TestCase
{
    /**
     * @throws \Interop\Container\Exception\ContainerException
     * @throws \ParagonIE\Certainty\Exception\CertaintyException
     * @throws \SodiumException
     */
    public function testConstructor()
    {
        $container = TestHelper::getContainer();
        $telegram = new Telegram($container);
        $this->assertInstanceOf(Telegram::class, $telegram);
    }
}
