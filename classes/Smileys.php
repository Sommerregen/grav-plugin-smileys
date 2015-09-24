<?php
/**
 * Smileys
 *
 * This file is part of Grav Smileys plugin.
 *
 * Dual licensed under the MIT or GPL Version 3 licenses, see LICENSE.
 * http://benjamin-regler.de/license/
 */

namespace Grav\Plugin;

use Grav\Common\GravTrait;
use Grav\Common\Data\Data;
use Grav\Common\Page\Media;
use Grav\Common\File\CompiledYamlFile;

/**
 * Smileys
 *
 * Helper class to substitute text emoticons, also known as smilies
 * like :-), with images.
 */
class Smileys {
  /**
   * @var Smileys
   */
  use GravTrait;

  /** ---------------------------
   * Private/protected properties
   * ----------------------------
   */

  /**
   * A key-valued array of all smileys and their acronyms
   *
   * @var array
   */
  public $smileys;

  /**
   * A regular expression to match any smiley in $smileys array
   *
   * @var string
   */
  public $regex;

  /** -------------
   * Public methods
   * --------------
   */

  /**
   * Initialize Smileys class
   *
   * @param  string $package The name of the smiley package
   *
   * @param  string $path    The path to the smiley package
   */
  public function __construct($package, $path)
  {
    $grav = static::getGrav();

    /** @var Cache $cache */
    $cache = $grav['cache'];

    /** @var Debugger $debugger */
    $debugger = $grav['debugger'];

    // Get cache id and try to fetch data
    $id = @filemtime($path . DS . $package . YAML_EXT);
    $cache_id = md5('smileys' . $id . $package . $cache->getKey());
    $data = $cache->fetch($cache_id);

    if ($data === false) {
      $debugger->addMessage("Smileys Plugin cache miss. Rebuilding...");

      // Load smileys from package
      $data = $this->load($package, $path);
      $cache->save($cache_id, $data);
    } else {
      $debugger->addMessage("Smileys Plugin cache hit.");
    }

    // Unpack regex and smileys from cached data
    list($this->regex, $this->smileys) = $data;
  }

  /**
   * Process contents i.e. replace any smiley acronyms by their
   * respective smiley images
   *
   * @param  string $content The content to be processed
   * @param  array  $exclude Array of tags within smileys will be
   *                         ignored
   *
   * @return string          The processed content
   */
  public function process($content, $exclude = [])
  {
    // Return contents on empty smileys array
    if (count($this->smileys) == 0) {
      return $content;
    }

    // Load PHP built-in DOMDocument class
    if (($dom = $this->loadDOMDocument($content)) === null) {
      return $content;
    }

    // Create a DOM XPath object
    $xpath = new \DOMXPath($dom);

    // Get all text nodes of DOM
    $textnodes = $xpath->evaluate('//text()');

    // Run through each text node and replace any smiley acronyms by
    // their respective smiley images
    $replace = [];
    foreach ($textnodes as $node) {
      // Ignore text inside specific tags i.e. <code> and <pre>
      if ($this->skipNode($node, $exclude)) {
        continue;
      }

      // Encode HTML entities and replace acronyms by their respective
      // smiley images, make sure that content is UTF-8 and entities
      // properly encoded
      $convmap = array(0x80, 0xffff, 0, 0xffff);
      $original = htmlspecialchars($node->textContent);
      $original = mb_encode_numericentity($original, $convmap, 'UTF-8');

      $text = preg_replace_callback($this->regex, [$this, 'getSmiley'], $original);

      // Save processed text
      $replace[$original] = $text;
    }

    // Perform replacement of text
    $content = str_replace(array_keys($replace), $replace, $content);
    return $content;
  }

  /** -------------------------------
   * Private/protected helper methods
   * --------------------------------
   */

  /**
   * Load smiley package into plugin.
   *
   * @param  string $package The name of the smiley package
   * @param  string $path    The path to the smiley package
   *
   * @return array           Returns the regular expressions and all
   *                         active smileys as an array
   */
  protected function load($package, $path)
  {
    // Don't try load something, which does not exits
    if (!file_exists($path)) {
      return array('', []);
    }

    // Load YAML file
    $file = $path . DS . $package . YAML_EXT;
    $config = CompiledYamlFile::instance($file);
    $config = new Data($config->content());

    // Consider all images
    $ext = ['png', 'gif', 'bmp', 'tif', 'tiff', 'jpg', 'jpeg', 'svg'];
    $default = [];

    // By default if `items: @all` is set, add all images to smiley list

    if ($items = $config->get('items')) {
      if ($items == '@all') {
        $default = [
          'enabled' => true,
          'acronyms' => '',
          'description' => ''
        ];
      } else {
        $ext = $items;
      }
    }

    // Load smileys, its acronyms and their descriptions
    $media = new Media($path);
    $smileys = [];

    /** @var Grav\Common\Page\Media $media */
    foreach ($media->images() as $image) {
      // Get basename (without extension) of image
      $name = pathinfo($image->get('filename'), PATHINFO_FILENAME);
      $smiley = $config->get('smileys.' . $name, $default);
      $smiley += $default;

      // Filter out files, disabled smileys and smiley icons not in
      // list of valid image extensions
      if (!in_array($image->get('extension'), $ext) || !$smiley['enabled']) {
        continue;
      }

      // Add smiley name as acronym like :name: where name is the
      // filename of the smiley
      $acronyms = array_filter(explode(' ', $smiley['acronyms']));
      $acronyms[] = ":$name:";

      foreach ($acronyms as $acronym) {
        // Escape acronym for image title attribute
        $acronym_escaped = htmlspecialchars($acronym, ENT_COMPAT | ENT_HTML401, 'UTF-8');

        // Set "alt" description and "title" attributes of image
        $title = ltrim($smiley['description'] . " \x1Aacronym_escaped\x1A");
        $html = $image->html($title, $acronym_escaped, 'smileys', false);

        // Store acronym
        $acronym = strtolower($acronym);
        $smileys[$acronym] = array(
          'acronym' => $acronym_escaped, 'html' => $html,
        );

        // Eventually auto-escape forbidden characters for the user
        if ($acronym != $acronym_escaped) {
          $smileys[$acronym_escaped] = $smileys[$acronym];
        }
      }
    }

    // Create regex based on smileys set
    $regex = $this->getRegex($smileys);

    // Return regex and smileys as one data set
    return array($regex, $smileys);
  }

