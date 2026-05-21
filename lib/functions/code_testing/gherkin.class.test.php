<?php
/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * Self-contained tests for the Gherkin / BDD (.feature) engine.
 * No database is required; run from CLI or browser:
 *
 *   php lib/functions/code_testing/gherkin.class.test.php
 *
 * @filesource  gherkin.class.test.php
 * @package     TestLink
 */
require_once(dirname(__FILE__) . '/../gherkin.class.php');

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
$cli = (php_sapi_name() === 'cli');
if (!$cli) { echo "<pre>"; }

function check($cond, $label) {
  if ($cond) {
    $GLOBALS['__pass']++;
    echo "  [PASS] $label\n";
  } else {
    $GLOBALS['__fail']++;
    echo "  [FAIL] $label\n";
  }
}

/** Extract testcases (name/preconditions/action/expected) from generated XML. */
function tcsFromXML($xml) {
  $out = array();
  $sx = simplexml_load_string($xml);
  if ($sx === false) { return false; }
  foreach ($sx->xpath('//testcase') as $tc) {
    $out[] = array(
      'name' => (string)$tc['name'],
      'preconditions' => trim((string)$tc->preconditions),
      'actions' => trim((string)$tc->steps->step->actions),
      'expected' => trim((string)$tc->steps->step->expectedresults));
  }
  return $out;
}

// ---------------------------------------------------------------------------
echo "== 1. Parse English feature ==\n";
$en = <<<'FEAT'
@checkout @smoke
Feature: Shopping cart checkout
  As a customer I want to check out.

  Background:
    Given the store catalogue is loaded

  Scenario: Successful checkout
    Given the user is logged in
    And the cart contains 1 item
    When the user clicks checkout
    Then an order is created
    And a confirmation email is sent

  Scenario Outline: Checkout quantities
    Given the cart contains <qty> items
    When the user checks out
    Then the total is <total>

    Examples:
      | qty | total |
      | 2   | 20    |
      | 3   | 30    |
FEAT;

$p = gherkin::parse($en);
check($p['name'] === 'Shopping cart checkout', "feature name");
check($p['language'] === 'en', "default language en");
check($p['tags'] === array('@checkout', '@smoke'), "feature tags");
check(count($p['background']) === 1, "background step count");
check(count($p['scenarios']) === 2, "scenario count");
check($p['scenarios'][0]['outline'] === false, "scenario 0 is not outline");
check($p['scenarios'][1]['outline'] === true, "scenario 1 is outline");
check(count($p['scenarios'][1]['examples']['rows']) === 2, "examples row count");

// ---------------------------------------------------------------------------
echo "== 2. Feature -> XML (well-formed + field mapping) ==\n";
$xml = gherkin::featureToTestSpecXML($en);
$tcs = tcsFromXML($xml);
check($tcs !== false, "generated XML is well-formed");
check(count($tcs) === 3, "3 test cases (1 scenario + 2 outline rows)");
check(strpos($tcs[0]['preconditions'], 'the store catalogue is loaded') !== false,
      "background merged into preconditions");
check(strpos($tcs[0]['preconditions'], 'the user is logged in') !== false,
      "Given block -> preconditions");
check(strpos($tcs[0]['actions'], 'the user clicks checkout') !== false,
      "When block -> actions");
check(strpos($tcs[0]['expected'], 'an order is created') !== false,
      "Then block -> expected results");
check(strpos($tcs[0]['expected'], 'a confirmation email is sent') !== false,
      "And-after-Then continues Then block");

// ---------------------------------------------------------------------------
echo "== 3. Scenario Outline expansion ==\n";
check(strpos($tcs[1]['name'], 'qty=2') !== false, "outline row 1 name carries example values");
check(strpos($tcs[1]['preconditions'], 'contains 2 items') !== false, "placeholder <qty> substituted (row 1)");
check(strpos($tcs[1]['expected'], 'total is 20') !== false, "placeholder <total> substituted (row 1)");
check(strpos($tcs[2]['preconditions'], 'contains 3 items') !== false, "placeholder <qty> substituted (row 2)");

// ---------------------------------------------------------------------------
echo "== 4. Multi-language keywords ==\n";

