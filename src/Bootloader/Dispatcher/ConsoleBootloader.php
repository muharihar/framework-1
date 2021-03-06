<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

namespace Spiral\Bootloader\Dispatcher;

use Spiral\Boot\KernelInterface;
use Spiral\Command\Framework\CleanCommand;
use Spiral\Command\Module\PublishCommand;
use Spiral\Config\ConfiguratorInterface;
use Spiral\Console\Command\ReloadCommand;
use Spiral\Console\CommandLocator;
use Spiral\Console\ConsoleCore;
use Spiral\Console\ConsoleDispatcher;
use Spiral\Console\LocatorInterface;
use Spiral\Core\Bootloader\Bootloader;

class ConsoleBootloader extends Bootloader
{
    const BOOT = true;

    const SINGLETONS = [
        ConsoleCore::class      => ConsoleCore::class,
        LocatorInterface::class => CommandLocator::class
    ];

    /**
     * @param KernelInterface       $kernel
     * @param ConsoleDispatcher     $console
     * @param ConfiguratorInterface $configurator
     */
    public function boot(
        KernelInterface $kernel,
        ConsoleDispatcher $console,
        ConfiguratorInterface $configurator
    ) {
        $kernel->addDispatcher($console);

        $configurator->setDefaults('console', [
            'commands'  => [
                ReloadCommand::class,
                PublishCommand::class,
                CleanCommand::class
            ],
            'configure' => [],
            'update'    => []
        ]);
    }
}