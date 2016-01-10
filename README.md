Composer Plugin - FastFetch
---------------------------

The Composer Plugin FastFetch downloads packages fast and in parallel into your Composer cache.

[![Total Downloads](https://img.shields.io/packagist/dt/jakoch/composer-fastfetch.svg?style=flat-square)](https://packagist.org/packages/jakoch/composer-fastfetch)
[![Latest Stable Version](https://img.shields.io/packagist/v/jakoch/composer-fastfetch.svg?style=flat-square)](https://packagist.org/packages/jakoch/composer-fastfetch)
[![Build Status](https://img.shields.io/travis/jakoch/composer-fastfetch/master.svg?style=flat-square)](http://travis-ci.org/#!/jakoch/composer-fastfetch)
[![Scrutinizer Code Quality](https://img.shields.io/scrutinizer/g/jakoch/composer-fastfetch/master.svg?style=flat-square)](https://scrutinizer-ci.com/g/jakoch/composer-fastfetch/?branch=master)
[![Code Coverage](https://img.shields.io/scrutinizer/coverage/g/jakoch/composer-fastfetch/master.svg?style=flat-square)](https://scrutinizer-ci.com/g/jakoch/composer-fastfetch/?branch=master)

#### Requirements

- **External Download Tool - Aria2**
   - This package requires an external downloading tool named "[aria2](https://aria2.github.io/)".
   - You may find the latest release over here: https://github.com/tatsuhiro-t/aria2/releases/latest
   - Linux, e.g. Travis: `sudo apt-get install -y aria2`
   - Windows: download manually and add it to the environment path or to the `\bin` folder of your project
   - Mac/OSX: download & compile or `brew install aria2`
- **Env**
   - This plugin makes use of `exec()` to run the external download tool on the CLI. It will not work on shared hosting environments, where `exec` is disabled (`php.ini` `disabled_functions`).

#### Installation and Usage

- You might install this plugin on a single project or globally.

- Use `--prefer-dist` when executing Composer, e.g. `php composer.phar install --prefer-dist`.

##### Installation for a single project (project install)

   - Add the package dependency `jakoch/composer-fastfetch` at the pole position of your require section.

```
{
   "require": {
      "jakoch/composer-fastfetch":  "*",
      "vendor/package-A":           "1.0.0",
      "vendor/package-B":           "2.0.0"
    }
}
```

#### Installation for multiple projects (global install)

##### Install

```bash
$ composer global require jakoch/composer-fastfetch
```

##### Uninstall

```bash
$ composer global remove jakoch/composer-fastfetch
```

#### How does this work?

FastFetch is a Composer Plugin for downloading packages fast and in parallel into your cache.

Conceptually the plugin implements a simple cache warming strategy.

When Composer reaches the install step, it will firstly download this plugin.
The plugin will generate a list with download urls for the packages.
Then it shell-executes a download tool. The preferred download tool is "aria2".
You might also know it as the tool behind [apt-fast](https://github.com/ilikenwf/apt-fast).
(Other tools like "curl", "wget" might also get supported in the future. Please send PRs.)

The download tool retrieves all the packages directly into Composers cache folder.
When Composer proceeds and reaches the "install/download" event for the next packages,
it will skip downloading and use the cached files for installation.

#### Workflow of the plugin

This is a rough overview of the execution flow:

- **Composer Init**
   - You execute `composer install --prefer-dist`
   - Composer hopefully resolves your dependencies into a stable set.
   - This stable set contains the dist URLs for all the packages.
- **Composer Download Step**
   - Composer starts downloading the packages.
   - The first downloaded package is the plugin "composer-fastfetch".
   - The plugin is auto-started after its download.
- **FastFetch Plugin**
   - Detect Download Tool on Environment and init command wrapper class
   - Build download list
     - (Transform Array of DistUrls into a file with the URLs to download)
   - Run download tool
     - (Process "downloads" file and download each file into the cache folder)
   - External Download tool fetched the files. All done. Exit Plugin.
- **Back in the Composer Download Step**
    - Composer is still in the download step and proceeds as normal.
      It tries to downloading the next dependencies/packages,
      BUT skips each, because all the files are already in the Composer cache folder.
- **Composer Install Step**
    - Composer extracts the packages from the cache to the vendor folder.

### Why?

The main reason for this plugin is that Composer downloads all files one after another (sequentially).
This is true for the package metadata (packagist.org) and all package downloads (dists).
The downloads work, but the overall speed and download experience isn't that nice.

Parallelizing the metadata retrival is complicated, because Composer doesn't expose events (like "onBeforeMetadataRetrieval" or similar) and the internal API is protected. At this point in time, its not possible to parallelize the metadata fetching.

But, Composer provides an event "onPostDependenciesSolving", which means the Solver has finishes its dirty deeds
and a stable set of packages has been determined. The set might contain download urls for the packages, which allows forwarding them to a parallel downloader.

Aria makes multiple connections to one or multiple servers and reuses connections, when possible.
Files are not downloaded sequentially, but in parallel.

All in all, this might result in a slight download speed improvement and improve the download experience.
