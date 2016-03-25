<?php

/**
 * @copyright 2010-2014 Vanilla Forums Inc
 * @license Proprietary
 */
$PluginInfo['DiceRoll'] = array(
    'Name' => 'Minion: Dice Roll',
    'Description' => "Roll some dice.",
    'Version' => '1.2.1',
    'MobileFriendly' => TRUE,
    'Author' => "Tim Gunter",
    'AuthorEmail' => 'tim@vanillaforums.com',
    'AuthorUrl' => 'http://vanillaforums.com',
    'RequiredApplications' => array(
        'Vanilla' => '2.1a'
    ),
    'RequiredPlugins' => array(
        'Minion' => '1.14'
    ),
);

/**
 * Dice Roll Plugin
 *
 * Using the Minion subsystem and the dice roll parser from orokos.com,
 * create an interactive dice roller for PbP use.
 *
 * Changes:
 *  1.0        Release
 *  1.1        Multi dice rolls
 *  1.2        Support for symbols
 *  1.2.1      Format tags as text
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @author Daniel Major <dmajor@gmail.com>
 * @package minion
 */
class DiceRollPlugin extends Gdn_Plugin {

    const LIMIT_KEY = 'minion.dice.limit.%d';
    const LIMIT_LIMIT = 10;
    const OROKOS_IMAGES = "http://orokos.com/roll/images/";

    protected $queue = array();

    /**
     * Reference to MinionPlugin
     * @var MinionPlugin
     */
    protected $minion;

    /**
     * Hook early and perform game actions
     *
     * @param Gdn_Dispatcher $sender
     * @return type
     */
    public function gdn_dispatcher_appStartup_handler($sender) {
        $this->minion = MinionPlugin::instance();
    }

    /**
     * Add CSS files
     *
     * @param AssetModel $sender
     * @param type $args
     */
    public function assetModel_styleCss_handler($sender, $args) {
        $sender->addCssFile('diceroll.css', 'plugins/DiceRoll');
    }

    /**
     * Parse a token from the current state
     *
     * @param MinionPlugin $sender
     */
    public function minionPlugin_token_handler($sender) {
        $state = &$sender->EventArguments['State'];

        if (!$state['Method'] && in_array($state['CompareToken'], array('roll'))) {
            $sender->consume($state, 'Method', 'roll');

            $sender->consume($state, 'Gather', array(
                'Node' => 'Phrase',
                'Delta' => '',
            ));
        }
    }

    /**
     * Parse custom minion commands
     *
     * @param MinionPlugin $sender
     */
    public function minionPlugin_command_handler($sender) {
        $Actions = &$sender->EventArguments['Actions'];
        $state = &$sender->EventArguments['State'];

        switch ($state['Method']) {
            case 'roll':

                // Rolls must occur in discussions
                if (!array_key_exists('Discussion', $state['Sources'])) {
                    return;
                }

                // Rolls must specify a dice type
                if (!array_key_exists('Phrase', $state['Targets'])) {
                    return;
                }

                $Actions[] = array('roll', null, $state);
                break;
        }
    }

    /**
     * Perform custom minion actions
     *
     * @param MinionPlugin $sender
     */
    public function minionPlugin_action_handler($sender) {
        $action = $sender->EventArguments['Action'];
        $state = &$sender->EventArguments['State'];

        switch ($action) {

            // Play a game, or shut one down
            case 'roll':

                // Only works in threads
                if (!array_key_exists('Discussion', $state['Sources'])) {
                    return;
                }

                $user = &$state['Sources']['User'];
                $contentType = array_key_exists('Comment', $state['Sources']) ? 'comment' : 'discussion';
                $content = &$state['Sources'][ucfirst($contentType)];

                // Edits do nothing
                $alreadyRolled = $sender->monitoring($content, 'diceroll', false);
                if ($alreadyRolled) {
                    return;
                }

                // Enforce roll frequency limits
                $identifier = $contentType == 'comment' ? 'c' . $content['CommentID'] : $content['DiscussionID'];
                $limited = $this->limited($user, $identifier);
                if ($limited) {
                    Gdn::controller()->informMessage(T('Calm down buttercup, slow your roll'));
                    return false;
                }

                $dice = valr('Targets.Phrase', $state);
                $rollTag = null;
                if (is_null($rollTag)) {
                    $rollTag = val('Reason', $state, null);
                }
                if (is_null($rollTag)) {
                    $rollTag = val('Gravy', $state, null);
                }

                $dice = strip_tags(Gdn_Format::text($dice));
                $rolled = $this->roll($dice, $rollTag, $content, $user, $state);

                break;
        }
    }

