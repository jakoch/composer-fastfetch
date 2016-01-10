Composer Plugin - FastFetch
---------------------------

The Composer Plugin FastFetch downloads packages fast and in parallel into your Composer cache.

[![Total Downloads](https://img.shields.io/packagist/dt/jakoch/composer-fastfetch.svg?style=flat-square)](https://packagist.org/packages/jakoch/composer-fastfetch)
[![Latest Stable Version](https://img.shields.io/packagist/v/jakoch/composer-fastfetch.svg?style=flat-square)](https://packagist.org/packages/jakoch/composer-fastfetch)
[![Build Status](https://img.shields.io/travis/jakoch/composer-fastfetch/master.svg?style=flat-square)](http://travis-ci.org/#!/jakoch/composer-fastfetch)
[![Scrutinizer Code Quality](https://img.shields.io/scrutinizer/g/jakoch/composer-fastfetch/master.svg?style=flat-square)](https://scrutinizer-ci.com/g/jakoch/composer-fastfetch/?branch=master)
[![Code Coverage](https://img.shields.io/scrutinizer/coverage/g/jakoch/composer-fastfetch/master.svg?style=flat-square)](https://scrutinizer-ci.com/g/jakoch/composer-fastfetch/?branch=master)

#### Requirements

   This package requires an external downloading tool named "aria2".
   - You may find the latest release over here: https://github.com/tatsuhiro-t/aria2/releases/latest
   - Linux, e.g. Travis: `sudo apt-get install -y aria2`
   - Windows: download manually and add it to the environment path or to the `\bin` folder of your project
   - Mac/OSX: download & compile or `brew install aria2`

#### Usage

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

   - Use `--prefer-dist` when executing Composer, e.g. `php composer.phar install --prefer-dist`.

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
-** Composer Download Step**
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

Aria makes multiple connections to one or multiple servers and reuses connections, when possible.
Files are not downloaded sequentially, but in parallel.
All in all, this might result in a slight download speed improvement.
