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

use Composer\Cache;
use Composer\Config;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\IO\IOInterface;
use Composer\Util\ProcessExecutor;
use Composer\Package;
//use Composer\Util\GitHub; // helper util to raise rate limit?

/**
 * Downloader
 */
class Downloader
{
    public $composer, $io, $cache, $config;

    public $cacheFilesDir = '';

    /**
     * @var object Composer\Plugin\FastFetch\Downloader\*
     */
    public $downloader;

    /**
     * @var string DownloadList
     */
    public $downloadList = array();

    /**
     * @var string Path to download list file.
     */
    public $downloadFile;

    /**
     * @var string Path to bin-dir
     */
    public $binDir = '';

    public function __construct(\Composer\Composer $composer, \Composer\IO\IOInterface $io, $downloader)
    {
        $this->composer   = $composer;
        $this->io         = $io;
        $this->config     = $composer->getConfig();
        $this->downloader = $downloader;

        // exit early, if no downloader was found
        if (!is_object($this->downloader)) {
            $this->io->write('Skipping downloading, because Composer couldn\'t find a suitable download tool.');
            $this->io->write('You might install one of the following tools: aria2'); //, wget, curl.
            return;
        }

        // init cache
        if ($this->config->get('cache-files-ttl') > 0) {
            $this->cacheFilesDir = $this->config->get('cache-files-dir');
            $this->cache         = new Cache($this->io, $this->cacheFilesDir, 'a-z0-9_./');
        }

        // remove log file on re-run
        if ($this->io->isDebug()) {
            $this->removeLogfile();
        }
    }

    /**
     * Main method for downloading.
     *
     * Execution workflow:
     *
     * 1. iterate over the Operations array
     * 2. fetch distURLs for all packages
     * 3. add URLs to a download list, which is stored to a temp file
     * 4. finally: execute the download util and process URL list
     *
     * @param array $operations
     * @return type
     */
    public function downloadPackages(array $operations)
    {
        if ($this->io->isVerbose()) {
            $this->io->write('Adding packages for downloading....');
        }

        $counter = 0;

        foreach ($operations as $idx => $op) {
            if ($op instanceof InstallOperation) {          // package to install
                $package = $op->getPackage();
            } elseif ($op instanceof UpdateOperation) {     // package to update
                $package = $op->getTargetPackage();
            } else {
                continue;                                   // skip all other packages
            }

            // do not download this plugin again
            if($package->getName() === 'jakoch/composer-fastfetch') {
                continue;
            }

            // show package name
            if ($this->io->isVerbose()) {
                $this->io->write('[<info>' . $package->getName() . '</info>]'); // (<comment>' . VersionParser::formatVersion($package) . '</comment>)');
            }

            // Lets grab the URLs of all packages (not-cached-yet)
            $url = $package->getDistUrl();

            // uhm, mutiple URLs? are that real mirror URLs or local mirrors?
            //$url = array_shift($urls);

            // moan, when there is no download URL
            if (!$package->getDistUrl()) {
                if ($this->io->isVerbose()) {
                    $this->io->write(' - The package was not added to the download list, because it did not provide any distUrls.');
                }
                continue;
            }

            /**
             * The next section is similar to Composer/Downloader/FileDownloader->doDownload()
             *
             * Build cache infos: sum, key, (cached) file, full cache target path
             */
            $checksum    = $package->getDistSha1Checksum();
            $fileName    = $package->getTargetDir() . '/' . pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_BASENAME);
            $cacheFile   = $this->getCacheKey($package);
            $cacheFolder = $this->cacheFilesDir . '/' . $cacheFile;

            /*var_dump(
                $package->getDistUrl(),
                $package->getDistUrls,
                'Checksum: ' . $checksum,
                'SHA1: ' . $this->cache->sha1($cacheFile)
                //'hashfile sha1: ' .hash_file('sha1', $fileName)
            ); exit;*/

            /**
             * add distURL to download list, but only, if file is "not cached, yet" or "cache is invalidated".
             *
             * Note: to improve the Composer API, get rid of this long condition check, in favor of a short function,
             * e.g. $package->isCached() or $cache->hasPackage($package).
             *
             */
            if (!$this->cache || ($checksum && $checksum !== $this->cache->sha1($cacheFile)) || !$this->cache->copyTo($cacheFile, $fileName)) {

                // show URL and the target path for this package
                if ($this->io->isVerbose()) {
                    $this->io->write(' + From ' . $url);
                    $this->io->write('   To   ' . $cacheFolder);
                }

                $this->downloader->addDownloadUrl($url, $cacheFolder);

                $counter++;
            } else {
                if ($this->io->isVerbose()) {
                    $this->io->write(' - The package was not added to the download list, because its already cached.');
                }
            }
        }

        // skip parallel downloading, if "no distUrls found".
        if($counter == 0) {
            $this->io->write('<info>FastFetch: skipped downloading, because no distUrls were found.</info>' . PHP_EOL);
            return;
        }

        // save download list to temp folder
        $this->downloadsFile = tempnam(sys_get_temp_dir(), 'download.list');
        file_put_contents($this->downloadsFile, $this->downloader->downloadList);

        $this->io->write('<info>Downloading ' . $counter . ' packages.</info>');
        $this->io->write('<info>Working... this may take a while.</info>');
        $this->executeDownloader();
        $this->io->write('<info>FastFetch: Done.</info>' . PHP_EOL);

        unlink($this->downloadsFile);
    }

    public function executeDownloader()
    {
        // collect output into a buffer, but only display in verbose mode
        $callableOutputHandler = function ($type, $buffer) use (&$output) {
            if ($type !== 'out') {
                return;
            }
            $output .= $buffer;
            if ($this->io->isVerbose()) {
                $this->io->write($buffer, false);
            }
        };

        $this->process = new ProcessExecutor($this->io);
        // use command from downloader, insert into command string, then execute
        $command = $this->downloader->getCommand();
        $command = ($this->binDir !== '') ? $this->binDir . $command : $command;
        $escapedCmd = sprintf($command, $this->downloadsFile);

        if (!0 === $this->process->execute($escapedCmd, $callableOutputHandler)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }
    }

    /**
     * Get the cache file path (without cache-files-dir prefix).
     *
     * @param PackageInterface $p
     * @return type
     */
    public function getCacheKey(\Package\PackageInterface $p)
    {
        $distRef = $p->getDistReference();

        if (preg_match('{^[a-f0-9]{40}$}', $distRef)) {
            return $p->getName() . '/' . $distRef . '.' . $p->getDistType();
        }

        // Composer builds the key like this, but it doesn't work:
        //return $p->getName().'/'.$p->getVersion().'-'.$distRef.'.'.$p->getDistType();

        // we need to apply the SHA1 fix from
        // https://github.com/krispypen/composer/commit/d8fa9ab57efdac94cd7135d96c2523932ac30f8b
        return $p->getName().'/'.$p->getVersion().'-'.$distRef.'-'.$p->getDistSha1Checksum().'.'.$p->getDistType();
    }

    /**
     * Returns the authentication token for the url.
     *
     * @param string $url
     * @return string AuthToken
     */
    public function getAuthTokenForUrl($url)
    {
        if (false !== strpos($url, 'github')) {
            if ($oauth = $this->config->get('github-oauth')) {
                foreach ($oauth as $domain => $token) {
                    if(false !== strpos($url, $domain)) {
                        return $token;
                    }
                }
            }
        }
    }

    public function removeLogfile()
    {
        $logfile = getcwd() . '/composer.fastfetch.log';

        if (file_exists($logfile)) {
            unlink($logfile);
        }
    }

}
