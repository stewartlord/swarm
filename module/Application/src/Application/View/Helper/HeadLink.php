<?php
/**
 * Perforce Swarm
 *
 * @copyright   2013-2016 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2016.1/1400259
 */

namespace Application\View\Helper;

use Zend\View\Helper\HeadLink as ZendHeadLink;

class HeadLink extends ZendHeadLink
{
    protected $customAdded = false;

    /**
     * Retrieve string representation
     * Extends parent to add in custom styles before going to string.
     *
     * @param  string|int   $indent     Amount of whitespaces or string to use for indention
     * @return string       the head script(s)
     */
    public function toString($indent = null)
    {
        // if we haven't already added the custom stylesheets do so now
        if (!$this->customAdded) {
            $this->customAdded = true;

            // get the base path as we'll need it later
            $services = $this->getView()->getHelperPluginManager()->getServiceLocator();
            $config   = $services->get('config') + array('css' => array());
            $dev      = isset($config['environment']['mode']) && $config['environment']['mode'] == 'development';
            $accept   = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '';
            $gz       = strpos($accept, 'gzip') !== false ? 'gz' : '';
            $basePath = $services->get('viewhelpermanager')->get('basePath')->__invoke();

            // first deal with config declared css
            foreach (array_reverse($config['css']) as $key => $value) {
                // if key is a string, its a 'build', use that if we aren't in dev mode and it exists
                if (is_string($key) && !$dev && is_file(BASE_PATH . '/public' . $key . $gz)) {
                    // if the file is under build; mix in the patch level (assuming we have one)
                    // this serves as a 'cache-buster' to ensure browsers take upgraded versions
                    if (defined('VERSION_PATCHLEVEL') && ctype_digit((string) VERSION_PATCHLEVEL)) {
                        $key = preg_replace(
                            '#^(/build/.+)\.css$#',
                            '$1-' . VERSION_PATCHLEVEL . '.css',
                            $key
                        );
                    }

                    $this->prependStylesheet($basePath . $key . $gz, 'all');
                    continue;
                }

                // we're not using a build so add all 'value' css scripts
                $value = array_reverse((array) $value);
                foreach ($value as $script) {
                    $this->prependStylesheet($basePath .  $script, 'all');
                }
            }

            // find any custom css to be added
            $files = array_merge(
                glob(BASE_PATH . '/public/custom/*.css'),
                glob(BASE_PATH . '/public/custom/*/*.css')
            );

            // sort the files to ensure a predictable order
            natcasesort($files);

            foreach ($files as $file) {
                $custom = substr($file, strlen(BASE_PATH . '/public/'));
                $this->appendStylesheet($basePath . '/' . $custom, 'all');
            }
        }

        return parent::toString($indent);
    }
}