    /**
     * Rolls some dice
     *
     * @param string $dice
     * @param string|null $tag
     * @param array $content
     * @param array $user
     */
    public function roll($dice, $tag, &$content, &$user, $state) {
        $dice = implode('; ', explode(' ', str_replace('; ', ' ', $dice)));
        $rolls = OrokosRoller::roller($dice);

        if (!OrokosRoller::is_error($rolls)) {

            // Enact roll frequency limit
            if (!Gdn::session()->checkPermission('Garden.Moderation.Manage')) {
                $this->limited($user, $identifier, true);
            }

            // Parse roll output
            $queue = [];
            foreach ($rolls as $roll) {
                $rollStr = $roll['roll'];

                $parts = [];
                $symbols = [];
                $i = 0;
                $nParts = sizeof($roll['result']);
                foreach ($roll['result'] as $part) {
                    $i++;
                    $partStr = $part['part'];
                    $results = array();

                    if ($i < $nParts) {

                        // # times part
                        $results[0] = 0;
                        foreach ($part['result'] as $partResult) {
                            $results[0] += $partResult['result'];
                            if (!empty($partResult['details'])) {
                                $results[] = trim($partResult['details']);
                            }
                        }

                        // Final part
                    } else {

                        foreach ($part['result'] as $partResult) {
                            $results[] = "<b>{$partResult['result']}</b>{$partResult['details']}";
                        }
                    }

                    if (array_key_exists('symbols', $partResult)) {
                        $symbols = array_merge($symbols, $partResult['symbols']);
                    }

                    $parts[] = join(' ', $results);
                }
                $parts = join(' # ', $parts);
                $queue[] = [
                    'roll' => $rollStr,
                    'result' => $parts,
                    'symbols' => $symbols
                ];
            }

            // Enqueue roll output
            $this->queueRoll($user, $content, $queue, $tag, $state);
            return true;
        } else {

            $error = $rolls['error'];
            switch ($error) {
                case 'limit':
                    Gdn::controller()->informMessage(T("Too many dice, meatbag. Roll less dice or suffer."));
                    break;

                case 'syntax':
                    Gdn::controller()->informMessage(T("Unable to process your filthy meatbag utterance. Be more precise."));
                    break;
            }
        }

        return false;
    }

    protected function symbols($symbols) {
        $symbolsStr = '';
        foreach ($symbols as $symbol) {
            $symbolSrc = paths(self::OROKOS_IMAGES, $symbol);
            $symbolsStr .= "<img src=\"{$symbolSrc}\" />\n";
        }
        return $symbolsStr;
    }

    /**
     * Queue up a roll for output
     *
     * @param array $user
     * @param array $content
     * @param array $results
     * @param string $tag Optional.
     */
    protected function queueRoll($user, $content, $results, $tag, $state) {
        $contentType = array_key_exists('CommentID', $content) ? 'comment' : 'discussion';
        $contentID = $contentType == 'comment' ? $content['CommentID'] : $content['DiscussionID'];
        $queueKey = "{$user['UserID']}.{$contentType}.{$contentID}";

        // Create queue if doesn't exist
        if (!array_key_exists($queueKey, $this->queue)) {
            $this->queue[$queueKey] = [
                'user' => $user,
                'content' => $content,
                'contentType' => $contentType,
                'contentID' => $contentID,
                'dice' => []
            ];
        }
        $queue = &$this->queue[$queueKey];

        // Parse roll output
        $queue['dice'][] = [
            'rolls' => $results,
            'tag' => $tag,
            'spoiled' => $state['Spoiled']
        ];
    }

