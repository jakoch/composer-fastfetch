<?php

/*
 * This file is part of the "jakoch/composer-fastfetch" package.
 *
 * (c) Jens A. Koch <jakoch@web.de>
 *
 * The content is released under the MIT License. Please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Plugin\FastFetch;

use Composer\Composer;
use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\FastFetch\Downloader;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;

/**
 * FastFetch is a Composer Plugin for downloading packages fast and in parallel into your cache.
 *
 * When Composer reaches the install step, it will firstly download this plugin.
 * The plugin generates a list with download urls for the packages
 * and shell-executes a download tool. The preferred download tool is "aria2".
 * The download tool retrieves all the packages directly into Composers cache folder.
 * When Composer proceeds and reaches the "install/download" event for the next packages,
 * it will skip downloading and use the cached files for installation.
 */
class FastFetch implements PluginInterface, EventSubscriberInterface
{
    // @var Composer
    protected $composer;

    // @var IOInterface
    protected $io;

    protected $downloader;

    /**
     * {@inheritDoc}
     */
    public function activate(\Composer\Composer $composer,\Composer\IO\IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;

        $this->downloader = new Downloader($composer, $io);

        /**
         * @todo if we could get all distUrls from Composer at this position,
         * we could skip the event subscribing and proceed executing the downloader.
         * But $operations comes directly from the Solver and vanishes in the event system.
         * https://github.com/composer/composer/blob/9e9c1917e1ed9f3f78b195a785aee3c6dc3cb883/src/Composer/Installer.php#L523
         */
        //$this->downloader->downloadPackages( $composer->fromWhichObject()->getOperations() );
    }

    /**
     * @inheritdoc
     */
    public static function getSubscribedEvents()
    {
        return [
            'post-package-install' => 'onPostPackageInstall',
            'post-package-update' => 'onPostPackageUpdate',
         ];
    }

    public function onPostPackageInstall(\Composer\Installer\PackageEvent $event)
    {
        $installedPackage = $event->getOperation()->getPackage();

        if('jakoch/composer-fastfetch' === $installedPackage->getPrettyName()) {
            $this->downloader->downloadPackages( $event->getOperations() );
        }
    }

    public function onPostPackageUpdate(\Composer\Installer\PackageEvent $event)
    {
        $installedPackage = $event->getOperation()->getPackage();

        if('jakoch/composer-fastfetch' === $installedPackage->getPrettyName()) {
            $this->downloader->downloadPackages( $event->getOperations() );
        }
    }
}