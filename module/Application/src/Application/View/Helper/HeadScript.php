<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\View\Helper;

use Zend\View\Helper\HeadScript as ZendHeadScript;

class HeadScript extends ZendHeadScript
{
    const   PUBLIC_PATH     = '/public';
    const   CUSTOM_PATH     = '/custom';
    const   LANGUAGE_PATH   = '/build/language';

    /**
     * Add scripts from the application config.
     *
     * Prepends scripts under the 'js' config property (relative to public path).
     * If the script is declared with a string-key and an array-value then it is
     * considered to be a build (aggregated and compressed set of scripts).
     *
     * If we can find the built script and we are not running in dev-mode, then
     * we add the built version. Otherwise, we add the individual scripts.
     *
     * @return HeadScript   provides fluent interface
     */
    public function addConfiguredScripts()
    {
        $services = $this->getView()->getHelperPluginManager()->getServiceLocator();
        $config   = $services->get('config') + array('js' => array());
        $isDev    = isset($config['environment']['mode']) && $config['environment']['mode'] == 'development';

        foreach (array_reverse((array) $config['js']) as $key => $value) {
            // if key is a string and value is an array its a 'build'
            // if it's a build and it exists and we aren't in dev mode, use it
            // otherwise, we're not using a build so add scripts individually
            $isBuild = is_string($key) && is_array($value);
            $script  = $isBuild && !$isDev ? $this->findCompiledScript($key) : false;
            if ($script) {
                $this->prependFile($script);
            } else {
                foreach (array_reverse((array) $value) as $script) {
                    $this->prependFile($this->getView()->basePath($script));
                }
            }
        }

        return $this;
    }

    /**
     * Looks for compiled language scripts (message files) in a predefined location
     * (public/build/language/*.js). If we find a matching script, we add it.
     *
     * @param   string      $locale             the locale to look for and add
     * @param   string      $fallbackLocale     the fallback locale to look for and add
     * @return  HeadScript  provides fluent interface
     */
    public function addLanguageScripts($locale, $fallbackLocale)
    {
        $localeScript = $this->findCompiledScript(static::LANGUAGE_PATH . '/' . $locale . '.js');
        if ($localeScript) {
            $this->appendFile($localeScript);
        }

        $fallbackScript = $this->findCompiledScript(static::LANGUAGE_PATH . '/' . $fallbackLocale . '.js');
        if ($fallbackLocale !== $locale && $fallbackScript) {
            $this->appendFile($fallbackScript);
        }

        return $this;
    }

    /**
     * Looks for custom js files in a predefined location (public/custom/*.js).
     *
     * @return  HeadScript  provides fluent interface
     */
    public function addCustomScripts()
    {
        $path  = BASE_PATH . static::PUBLIC_PATH . static::CUSTOM_PATH;
        $files = array_merge(glob($path . '/*.js'), glob($path . '/*/*.js'));

        // sort the files to ensure a predictable order
        natcasesort($files);

        foreach ($files as $script) {
            $script = substr($script, strlen(BASE_PATH . static::PUBLIC_PATH . '/'));
            $this->appendFile($this->getView()->basePath($script));
        }

        return $this;
    }

    /**
     * Looks for the given script under the public path and returns a URI for it.
     * If we find a gzip version of the script and the browser accepts gzip, use it.
     * If we have a defined patch-level for the application, add it to cache-bust.
     *
     * @param   string          $name   the name of the script to look for (relative to public path)
     * @return  bool|string     a URI for the script or false if the named script is not found
     */
    protected function findCompiledScript($name)
    {
        $path   = BASE_PATH . static::PUBLIC_PATH;
        $accept = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
        $gz     = strpos($accept, 'gzip') !== false ? 'gz' : '';

        // if we can't find a compressed version, fallback to uncompressed
        // if we can't find either version, then bail out
        if (!is_file($path . '/' . $name . $gz)) {
            if (is_file($path . '/' . $name)) {
                $gz = '';
            } else {
                return false;
            }
        }

        if (defined('VERSION_PATCHLEVEL') && ctype_digit((string) VERSION_PATCHLEVEL)) {
            $name = preg_replace('#^(.+)\.js$#', '$1-' . VERSION_PATCHLEVEL . '.js', $name);
        }

        return $this->getView()->basePath($name . $gz);
    }
}