$fr = <<<'FEAT'
# language: fr
Fonctionnalité: Connexion
  Scénario: Connexion valide
    Soit un utilisateur enregistré
    Quand il saisit des identifiants valides
    Alors le tableau de bord est affiché
FEAT;
$pf = gherkin::parse($fr);
check($pf['language'] === 'fr', "fr language detected");
check($pf['name'] === 'Connexion', "fr feature name");
$tf = tcsFromXML(gherkin::featureToTestSpecXML($fr));
check(strpos($tf[0]['preconditions'], 'utilisateur enregistré') !== false, "fr Soit -> preconditions");
check(strpos($tf[0]['actions'], 'identifiants valides') !== false, "fr Quand -> actions");
check(strpos($tf[0]['expected'], 'tableau de bord') !== false, "fr Alors -> expected");

$zh = <<<'FEAT'
# language: zh-TW
功能: 登入
  場景: 有效登入
    假設 使用者已註冊
    當 使用者輸入正確帳密
    那麼 顯示儀表板
    而且 寄出通知信
FEAT;
$pz = gherkin::parse($zh);
check($pz['language'] === 'zh-TW', "zh-TW language detected");
check($pz['name'] === '登入', "zh-TW feature name");
$tz = tcsFromXML(gherkin::featureToTestSpecXML($zh));
check(strpos($tz[0]['preconditions'], '使用者已註冊') !== false, "zh 假設 -> preconditions");
check(strpos($tz[0]['actions'], '正確帳密') !== false, "zh 當 -> actions");
check(strpos($tz[0]['expected'], '顯示儀表板') !== false, "zh 那麼 -> expected");
check(strpos($tz[0]['expected'], '寄出通知信') !== false, "zh 而且-after-那麼 continues Then");

// ---------------------------------------------------------------------------
echo "== 5. Round trip (test case -> .feature -> parse) ==\n";
$feat = gherkin::testCaseToFeature(
  'Successful checkout',
  'Feature: Shopping cart checkout',
  'Given the user is logged in<br/>And the cart contains 1 item',
  array(array('actions' => 'When the user clicks checkout',
              'expected_results' => 'Then an order is created')),
  'Shopping cart checkout');
$rp = gherkin::parse($feat);
check(count($rp['scenarios']) === 1, "round trip yields one scenario");
check($rp['scenarios'][0]['name'] === 'Successful checkout', "round trip scenario name");
$rt = tcsFromXML(gherkin::featureToTestSpecXML($feat));
check(strpos($rt[0]['preconditions'], 'the user is logged in') !== false, "round trip preconditions preserved");
check(strpos($rt[0]['actions'], 'the user clicks checkout') !== false, "round trip actions preserved");

// French output language
$featFr = gherkin::testCaseToFeature('Connexion', '', 'un utilisateur enregistré',
  array(array('actions' => 'il se connecte', 'expected_results' => 'le tableau est affiché')),
  'Connexion', 'fr');
check(strpos($featFr, 'Fonctionnalité:') !== false, "fr output uses Fonctionnalité");
check(strpos($featFr, 'Soit ') !== false, "fr output uses Soit for Given block");
check(strpos($featFr, 'Quand ') !== false, "fr output uses Quand for When block");

// ---------------------------------------------------------------------------
echo "== 6. Keyword boundary safety ==\n";
$amb = <<<'FEAT'
Feature: Boundary
  Scenario: words that merely start with keywords
    Given Android devices are ready
    When Whenever possible run fast
    Then Thenable promises resolve
FEAT;
$pa = gherkin::parse($amb);
$steps = $pa['scenarios'][0]['steps'];
check($steps[0]['category'] === 'Given' && strpos($steps[0]['line'], 'Android') !== false,
      "'Given Android...' keyword is Given, 'Android' kept in text");
check($steps[1]['category'] === 'When', "'When Whenever...' classified as When (not mis-split)");
check($steps[2]['category'] === 'Then', "'Then Thenable...' classified as Then");

// ---------------------------------------------------------------------------
echo "\n== SUMMARY ==\n";
echo "  PASS: {$GLOBALS['__pass']}  FAIL: {$GLOBALS['__fail']}\n";
if (!$cli) { echo "</pre>"; }
if ($cli) { exit($GLOBALS['__fail'] === 0 ? 0 : 1); }
