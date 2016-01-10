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

use Composer\Util\ProcessExecutor;

/**
 *
 */
class DownloaderFactory
{
    public $io;
    public $composer;
    public $binPath = false;

    public function __construct(\Composer\Composer $composer, \Composer\IO\IOInterface $io)
    {
        $this->composer = $composer;
        $this->io       = $io;
        $this->config   = $composer->getConfig();
    }

    /**
     * Searches the environment for known downloaders
     * and returns the (cli-command wrapper) object for the downloader.
     * Its a factory method for download util wrappers.
     *
     * The "return early style" defines the downloader priority:
     *
     * 1. config->get('extra/composer-fastfetch/download-tool')
     * 2. aria2c
     * 3. parallel + curl
     * 4. curl
     * 5. parallel + wget
     * 6. wget
     *
     * @return string Classname of the downloader.
     */
    public function getDownloader()
    {
/*
        // override download tool detection, by using the manually defined tool from config
        if ($this->config->get('downloader')) {
            $name = $this->config->get('downloader');
            $className = 'Downloader\\' . $name;
            return new $className($this->composer, $this->io);
        }
*/
        if ($this->io->isVerbose()) {
            $this->io->write('Searching environment for known download tools...');
        }

        if ($this->cmdExists('aria2c')) {
            return new Downloader\Aria($this->composer, $this->io, $this->binPath);
        }
/*
        if ($this->cmdExists('curl')) {
            if ($this->cmdExists('parallel')) {
                return new Downloader\ParallelCurl($this->composer, $this->io);
            }
            return new Downloader\Curl($this->composer, $this->io);
        }

        if ($this->cmdExists('wget')) {
            if ($this->cmdExists('parallel')) {
                return new Downloader\ParallelWget($this->composer, $this->io);
            }
            return new Downloader\Wget($this->composer, $this->io);
        }
*/
        return false;
    }

    /**
     * Check, if a command exists on the environment.
     *
     * @param string $command The command to look for.
     * @return bool True, if the command has been found. False, otherwise.
     */
    public function cmdExists($command)
    {
        $binary = (PHP_OS === 'WINNT') ? $command . '.exe' : $command;

        // check, if file exists in the "bin-dir"
        $binDir = $this->config->get('bin-dir') . DIRECTORY_SEPARATOR;
        $file = $binDir . $binary;
        if(file_exists($file)) {
            $this->binPath = $file;
            return true;
        }

        // check, if the file can be detected on the ENV using "where" or "which"
        $find = (PHP_OS === 'WINNT') ? 'where' : 'which';
        $cmd = sprintf('%s %s', escapeshellarg($find), escapeshellarg($command));
        $process = new ProcessExecutor($this->io);
        return ($process->execute($cmd) === 0);
    }
}