<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * Scope: Gherkin / BDD (.feature) support.
 *
 * Bidirectional bridge between Gherkin feature files and TestLink test cases:
 *   - parse()                  : .feature text  -> structured array
 *   - featureToTestSpecXML()   : .feature text  -> TestLink native <testcases> XML
 *   - testCaseToFeature()      : test case data -> .feature text
 *
 * Mapping convention (Given/When/Then <-> TestLink fields):
 *   Background + Given block -> preconditions
 *   When block               -> step actions
 *   Then block               -> step expected results
 * A Scenario Outline is expanded: one test case per Examples row.
 *
 * @filesource  gherkin.class.php
 * @package     TestLink
 */
class gherkin
{
  /** Primary Gherkin step keywords (English). */
  private static $primaryKeywords = array('Given', 'When', 'Then');
  /** Keywords that continue the previous primary keyword's block. */
  private static $continuationKeywords = array('And', 'But', '*');

  /**
   * Parse Gherkin text into a structured representation.
   *
   * @param string $text raw .feature content
   * @return array feature structure:
   *   array('name','description','tags','background'(steps),'scenarios'=>array(
   *      array('name','description','tags','outline'(bool),'steps','examples')))
   */
  public static function parse($text)
  {
    $feature = array('name' => '', 'description' => '', 'tags' => array(),
                     'background' => array(), 'scenarios' => array());

    $lines = preg_split("/\r\n|\n|\r/", (string)$text);
    $pendingTags = array();
    $context = 'feature';        // feature | background | scenario | examples
    $scenarioIdx = -1;
    $inDocString = false;
    $docDelimiter = '';
    $descLines = array();

    $lineCount = count($lines);
    for ($i = 0; $i < $lineCount; $i++) {
      $raw = $lines[$i];
      $line = trim($raw);

      // DocString handling ("""/```): capture verbatim into current step text.
      if ($inDocString) {
        if ($line === $docDelimiter) {
          $inDocString = false;
        } else {
          self::appendToCurrentStep($feature, $context, $scenarioIdx, $raw);
        }
        continue;
      }
      if ($line === '"""' || $line === '```') {
        $inDocString = true;
        $docDelimiter = $line;
        continue;
      }

      if ($line === '') {
        continue;
      }
      if (strpos($line, '#') === 0) {
        continue; // comment
      }
      if (strpos($line, '@') === 0) {
        $pendingTags = array_merge($pendingTags, self::parseTags($line));
        continue;
      }

      // Data table row.
      if (strpos($line, '|') === 0) {
        $cells = self::parseTableRow($line);
        if ($context === 'examples' && $scenarioIdx >= 0) {
          $ex =& $feature['scenarios'][$scenarioIdx]['examples'];
          if (empty($ex['headers'])) {
            $ex['headers'] = $cells;
          } else {
            $ex['rows'][] = $cells;
          }
          unset($ex);
        } else {
          // table attached to a step
          self::appendToCurrentStep($feature, $context, $scenarioIdx, $line);
        }
        continue;
      }

      if (self::startsWithKeyword($line, 'Feature:')) {
        $feature['name'] = trim(substr($line, strlen('Feature:')));
        $feature['tags'] = $pendingTags;
        $pendingTags = array();
        $context = 'feature';
        $descLines = array();
        continue;
      }

      if (self::startsWithKeyword($line, 'Background:')) {
        $context = 'background';
        continue;
      }

      $scenarioKw = self::matchScenarioKeyword($line);
      if ($scenarioKw !== null) {
        $scenarioIdx++;
        $feature['scenarios'][$scenarioIdx] = array(
          'name' => trim(substr($line, strlen($scenarioKw))),
          'description' => '',
          'tags' => $pendingTags,
          'outline' => ($scenarioKw === 'Scenario Outline:' || $scenarioKw === 'Scenario Template:'),
          'steps' => array(),
          'examples' => array('headers' => array(), 'rows' => array()));
        $pendingTags = array();
        $context = 'scenario';
        continue;
      }

      if (self::startsWithKeyword($line, 'Examples:') ||
          self::startsWithKeyword($line, 'Scenarios:')) {
        $context = 'examples';
        continue;
      }

      // Step line?
      $stepKw = self::matchStepKeyword($line);
      if ($stepKw !== null) {
        $stepText = trim(substr($line, strlen($stepKw)));
        $step = array('keyword' => $stepKw, 'text' => $stepText);
        if ($context === 'background') {
          $feature['background'][] = $step;
        } elseif ($scenarioIdx >= 0) {
          $feature['scenarios'][$scenarioIdx]['steps'][] = $step;
        }
        continue;
      }

      // Free text: feature or scenario description.
      if ($context === 'feature') {
        $feature['description'] = trim($feature['description'] . "\n" . $line);
      } elseif ($context === 'scenario' && $scenarioIdx >= 0) {
        $sc =& $feature['scenarios'][$scenarioIdx];
        $sc['description'] = trim($sc['description'] . "\n" . $line);
        unset($sc);
      }
    }

    return $feature;
  }

