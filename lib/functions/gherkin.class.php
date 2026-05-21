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
 *   - testCasesToFeature()     : test cases     -> .feature text
 *
 * Mapping convention (Given/When/Then <-> TestLink fields):
 *   Background + Given block -> preconditions
 *   When block               -> step actions
 *   Then block               -> step expected results
 * A Scenario Outline is expanded: one test case per Examples row.
 *
 * Localized feature files are supported through a "# language: xx" header
 * (Gherkin i18n). Keywords are matched against the declared language and
 * mapped back to the canonical Given/When/Then categories.
 *
 * @filesource  gherkin.class.php
 * @package     TestLink
 */
class gherkin
{
  /**
   * Localized keyword dictionary (Gherkin i18n subset).
   * Categories: feature, background, outline, scenario, examples and the
   * step keywords given/when/then/and/but.
   */
  private static $i18n = array(
    'en' => array(
      'feature' => array('Feature'),
      'background' => array('Background'),
      'outline' => array('Scenario Outline', 'Scenario Template'),
      'scenario' => array('Scenario', 'Example'),
      'examples' => array('Examples', 'Scenarios'),
      'given' => array('Given'), 'when' => array('When'), 'then' => array('Then'),
      'and' => array('And'), 'but' => array('But')),
    'fr' => array(
      'feature' => array('Fonctionnalité'),
      'background' => array('Contexte'),
      'outline' => array('Plan du scénario', 'Plan du Scénario'),
      'scenario' => array('Scénario', 'Exemple'),
      'examples' => array('Exemples'),
      'given' => array('Soit', 'Sachant que', 'Étant donné', 'Etant donné'),
      'when' => array('Quand', 'Lorsque', "Lorsqu'"),
      'then' => array('Alors'), 'and' => array('Et'), 'but' => array('Mais')),
    'de' => array(
      'feature' => array('Funktionalität', 'Funktion'),
      'background' => array('Grundlage', 'Hintergrund'),
      'outline' => array('Szenariogrundriss'),
      'scenario' => array('Szenario', 'Beispiel'),
      'examples' => array('Beispiele'),
      'given' => array('Angenommen', 'Gegeben sei'),
      'when' => array('Wenn'), 'then' => array('Dann'),
      'and' => array('Und'), 'but' => array('Aber')),
    'es' => array(
      'feature' => array('Característica'),
      'background' => array('Antecedentes'),
      'outline' => array('Esquema del escenario'),
      'scenario' => array('Escenario', 'Ejemplo'),
      'examples' => array('Ejemplos'),
      'given' => array('Dado', 'Dada', 'Dados', 'Dadas'),
      'when' => array('Cuando'), 'then' => array('Entonces'),
      'and' => array('Y', 'E'), 'but' => array('Pero')),
    'pt' => array(
      'feature' => array('Funcionalidade', 'Característica'),
      'background' => array('Contexto', 'Cenário de Fundo'),
      'outline' => array('Esquema do Cenário'),
      'scenario' => array('Cenário', 'Cenario'),
      'examples' => array('Exemplos', 'Cenários'),
      'given' => array('Dado', 'Dada', 'Dados', 'Dadas'),
      'when' => array('Quando'), 'then' => array('Então', 'Entao'),
      'and' => array('E'), 'but' => array('Mas')),
    'zh-TW' => array(
      'feature' => array('功能'),
      'background' => array('背景'),
      'outline' => array('場景大綱', '劇本大綱'),
      'scenario' => array('場景', '劇本'),
      'examples' => array('例子'),
      'given' => array('假設', '假定', '假如'),
      'when' => array('當'), 'then' => array('那麼', '那么'),
      'and' => array('而且', '並且'), 'but' => array('但是')),
    'zh-CN' => array(
      'feature' => array('功能'),
      'background' => array('背景'),
      'outline' => array('场景大纲', '剧本大纲'),
      'scenario' => array('场景', '剧本'),
      'examples' => array('例子'),
      'given' => array('假如', '假设', '假定'),
      'when' => array('当'), 'then' => array('那么'),
      'and' => array('而且', '并且', '同时'), 'but' => array('但是')),
    'ja' => array(
      'feature' => array('フィーチャ', '機能'),
      'background' => array('背景'),
      'outline' => array('シナリオアウトライン', 'シナリオテンプレート'),
      'scenario' => array('シナリオ'),
      'examples' => array('例', 'サンプル'),
      'given' => array('前提'),
      'when' => array('もし'), 'then' => array('ならば'),
      'and' => array('かつ', 'しかも'), 'but' => array('しかし', '但し', 'ただし')),
  );

