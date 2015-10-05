<?php
/**
 * Smileys v1.2.1
 *
 * This plugin substitutes text emoticons, also known as smilies
 * like :-), with images.
 *
 * Dual licensed under the MIT or GPL Version 3 licenses, see LICENSE.
 * http://benjamin-regler.de/license/
 *
 * @package     Smileys
 * @version     1.2.1
 * @link        <https://github.com/sommerregen/grav-plugin-smileys>
 * @author      Benjamin Regler <sommerregen@benjamin-regler.de>
 * @copyright   2015, Benjamin Regler
 * @license     <http://opensource.org/licenses/MIT>        MIT
 * @license     <http://opensource.org/licenses/GPL-3.0>    GPLv3
 */

namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\GravTrait;
use Grav\Common\Page\Page;
use Grav\Common\Filesystem\Folder;
use RocketTheme\Toolbox\Event\Event;
use Grav\Common\File\CompiledYamlFile;

/**
 * Smileys
 *
 * This plugin substitutes text emoticons, also known as smilies
 * like :-), with images.
 */
class SmileysPlugin extends Plugin
{
  /**
   * @var SmileysPlugin
   */

  /** ---------------------------
   * Private/protected properties
   * ----------------------------
   */

  /**
   * Instance of Smileys class
   *
   * @var object
   */
  protected $smileys;

  /** -------------
   * Public methods
   * --------------
   */

  /**
   * Return a list of subscribed events.
   *
   * @return array    The list of events of the plugin of the form
   *                      'name' => ['method_name', priority].
   */
  public static function getSubscribedEvents()
  {
    return [
      'onPluginsInitialized' => ['onPluginsInitialized', 0]
    ];
  }

  /**
   * Initialize configuration.
   */
  public function onPluginsInitialized()
  {
    if ($this->isAdmin()) {
      $this->active = false;
      return;
    }

    if ($this->config->get('plugins.smileys.enabled')) {
      // Process contents order according to weight option
      $weight = $this->config->get('plugins.smileys.weight');

      $this->enable([
        'onPageContentProcessed' => ['onPageContentProcessed', $weight],
        'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
      ]);
    }
  }

  /**
   * Apply smileys filter to content, when each page has not been
   * cached yet.
   *
   * @param  Event  $event The event when 'onPageContentProcessed' was
   *                       fired.
   */
  public function onPageContentProcessed(Event $event)
  {
    /** @var Page $page */
    $page = $event['page'];

    $config = $this->mergeConfig($page);
    if ($config->get('process', false) && $this->compileOnce($page)) {
      // Get content and list of exclude tags
      $content = $page->getRawContent();
      $exclude = $config->get('exclude');

      // Substitute smileys by their respective icons and save modified
      // page content
      $page->setRawContent(
        $this->init()->process($content, $exclude)
      );
    }
  }

  /**
   * Set needed variables to display drop caps.
   */
  public function onTwigSiteVariables()
  {
    if ($this->config->get('plugins.smileys.built_in_css')) {
      $this->grav['assets']->add('plugin://smileys/assets/css/smileys.css');
    }
  }

  /**
   * Install plugin and initialize configurations.
   */
  static public function onPluginInstalled() {
    /** @var Grav $grav */
    $grav = GravTrait::getGrav();

    // Resolve path of default smiley package and smileys data path
    $locator = $grav['locator'];
    if (!($path = $locator->findResource('user://data/smileys'))) {
      $path = $locator->findResource('user://data') . DS . 'smileys';
      mkdir($path, 0775, true);

      // Copy contents to user data folder
      $packs = $locator->findResource('plugin://smileys/assets/packs');
      Folder::rcopy($packs, $path);
    }
  }

  /**
   * Get installed Smileys packages
   *
   * @return array An array of installed and available Smileys packages.
   */
  static public function getSmileyPacks()
  {
    // Resolve path of default smiley package and smileys data path
    $locator = GravTrait::getGrav()['locator'];

    // Path checks
    if (!($path = $locator->findResource('user://data/smileys'))) {
      $path = $locator->findResource('user://data') . DS . 'smileys';
      self::onPluginInstalled();
    }

    $packs = [];
    // Load all smileys from path
    $iterator = new \FilesystemIterator($path);
    foreach ($iterator as $object) {
      if ($object->isDir()) {
        $name = $text = $object->getBasename();

        // Load name of Smiley package
        $file = $object->getRealPath() . DS . $name . YAML_EXT;
        if (file_exists($file)) {
          $config = CompiledYamlFile::instance($file)->content();
          $text = isset($config['name']) ? $config['name'] : $name;
        }
        $packs[$name] = $text;
      }
    }

    return $packs;
  }

  /** -------------------------------
   * Private/protected helper methods
   * --------------------------------
   */

  /**
   * Checks if a page has already been compiled yet.
   *
   * @param  Page    $page The page to check
   * @return boolean       Returns true if page has already been
   *                       compiled yet, false otherwise
   */
  protected function compileOnce(Page $page)
  {
    static $processed = [];

    $id = md5($page->path());
    // Make sure that contents is only processed once
    if (!isset($processed[$id]) || ($processed[$id] < $page->modified())) {
      $processed[$id] = $page->modified();
      return true;
    }

    return false;
  }

  /**
   * Initialize plugin and all dependencies.
   *
   * @return \Grav\Plugin\ExternalLinks   Returns ExternalLinks instance.
   */
  protected function init()
  {
    if (!$this->smileys) {
      // Get smiley package
      $package = $this->config->get('plugins.smileys.pack');

      // Check if smiley package was properly installed in 'user/data/smileys'
      $locator = $this->grav['locator'];
      $smileys_path = $locator->findResource('user://data/smileys');

      // Call onPluginInstalled when user data smiley folder can not be found
      if (!$smileys_path) {
        self::onPluginInstalled();
      }

      // Check if package exists, if not fall-back to default smiley package
      $path = $smileys_path . DS . $package;
      if (!file_exists($path)) {
        $path = $smileys_path . DS . 'simple_smileys';
      }

      // Load and initialize Smileys class
      require_once(__DIR__ . '/classes/Smileys.php');
      $this->smileys = new Smileys($package, $path);
    }

    return $this->smileys;
  }
}