  /**
   * Get regular expression based on set of smileys.
   *
   * @param  array  $smileys The smileys as an key-valued array
   *
   * @return string          The regular expression which will match any
   *                         smileys from $smileys.
   */
  protected function getRegex($smileys)
  {
    // Do nothing on empty smileys array
    if (count($smileys) == 0) {
      return '';
    }

    // NOTE: we sort the smilies in reverse key order. This is to make
    // sure we match the longest possible smilie (:???: vs :?) as the
    // regular expression used below is first-match
    krsort($smileys);

    $subchar = '';
    $regex = '/(?:\s|^)';
    // Run through all smileys codes
    foreach ($smileys as $acronym => $smiley) {
      $firstchar = substr($acronym, 0, 1);
      $rest = substr($acronym, 1);

      // Check of new subpattern
      if ($firstchar != $subchar) {
        if ($subchar != '') {
          $regex .= ')(?=\p{P}?(?:\s|$))|(?:\s|^)';
        }

        $subchar = $firstchar;
        $regex .= preg_quote($firstchar, '/') . '(?:';
      } else {
        $regex .= '|';
      }

      $regex .= preg_quote($rest, '/');
    }

    $regex .= ')(?=\p{P}?(?:\s|$))/imS';
    return $regex;
  }

  /**
   * Checks if a node is a children of nodes which should be excluded
   *
   * @param  DOMElement $node         A node from the DOMDocument
   * @param  array      $exclude      Array of tags and classes to be excluded
   *
   * @return boolean                  Returns true, if node should be
   *                                  skipped, false otherwise.
   */
  protected function skipNode($node, $exclude = [])
  {
    // Get owner document of node
    $body = $node->ownerDocument;

    // Check if node has specific class
    if ($node->parentNode->hasAttribute('class')) {
      $class = $node->parentNode->getAttribute('class');
      $classes = array_filter(explode(' ', $class));
      if (!!array_intersect($classes, $exclude['classes'])) {
        return true;
      }
    }

    // Perform loop till node equals its owner document
    while ($node !== $body) {
      if (in_array($node->nodeName, $exclude['tags'])) {
        // We found a tag matching one in $exclude_tags
        return true;
      }
      // Investigate parent of node
      $node = $node->parentNode;
    }

    // The node definitely should not be excluded
    return false;
  }

  /**
   * Convert one smiley code to the icon graphic file equivalent.
   *
   * @param  array  $matches An array. The first item should hold the
   *                         acronym
   *
   * @return string          A string to be returned
   */
  protected function getSmiley($matches)
  {
    $acronym = trim($matches[0]);
    $smiley = $this->smileys[strtolower($acronym)];
    $image = str_replace("\x1Aacronym_escaped\x1A", $smiley['acronym'],
      $smiley['html']);

    return str_ireplace($acronym, $image, $matches[0]);
  }

  /**
   * Load contents into PHP built-in DOMDocument object
   *
   * Two Really good resources to handle DOMDocument with HTML(5)
   * correctly.
   *
   * @see http://stackoverflow.com/questions/3577641/how-do-you-parse-and-process-html-xml-in-php
   * @see http://stackoverflow.com/questions/7997936/how-do-you-format-dom-structures-in-php
   *
   * @param  string      $content The content to be loaded into the
   *                              DOMDocument object
   *
   * @return DOMDocument          DOMDocument object of content
   */
  protected function loadDOMDocument($content)
  {
    // Clear previous errors
    if (libxml_use_internal_errors(true) === true) {
      libxml_clear_errors();
    }

    // Parse content using PHP built-in DOMDocument class
    $document = new \DOMDocument('1.0', 'UTF-8');

    // Encode contents as UTF-8, strip whitespaces & normalize newlines
    $content = mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8');

    // $whitespaces = array(
    //   '~\R~u' => "\n",         // Normalize new line
    //   '~\>[^\S ]+~s' => '>',   // Strip whitespaces after tags, except space
    //   '~[^\S ]+\<~s' => '<',   // Strip whitespaces before tags, except space
    //   '~(\s)+~s' => '\\1'      // Shorten multiple whitespace sequences
    // );
    // $content = preg_replace(array_keys($whitespaces), $whitespaces, $content);

    // Parse the HTML using UTF-8
    // The @ before the method call suppresses any warnings that
    // loadHTML might throw because of invalid HTML in the page.
    @$document->loadHTML($content);

    // Do nothing, if DOM is empty
    if (is_null($document->documentElement)) {
      return null;
    }

    return $document;
  }
}