    /**
     * Handle roll output queue
     *
     * @param MinionPlugin $sender
     */
    public function minionPlugin_performed_handler($sender) {
        foreach ($this->queue as $queue) {
            $sender->monitor($queue['content'], [
                'diceroll' => $queue['dice']
            ]);
        }

        if (sizeof($this->queue)) {
            Gdn::controller()->informMessage(T("Dice clatter ominously across the table..."));
        }
    }

    /**
     * Prepare to render comments and discussions
     *
     * @param Controller $sender
     */
    public function base_afterCommentFormat_handler($sender) {
        $output = $this->renderRolls($sender, $sender->EventArguments['Object']);
        $body = GetValue('FormatBody', $sender->EventArguments['Object']);
        $body .= "<p>{$output}</p>";
        SetValue('FormatBody', $sender->EventArguments['Object'], $body);
    }

    /**
     * Render rolls
     *
     * @param array $content
     * @param array $dice
     */
    protected function renderRolls($sender, &$content) {
        $dice = $this->minion->monitoring($content, 'diceroll', false);
        if (!$dice) {
            return;
        }

        $output = [
            'open' => '',
            'spoiled' => ''
        ];
        foreach ($dice as $expr) {
            $key = (array_key_exists('spoiled', $expr) && $expr['spoiled']) ? 'spoiled' : 'open';
            $exprStr = "<div class=\"Expr\">";
            $tag = Gdn_Format::text($expr['tag']);
            if (!empty($tag)) {
                $exprStr .= "<b>{$tag}</b>: ";
            }

            foreach ($expr['rolls'] as $roll) {
                $exprStr .= "<div class=\"Roll\">";
                $exprStr .= "<div><u>{$roll['roll']}</u> {$roll['result']}</div>";

                if (Gdn::request()->scheme() != 'https') {
                    if (isset($roll['symbols']) && sizeof($roll['symbols'])) {
                        $exprStr .= "<div class=\"Symbols\">";
                        $exprStr .= $this->symbols($roll['symbols']);
                        $exprStr .= "</div>";
                    }
                }
                $exprStr .= "</div>";
            }
            $exprStr .= "</div>\n";
            $output[$key] .= $exprStr;
        }

        $out = "<div class=\"DiceRoll\">\n";

        if (!empty($output['open'])) {
            $out .= "{$output['open']}";
        }

        if (!empty($output['spoiled'])) {
            $spoiled = Gdn_Format::To('[spoiler]!!roll!![/spoiler]', 'BBCode');
            $spoiled = str_replace('!!roll!!', $output['spoiled'], $spoiled);
            $out .= $spoiled;
        }

        $out .= "</div>";

        return $out;
    }

    /**
     * Check/Set roll limits
     *
     * Using identifier, we can allow multiple rolls per post.
     *
     * @param array $user
     * @param string $identifier d987234 or c239874
     * @param bool $limit
     */
    public function limited($user, $identifier, $limit = null) {
        $key = sprintf(self::LIMIT_KEY, getValue('UserID', $user));

        // Check
        if (is_null($limit)) {
            $limited = Gdn::cache()->get($key);
            if ($limited == $identifier) {
                return false;
            }
            if ($limited) {
                return true;
            }
            return false;
        }

        // Set cache limit
        Gdn::cache()->store($key, $identifier, array(
            Gdn_Cache::FEATURE_EXPIRY => self::LIMIT_LIMIT
        ));
    }

}
