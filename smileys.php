<?php
/**
 * Smileys v1.0.0
 *
 * This plugin substitutes text emoticons, also known as smilies
 * like :-), with images.
 *
 * Licensed under MIT, see LICENSE.
 *
 * @package     Smileys
 * @version     1.0.0
 * @link        <https://github.com/sommerregen/grav-plugin-smileys>
 * @author      Benjamin Regler <sommerregen@benjamin-regler.de>
 * @copyright   2015, Benjamin Regler
 * @license     <http://opensource.org/licenses/MIT>            MIT
 */

namespace Grav\Plugin;

use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use RocketTheme\Toolbox\Event\Event;

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
      'onPluginsInitialized' => ['onPluginsInitialized', 0],
    ];
  }

  /**
   * Install plugin and initialize configurations.
   */
  public function onPluginInstalled() {
    /** @var Debugger $debugger */
    $debugger = $this->grav['debugger'];

    // Add debug informations of plugin install action
    $debugger->addMessage("Smileys folder `user/data/smileys` not found. Creating...");

    // Resolve path of default smiley package and smileys data path
    $locator = $this->grav['locator'];
    $data_path = $locator->findResource('user://data');
    $pack_path = $locator->findResource('plugin://smileys/assets/packs');

    // Copy contents to user data folder
    Utils::rCopy($pack_path, $data_path.DS.'smileys');
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
      // Get smiley package
      $package = $this->config->get('plugins.smileys.pack');

      // Check if smiley package was properly installed in 'user/data/smileys'
      $locator = $this->grav['locator'];
      $smileys_path = $locator->findResource('user://data/smileys');

      // Call onPluginInstalled when user data smiley folder can not be found
      if (!$smileys_path) {
        $this->onPluginInstalled();
      }

      // Check if package exists, if not fall-back to default smiley package
      $path = $smileys_path.DS.$package;
      if (!file_exists($path)) {
        $path = $smileys_path.DS.'simple_smileys';
      }

      // Load Smileys class
      require_once(__DIR__.'/classes/Smileys.php');
      $this->smileys = new Smileys($package, $path);

      // Process contents order according to weight option
      $weight = $this->config->get('plugins.smileys.weight');

      $this->enable([
        'onPageContentProcessed' => ['onPageContentProcessed', $weight],
        'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
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
        $this->smileys->process($content, $exclude)
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
}