  /**
   * Convert a .feature file content into TestLink native test specification XML.
   * Root element is <testcases>, ready for importTestCaseDataFromXML().
   *
   * @param string $text raw .feature content
   * @return string XML string
   */
  public static function featureToTestSpecXML($text)
  {
    $feature = self::parse($text);
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<testcases>\n";

    foreach ($feature['scenarios'] as $scenario) {
      $expanded = self::expandScenario($scenario);
      foreach ($expanded as $tc) {
        $xml .= self::scenarioToTestCaseXML($feature, $tc);
      }
    }

    $xml .= "</testcases>\n";
    return $xml;
  }

  /**
   * Build a Gherkin .feature representation of one or more TestLink test cases.
   *
   * @param array $testCases each: array('name','summary','preconditions','steps')
   *        where steps is an array of array('actions','expected_results')
   * @param string $featureName name for the Feature: header
   * @param string $featureDescription optional description block
   * @return string .feature text
   */
  public static function testCasesToFeature($testCases, $featureName, $featureDescription = '')
  {
    $out = 'Feature: ' . self::toPlainText($featureName) . "\n";
    $desc = self::toPlainText($featureDescription);
    if ($desc !== '') {
      foreach (preg_split("/\n/", $desc) as $dl) {
        $out .= '  ' . $dl . "\n";
      }
    }
    $out .= "\n";

    foreach ($testCases as $tc) {
      $out .= self::testCaseToScenario($tc);
      $out .= "\n";
    }
    return rtrim($out, "\n") . "\n";
  }

  /**
   * Convenience wrapper for a single test case.
   */
  public static function testCaseToFeature($name, $summary, $preconditions, $steps,
                                           $featureName = null)
  {
    $featureName = ($featureName === null) ? $name : $featureName;
    $tc = array('name' => $name, 'summary' => $summary,
                'preconditions' => $preconditions, 'steps' => $steps);
    return self::testCasesToFeature(array($tc), $featureName, $summary);
  }

  // ----------------------------------------------------------------------------
  // Internal helpers
  // ----------------------------------------------------------------------------

  /**
   * Expand a parsed scenario into one or more concrete test cases.
   * A plain Scenario yields one; a Scenario Outline yields one per Examples row.
   */
  private static function expandScenario($scenario)
  {
    if (!$scenario['outline'] || empty($scenario['examples']['headers'])) {
      return array(array('name' => $scenario['name'],
                         'description' => $scenario['description'],
                         'tags' => $scenario['tags'],
                         'steps' => $scenario['steps']));
    }

    $result = array();
    $headers = $scenario['examples']['headers'];
    foreach ($scenario['examples']['rows'] as $row) {
      $repl = array();
      foreach ($headers as $ci => $h) {
        $repl['<' . $h . '>'] = isset($row[$ci]) ? $row[$ci] : '';
      }
      $steps = array();
      foreach ($scenario['steps'] as $st) {
        $steps[] = array('keyword' => $st['keyword'],
                         'text' => strtr($st['text'], $repl));
      }
      $suffix = array();
      foreach ($headers as $ci => $h) {
        $suffix[] = $h . '=' . (isset($row[$ci]) ? $row[$ci] : '');
      }
      $result[] = array('name' => $scenario['name'] . ' [' . implode(', ', $suffix) . ']',
                        'description' => $scenario['description'],
                        'tags' => $scenario['tags'],
                        'steps' => $steps);
    }
    return $result;
  }

