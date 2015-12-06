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

    public function __construct(\Composer\Composer $composer, \Composer\IO\IOInterface $io)
    {
        $this->composer   = $composer;
        $this->io         = $io;
        $this->config     = $composer->getConfig();
        $this->downloader = $this->findDownloader();

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
            if ($op instanceof InstallOperation) {
                $package = $op->getPackage();
            } elseif ($op instanceof UpdateOperation) {
                $package = $op->getTargetPackage();
            } else {
                continue;
            }

            // do not download this plugin again
            if($package->getName() === 'jakoch/composer-fastfetch') {
                continue;
            }

            // show package name
            if ($this->io->isVerbose()) {
                $this->io->write('[<info>' . $package->getName() . '</info>]'); // (<comment>' . VersionParser::formatVersion($package) . '</comment>)');
            }

            // bah! we don't have an URL?
            if (!$package->getDistUrl()) {
                if ($this->io->isVerbose()) {
                    $this->io->write(' - The package was not added to the download list, because it did not provide any distUrls.');
                }
            }

            /**
             * The next section is similar to Composer/Downloader/FileDownloader->doDownload()
             * Lets grab the URLs of all packages (not-cached-yet).
             */

            // build cache infos: sum, key, (cached) file, full cache target path
            $url           = $package->getDistUrl();
            //$url         = array_shift($urls); // uhm, mutiple URLs? are that real mirror URLs or local mirrors?
            $checksum      = $package->getDistSha1Checksum();
            $fileName      = $package->getTargetDir() . '/' . pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_BASENAME);
            $cacheFile     = $this->getCacheKey($package);
            $cacheFolder   = $this->cacheFilesDir . '/' . $cacheFile;

            /*var_dump(
                $package->getDistUrl(),
                $package->getDistUrls,
                'Checksum: ' . $checksum,
                'SHA1: ' . $this->cache->sha1($cacheFile)
                //'hashfile sha1: ' .hash_file('sha1', $fileName)
            ); exit;*/

            /**
             * add distURL to download list only, if file is "not cached, yet" or "cache is invalidated"
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
        $this->io->write('<info>FastFetch: Ok. All done.</info>' . PHP_EOL);

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

        // use command from downloader, escape, then execute
        $command = $this->downloader->getCommand();
        $command = ($this->binDir !== '') ? $this->binDir . $command : $command;
        $escapedCmd = sprintf($command, ProcessExecutor::escape($this->downloadsFile));
        $this->process = new ProcessExecutor($this->io);

        if (!0 === $this->process->execute($escapedCmd, $callableOutputHandler)) {
            throw new \RuntimeException('Failed to execute ' . $command . "\n\n" . $this->process->getErrorOutput());
        }
    }

    /**
     * Get the cache file path (without cache-files-dir prefix).
     *
     * @param PackageInterface $package
     * @return type
     */
    public function getCacheKey($package)
    {
        if (preg_match('{^[a-f0-9]{40}$}', $package->getDistReference())) {
            return $package->getName() . '/' . $package->getDistReference() . '.' . $package->getDistType();
        }

        //return $package->getName() . '/' . $package->getVersion() . '-' . $package->getDistReference() . '.' . $package->getDistType();

        // fix from https://github.com/krispypen/composer/commit/d8fa9ab57efdac94cd7135d96c2523932ac30f8b
        return $package->getName().'/'.$package->getVersion().'-'.$package->getDistReference().'-'.$package->getDistSha1Checksum().'.'.$package->getDistType();
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

    /**
     * Searches the environment for known downloaders
     * and returns the (cli-command wrapper) object for the downloader.
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
    public function findDownloader()
    {
/*
        // override download tool detection, by using the manually defined tool from config
        if ($this->config->get('download-tool')) {
            $name = $this->config->get('download-tool');
            $className = 'Downloader\\' . $downloaderClassName;
            return new $className($this->composer, $this->io);
        }
*/
        if ($this->io->isVerbose()) {
            $this->io->write('Searching environment for known download tools...');
        }

        if ($this->cmdExists('aria2c')) {
            return new Downloader\Aria($this->composer, $this->io);
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
        //$file = (PHP_OS === 'WINNT') ? str_replace('/', '\\', $file) : $file;
        if(file_exists($file)) {
            $this->binDir = $binDir;
            return true;
        }

        // check, if the file can be detected on the ENV using "where" or "which"
        $find = (PHP_OS === 'WINNT') ? 'where' : 'which';
        $cmd = sprintf('%s %s', escapeshellarg($find), escapeshellarg($command));
        $process = new ProcessExecutor($this->io);
        return ($process->execute($cmd) === 0);
    }

    public function removeLogfile()
    {
        $logfile = getcwd() . '/composer.fastfetch.log';

        if (file_exists($logfile)) {
            unlink($logfile);
        }
    }

}
