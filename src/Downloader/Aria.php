<?php

/*
 * This file is part of the "jakoch/composer-fastfetch" package.
 *
 * (c) Jens A. Koch <jakoch@web.de>
 *
 * The content is released under the MIT License. Please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Plugin\FastFetch\Downloader;

use Composer\IO\IOInterface;
use Composer\Composer;

/**
 * A wrapper class for the download tool "aria2".
 */
class Aria
{
    // @var IOInterface
    protected $composer;

    // @var IOInterface
    protected $io;

    public $downloadList = '';

    public function __construct(\Composer\Composer $composer, \Composer\IO\IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;

        $io->write('Using downloader: Aria');
    }

    public function addDownloadUrl($sources, $target)
    {
        $sources = (array) $sources;

        // can a package have multiple download sources (mirrors)?
        foreach ($sources as $idx => $url) {
            $this->downloadList .= $url . PHP_EOL;

            /**
             * @todo bypass the github rate limit by setting a authentication header per URI
             *
             * setting an authentication header per URI?
             * this feature is not (yet) supported by aria2,
             * see https://github.com/tatsuhiro-t/aria2/issues/81
             */
            //if ($token = $this->getAuthTokenForUrl($url)) {
            //   $this->downloadList .= ' header="Authorization: token ' . $token . '"' . PHP_EOL;
            //}
        }

        $this->downloadList .= '  dir=' . pathinfo($target, PATHINFO_DIRNAME) . PHP_EOL;
        $this->downloadList .= '  out=' . pathinfo($target, PATHINFO_BASENAME) . PHP_EOL;
    }

    /**
     * getCommand returns a sprintf() string to invoke "aria2c".
     * The download file is inserted later inserted at "-i%s".
     * The rest of the arguments are configuration and auth, see:
     *
     * Input File Options:
     * @link http://aria2.sourceforge.net/manual/en/html/aria2c.html#input-file
     *
     * Options used:
     * -i Download the files listed in this file concurrently.
     * -s Specifies the number of simultaneous connections per host.
     *    Raised from 2 to 4 (ignoring RFC 2616).
     * -j Specifies the number of concurrent downloads.
     * -x Max. number of connections to one server for each download.
     * -t Timeout in seconds.
     * -l Logs to file.
     */
    public function getCommand()
    {
        $cmd = 'aria2c --input-file=%s -s4 -j4 -x2 -t10';
        //$cmd .= ' --conditional-get=true --auto-file-renaming=false';
        //$cmd .= ' --allow-overwrite=true --http-accept-gzip=true';
        //$cmd .= ' --enable-color=true';
        //$cmd .= ' --check-certificate=false'; // rant!
        //$cmd .= ' --user-agent="' . $this->getUserAgent() . '"';

        //if($this->io->isDebug()) {
          //  $cmd .= ' -lcomposer.fastfetch.log';
        //}

        // setting the auth header token for Github might be useful to raise the download limit?
        // X-RateLimit-Limit
        //$cmd .= ' --header="Authorization: token ' . $this->getAuthTokenForUrl('github.com') . '"';

        return $cmd;
    }

    public function getUserAgent()
    {
        $composerVersion = (Composer::VERSION === '@package_version@') ? 'source' : Composer::VERSION;

        $phpVersion = (defined('HHVM_VERSION'))
            ? 'HHVM ' . HHVM_VERSION
            : 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;

        return sprintf('Composer/%s (%s; %s; %s)', $composerVersion, php_uname('s'), php_uname('r'), $phpVersion);
    }
}