  /**
   * Render one concrete (expanded) scenario as a TestLink <testcase> element.
   */
  private static function scenarioToTestCaseXML($feature, $tc)
  {
    // Classify steps into Given/When/Then blocks following Gherkin semantics:
    // And/But/* continue the previous primary keyword.
    $given = array();
    $when = array();
    $then = array();
    $current = 'Given';

    foreach ($feature['background'] as $st) {
      $given[] = self::renderStepLine($st);
    }
    foreach ($tc['steps'] as $st) {
      if (in_array($st['keyword'], self::$primaryKeywords, true)) {
        $current = $st['keyword'];
      }
      $line = self::renderStepLine($st);
      if ($current === 'Given') {
        $given[] = $line;
      } elseif ($current === 'When') {
        $when[] = $line;
      } else {
        $then[] = $line;
      }
    }

    $summaryParts = array();
    if ($feature['name'] !== '') {
      $summaryParts[] = 'Feature: ' . $feature['name'];
    }
    if (trim($feature['description']) !== '') {
      $summaryParts[] = $feature['description'];
    }
    if (trim($tc['description']) !== '') {
      $summaryParts[] = $tc['description'];
    }
    if (!empty($tc['tags'])) {
      $summaryParts[] = 'Tags: ' . implode(' ', $tc['tags']);
    }
    $summary = implode("\n", $summaryParts);

    $name = $tc['name'] !== '' ? $tc['name'] : 'Scenario';

    $xml  = "  <testcase name=\"" . self::xmlAttr($name) . "\">\n";
    $xml .= "    <summary>" . self::cdata(self::toHtml($summary)) . "</summary>\n";
    $xml .= "    <preconditions>" . self::cdata(self::toHtml(implode("\n", $given))) .
            "</preconditions>\n";
    $xml .= "    <execution_type><![CDATA[1]]></execution_type>\n";
    $xml .= "    <importance><![CDATA[2]]></importance>\n";
    $xml .= "    <steps>\n";
    $xml .= "      <step>\n";
    $xml .= "        <step_number><![CDATA[1]]></step_number>\n";
    $xml .= "        <actions>" . self::cdata(self::toHtml(implode("\n", $when))) . "</actions>\n";
    $xml .= "        <expectedresults>" . self::cdata(self::toHtml(implode("\n", $then))) .
            "</expectedresults>\n";
    $xml .= "        <execution_type><![CDATA[1]]></execution_type>\n";
    $xml .= "      </step>\n";
    $xml .= "    </steps>\n";
    $xml .= "  </testcase>\n";
    return $xml;
  }

  /**
   * Build a Scenario block from a TestLink test case.
   */
  private static function testCaseToScenario($tc)
  {
    $name = self::toPlainText(isset($tc['name']) ? $tc['name'] : '');
    $out = '  Scenario: ' . $name . "\n";

    $pre = self::toPlainText(isset($tc['preconditions']) ? $tc['preconditions'] : '');
    $out .= self::renderBlockLines($pre, 'Given');

    $steps = isset($tc['steps']) && is_array($tc['steps']) ? $tc['steps'] : array();
    foreach ($steps as $st) {
      $actions = self::toPlainText(isset($st['actions']) ? $st['actions'] : '');
      $expected = self::toPlainText(isset($st['expected_results']) ? $st['expected_results'] : '');
      $out .= self::renderBlockLines($actions, 'When');
      $out .= self::renderBlockLines($expected, 'Then');
    }
    return $out;
  }