  /** Canonical primary categories (the others continue the previous one). */
  private static $primaryCategories = array('Given', 'When', 'Then');

  /**
   * Parse Gherkin text into a structured representation.
   *
   * @param string $text raw .feature content
   * @return array feature structure:
   *   array('name','description','tags','language','background'(steps),
   *         'scenarios'=>array(array('name','description','tags','outline',
   *                                  'steps','examples')))
   *   Each step: array('category'(Given|When|Then|And|But),'line').
   */
  public static function parse($text)
  {
    $lines = preg_split("/\r\n|\n|\r/", (string)$text);
    $lang = self::detectLanguage($lines);
    list($blockKw, $stepKw) = self::buildKeywordIndex($lang);

    $feature = array('name' => '', 'description' => '', 'tags' => array(),
                     'language' => $lang, 'background' => array(),
                     'scenarios' => array());

    $pendingTags = array();
    $context = 'feature';        // feature | background | scenario | examples
    $scenarioIdx = -1;
    $inDocString = false;
    $docDelimiter = '';

    $lineCount = count($lines);
    for ($i = 0; $i < $lineCount; $i++) {
      $raw = $lines[$i];
      $line = trim($raw);

      // DocString ("""/```): capture verbatim into the current step.
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
        continue; // comment (language header already consumed)
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
          self::appendToCurrentStep($feature, $context, $scenarioIdx, $line);
        }
        continue;
      }

      // Block keyword (Feature / Background / Scenario / Outline / Examples).
      $bm = self::matchKeyword($line, $blockKw);
      if ($bm !== null) {
        $remainder = ltrim($bm['remainder'], ": \t");
        switch ($bm['category']) {
          case 'feature':
            $feature['name'] = $remainder;
            $feature['tags'] = $pendingTags;
            $pendingTags = array();
            $context = 'feature';
            break;
          case 'background':
            $context = 'background';
            break;
          case 'outline':
          case 'scenario':
            $scenarioIdx++;
            $feature['scenarios'][$scenarioIdx] = array(
              'name' => $remainder, 'description' => '', 'tags' => $pendingTags,
              'outline' => ($bm['category'] === 'outline'),
              'steps' => array(),
              'examples' => array('headers' => array(), 'rows' => array()));
            $pendingTags = array();
            $context = 'scenario';
            break;
          case 'examples':
            $context = 'examples';
            break;
        }
        continue;
      }

      // Step keyword.
      $sm = self::matchKeyword($line, $stepKw);
      if ($sm !== null) {
        $step = array('category' => $sm['category'], 'line' => $line);
        if ($context === 'background') {
          $feature['background'][] = $step;
        } elseif ($scenarioIdx >= 0) {
          $feature['scenarios'][$scenarioIdx]['steps'][] = $step;
        }
        continue;
      }

      // Free text -> description.
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
   * Convert .feature content into TestLink native test specification XML.
   * Root element is <testcases>, ready for importTestCaseDataFromXML().
   */
  public static function featureToTestSpecXML($text)
  {
    $feature = self::parse($text);
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<testcases>\n";
    foreach ($feature['scenarios'] as $scenario) {
      foreach (self::expandScenario($scenario) as $tc) {
        $xml .= self::scenarioToTestCaseXML($feature, $tc);
      }
    }
    $xml .= "</testcases>\n";
    return $xml;
  }

  /**
   * Build a Gherkin .feature representation of one or more test cases.
   *
   * @param array  $testCases each: array('name','summary','preconditions','steps')
   *               steps: array of array('actions','expected_results')
   * @param string $featureName name for the Feature: header
   * @param string $featureDescription optional description block
   * @param string $lang output language code for keywords (default 'en')
   */
  public static function testCasesToFeature($testCases, $featureName,
                                            $featureDescription = '', $lang = 'en')
  {
    $kw = isset(self::$i18n[$lang]) ? $lang : 'en';
    $out = self::kwOut('feature', $kw) . ': ' . self::toPlainText($featureName) . "\n";
    $desc = self::toPlainText($featureDescription);
    if ($desc !== '') {
      foreach (preg_split("/\n/", $desc) as $dl) {
        $out .= '  ' . $dl . "\n";
      }
    }
    $out .= "\n";
    foreach ($testCases as $tc) {
      $out .= self::testCaseToScenario($tc, $kw) . "\n";
    }
    return rtrim($out, "\n") . "\n";
  }