  /**
   * Render a multi-line text block as Gherkin step lines.
   * The first line gets $primary; subsequent lines get 'And'. Lines that already
   * begin with a Gherkin keyword are emitted verbatim (clean round-trip).
   */
  private static function renderBlockLines($text, $primary)
  {
    $text = trim($text);
    if ($text === '') {
      return '';
    }
    $out = '';
    $first = true;
    foreach (preg_split("/\n/", $text) as $line) {
      $line = trim($line);
      if ($line === '') {
        continue;
      }
      if (self::matchStepKeyword($line) !== null) {
        $out .= '    ' . $line . "\n";
      } else {
        $out .= '    ' . ($first ? $primary : 'And') . ' ' . $line . "\n";
      }
      $first = false;
    }
    return $out;
  }

  private static function renderStepLine($step)
  {
    return $step['keyword'] . ' ' . $step['text'];
  }

  private static function parseTags($line)
  {
    $tags = array();
    foreach (preg_split('/\s+/', trim($line)) as $t) {
      if ($t !== '' && strpos($t, '@') === 0) {
        $tags[] = $t;
      }
    }
    return $tags;
  }

  private static function parseTableRow($line)
  {
    $line = trim($line);
    $line = preg_replace('/^\|/', '', $line);
    $line = preg_replace('/\|$/', '', $line);
    $cells = explode('|', $line);
    foreach ($cells as &$c) {
      $c = trim($c);
    }
    unset($c);
    return $cells;
  }

  private static function startsWithKeyword($line, $kw)
  {
    return strncasecmp($line, $kw, strlen($kw)) === 0;
  }

  private static function matchScenarioKeyword($line)
  {
    foreach (array('Scenario Outline:', 'Scenario Template:', 'Scenario:', 'Example:') as $kw) {
      if (self::startsWithKeyword($line, $kw)) {
        return $kw;
      }
    }
    return null;
  }

  /**
   * Return the canonical step keyword if $line begins with one, else null.
   */
  private static function matchStepKeyword($line)
  {
    foreach (array_merge(self::$primaryKeywords, array('And', 'But')) as $kw) {
      if (strncasecmp($line, $kw . ' ', strlen($kw) + 1) === 0) {
        return $kw;
      }
    }
    if (strncmp($line, '* ', 2) === 0) {
      return '*';
    }
    return null;
  }

  private static function appendToCurrentStep(&$feature, $context, $scenarioIdx, $text)
  {
    if ($context === 'background') {
      $n = count($feature['background']);
      if ($n > 0) {
        $feature['background'][$n - 1]['text'] .= "\n" . $text;
      }
    } elseif ($scenarioIdx >= 0) {
      $steps =& $feature['scenarios'][$scenarioIdx]['steps'];
      $n = count($steps);
      if ($n > 0) {
        $steps[$n - 1]['text'] .= "\n" . $text;
      }
      unset($steps);
    }
  }

  /** Plain text -> HTML for storage in TestLink rich-text fields. */
  private static function toHtml($text)
  {
    $text = htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    return nl2br($text, false);
  }

  /** TestLink rich-text (HTML) -> plain text for .feature output. */
  private static function toPlainText($html)
  {
    $s = (string)$html;
    $s = preg_replace('#<br\s*/?>#i', "\n", $s);
    $s = preg_replace('#</(p|div|li|tr)>#i', "\n", $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    // collapse trailing spaces, normalize newlines
    $s = preg_replace("/[ \t]+\n/", "\n", $s);
    return trim($s);
  }

  private static function cdata($content)
  {
    $content = str_replace(']]>', ']]&gt;', (string)$content);
    return '<![CDATA[' . $content . ']]>';
  }

  private static function xmlAttr($value)
  {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }
}