  /**
   * Convenience wrapper for a single test case.
   */
  public static function testCaseToFeature($name, $summary, $preconditions, $steps,
                                           $featureName = null, $lang = 'en')
  {
    $featureName = ($featureName === null) ? $name : $featureName;
    $tc = array('name' => $name, 'summary' => $summary,
                'preconditions' => $preconditions, 'steps' => $steps);
    return self::testCasesToFeature(array($tc), $featureName, $summary, $lang);
  }

  /** List of supported language codes. */
  public static function getSupportedLanguages()
  {
    return array_keys(self::$i18n);
  }

  // ----------------------------------------------------------------------------
  // Internal helpers
  // ----------------------------------------------------------------------------

  /** Detect "# language: xx" header; defaults to 'en'. */
  private static function detectLanguage($lines)
  {
    foreach ($lines as $raw) {
      $line = trim($raw);
      if ($line === '') {
        continue;
      }
      if (strpos($line, '#') === 0) {
        if (preg_match('/^#\s*language\s*:\s*([\w-]+)/i', $line, $m)) {
          $code = $m[1];
          if (isset(self::$i18n[$code])) {
            return $code;
          }
          // tolerate "zh_TW", "zh" etc.
          $norm = str_replace('_', '-', $code);
          if (isset(self::$i18n[$norm])) {
            return $norm;
          }
        }
        continue;
      }
      break; // first meaningful line reached
    }
    return 'en';
  }

  /**
   * Build keyword lookup tables for a language, each sorted longest-first.
   * @return array(blockKeywords, stepKeywords) where every entry is
   *         array('kw'=>string,'category'=>string).
   */
  private static function buildKeywordIndex($lang)
  {
    $dict = isset(self::$i18n[$lang]) ? self::$i18n[$lang] : self::$i18n['en'];

    $blocks = array();
    foreach (array('feature', 'background', 'outline', 'scenario', 'examples') as $cat) {
      foreach ($dict[$cat] as $kw) {
        $blocks[] = array('kw' => $kw, 'category' => $cat);
      }
    }

    $catMap = array('given' => 'Given', 'when' => 'When', 'then' => 'Then',
                    'and' => 'And', 'but' => 'But');
    $steps = array();
    foreach ($catMap as $cat => $canonical) {
      foreach ($dict[$cat] as $kw) {
        $steps[] = array('kw' => $kw, 'category' => $canonical);
      }
    }
    // '*' is a language-neutral step keyword that continues the block.
    $steps[] = array('kw' => '*', 'category' => 'And');

    self::sortByKeywordLengthDesc($blocks);
    self::sortByKeywordLengthDesc($steps);
    return array($blocks, $steps);
  }

  private static function sortByKeywordLengthDesc(&$list)
  {
    usort($list, function ($a, $b) {
      return mb_strlen($b['kw'], 'UTF-8') - mb_strlen($a['kw'], 'UTF-8');
    });
  }

  /**
   * Return the first keyword from $candidates that $line starts with.
   * Latin keywords require a word boundary so "And" does not match "Android".
   * @return array('keyword','category','remainder') or null
   */
  private static function matchKeyword($line, $candidates)
  {
    foreach ($candidates as $entry) {
      $kw = $entry['kw'];
      $len = mb_strlen($kw, 'UTF-8');
      if (mb_strtolower(mb_substr($line, 0, $len, 'UTF-8'), 'UTF-8') !==
          mb_strtolower($kw, 'UTF-8')) {
        continue;
      }
      $lastChar = mb_substr($kw, -1, 1, 'UTF-8');
      $needBoundary = (bool)preg_match('/[A-Za-zÀ-ÿ]/u', $lastChar);
      $after = mb_substr($line, $len, 1, 'UTF-8');
      if ($needBoundary && $after !== '' && preg_match('/[A-Za-zÀ-ÿ]/u', $after)) {
        continue; // e.g. "Android" must not match "And"
      }
      return array('keyword' => $kw, 'category' => $entry['category'],
                   'remainder' => ltrim(mb_substr($line, $len, null, 'UTF-8')));
    }
    return null;
  }

  /** True if a line begins with a step keyword in ANY supported language. */
  private static function looksLikeStep($line)
  {
    static $all = null;
    if ($all === null) {
      $all = array();
      foreach (self::$i18n as $lang => $dict) {
        foreach (array('given', 'when', 'then', 'and', 'but') as $cat) {
          foreach ($dict[$cat] as $kw) {
            $all[] = array('kw' => $kw, 'category' => $cat);
          }
        }
      }
      $all[] = array('kw' => '*', 'category' => 'and');
      self::sortByKeywordLengthDesc($all);
    }
    return self::matchKeyword($line, $all) !== null;
  }

  /**
   * Expand a parsed scenario into one or more concrete test cases.
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
      $suffix = array();
      foreach ($headers as $ci => $h) {
        $val = isset($row[$ci]) ? $row[$ci] : '';
        $repl['<' . $h . '>'] = $val;
        $suffix[] = $h . '=' . $val;
      }
      $steps = array();
      foreach ($scenario['steps'] as $st) {
        $steps[] = array('category' => $st['category'],
                         'line' => strtr($st['line'], $repl));
      }
      $result[] = array('name' => $scenario['name'] . ' [' . implode(', ', $suffix) . ']',
                        'description' => $scenario['description'],
                        'tags' => $scenario['tags'], 'steps' => $steps);
    }
    return $result;
  }

  /**
   * Render one concrete scenario as a TestLink <testcase> element.
   */
  private static function scenarioToTestCaseXML($feature, $tc)
  {
    $given = array();
    $when = array();
    $then = array();
    $current = 'Given';

    foreach ($feature['background'] as $st) {
      $given[] = $st['line'];
    }
    foreach ($tc['steps'] as $st) {
      if (in_array($st['category'], self::$primaryCategories, true)) {
        $current = $st['category'];
      }
      if ($current === 'Given') {
        $given[] = $st['line'];
      } elseif ($current === 'When') {
        $when[] = $st['line'];
      } else {
        $then[] = $st['line'];
      }
    }

    $summaryParts = array();
    if ($feature['name'] !== '') {
      $summaryParts[] = self::kwOut('feature', $feature['language']) . ': ' . $feature['name'];
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
  private static function testCaseToScenario($tc, $lang)
  {
    $name = self::toPlainText(isset($tc['name']) ? $tc['name'] : '');
    $out = '  ' . self::kwOut('scenario', $lang) . ': ' . $name . "\n";

    $pre = self::toPlainText(isset($tc['preconditions']) ? $tc['preconditions'] : '');
    $out .= self::renderBlockLines($pre, 'given', $lang);

    $steps = isset($tc['steps']) && is_array($tc['steps']) ? $tc['steps'] : array();
    foreach ($steps as $st) {
      $actions = self::toPlainText(isset($st['actions']) ? $st['actions'] : '');
      $expected = self::toPlainText(isset($st['expected_results']) ? $st['expected_results'] : '');
      $out .= self::renderBlockLines($actions, 'when', $lang);
      $out .= self::renderBlockLines($expected, 'then', $lang);
    }
    return $out;
  }

  /**
   * Render a multi-line text block as Gherkin step lines.
   * Lines that already begin with a keyword are emitted verbatim (clean
   * round-trip); otherwise the first gets $primaryCat and the rest 'and'.
   */
  private static function renderBlockLines($text, $primaryCat, $lang)
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
      if (self::looksLikeStep($line)) {
        $out .= '    ' . $line . "\n";
      } else {
        $kw = $first ? self::kwOut($primaryCat, $lang) : self::kwOut('and', $lang);
        $out .= '    ' . $kw . ' ' . $line . "\n";
      }
      $first = false;
    }
    return $out;
  }

  /** First synonym of a category in the requested language. */
  private static function kwOut($category, $lang)
  {
    $dict = isset(self::$i18n[$lang]) ? self::$i18n[$lang] : self::$i18n['en'];
    return isset($dict[$category][0]) ? $dict[$category][0] : self::$i18n['en'][$category][0];
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

  private static function appendToCurrentStep(&$feature, $context, $scenarioIdx, $text)
  {
    if ($context === 'background') {
      $n = count($feature['background']);
      if ($n > 0) {
        $feature['background'][$n - 1]['line'] .= "\n" . $text;
      }
    } elseif ($scenarioIdx >= 0) {
      $steps =& $feature['scenarios'][$scenarioIdx]['steps'];
      $n = count($steps);
      if ($n > 0) {
        $steps[$n - 1]['line'] .= "\n" . $text;
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
