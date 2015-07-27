<?php

/**
 * @copyright 2003 Vanilla Forums, Inc
 * @license Proprietary
 */

$PluginInfo['ThreadCycle'] = array(
    'Name' => 'Minion: ThreadCycle',
    'Description' => "Provide a command to automatically cycle a thread after N pages.",
    'Version' => '1.4',
    'RequiredApplications' => array(
        'Vanilla' => '2.1a'
    ),
    'RequiredPlugins' => array(
        'Minion' => '1.16',
        'Online' => '1.6.3'
    ),
    'MobileFriendly' => true,
    'Author' => "Tim Gunter",
    'AuthorEmail' => 'tim@vanillaforums.com',
    'AuthorUrl' => 'http://vanillaforums.com'
);

/**
 * ThreadCycle Plugin
 *
 * This plugin uses Minion to automatically close threads after N pages.
 *
 * Changes:
 *  1.0     Release
 *  1.1     Improve new thread creator choices
 *  1.2     Further improve new thread creator choices
 *  1.3     Add speeds!
 *  1.4     Add early bet bonus
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package minion
 */
class ThreadCyclePlugin extends Gdn_Plugin {

    const WAGER_KEY = 'plugin.threadcycle.wager.%d';
    const RECORD_KEY = 'plugin.threadcycle.record';

    public static $cycling = array();

    /**
     * Cycle this thread
     *
     * @param array $discussion
     * @param boolean $betting optional. honor bets? default true
     */
    public function cycleThread($discussion, $betting = true) {

        // Note that we're cycling this thread
        $discussionID = $discussion['DiscussionID'];
        self::$cycling[$discussionID] = (array)$discussion;

        // Determine speed
        $startTime = strtotime(val('DateInserted', $discussion));
        $endTime = time();
        $elapsed = $endTime - $startTime;
        $rate = (val('CountComments', $discussion) / $elapsed) * 60;
        $speedBoost = C('Minion.ThreadCycle.Boost', 2.5);
        $rate = $rate * $speedBoost;

        // Define known speeds and their characteristics
        $engines = array(
            'thrusters' => array(
                'min' => 0,
                'max' => 0.1,
                'format' => '{verb} at {speed} {scale}',
                'divisions' => 4,
                'divtype' => 'fractions',
                'replace' => ['1/1' => 'full'],
                'verbs' => array('moseying by', 'puttering along')
            ),
            'impulse' => array(
                'min' => 0.1,
                'max' => 0.4,
                'format' => '{verb} at {speed} {scale}',
                'divisions' => 4,
                'divtype' => 'fractions',
                'replace' => ['1/1' => 'full'],
                'verbs' => array('travelling', 'moving', 'scooting past')
            ),
            'warp' => array(
                'min' => 0.4,
                'max' => 10,
                'format' => '{verb} at {scale} {speed}',
                'divisions' => 10,
                'divtype' => 'decimal',
                'round' => 1,
                'verbs' => array('zooming by', 'blasting along', 'careening by', 'speeding through')
            ),
            'transwarp' => array(
                'min' => 10,
                'max' => null,
                'format' => '{verb} at transwarp',
                'divisions' => 1,
                'divtype' => 'const',
                'verbs' => array('hurtling by', 'streaking past')
            )
        );

        // Determine which engine was in use, and the speed
        $speed = null;
        $realSpeed = 0;
        $speedcontext = array(
            'cpm' => $rate,
            'rate' => $rate
        );
        foreach ($engines as $engine => $engineInfo) {
            $engineMin = $engineInfo['min'];
            $engineMax = $engineInfo['max'];

            if ($rate >= $engineMin && $rate < $engineMax) {
                $speedcontext['format'] = val('format', $engineInfo, '{scale}');
                $speedcontext['scale'] = $engine;

                $rangedRate = $rate - $engineMin;

                $divisions = val('divisions', $engineInfo, null);
                if ($divisions && $engineMax) {
                    $divType = val('divtype', $engineInfo, 'decimal');
                    switch ($divType) {
                        case 'fractions':
                            $range = $engineMax - $engineMin;
                            $bucketsize = $range / $divisions;
                            $fraction = round($rangedRate / $bucketsize);
                            $gcd = self::gcd($fraction, $divisions);
                            $num = $fraction / $gcd;
                            $den = $divisions / $gcd;
                            $speed = "{$num}/{$den}";
                            $realSpeed = $num/$den;
                            break;

                        case 'decimal':
                            $range = $engineMax - $engineMin;
                            $bucketsize = $range / $divisions;
                            $round = val('round', $engineInfo, 1);
                            $realSpeed = $speed = round($rangedRate / $bucketsize, $round);
                            break;

                        case 'const':
                            $realSpeed = $speed = 1;
                            break;

                        default:
                            break;
                    }
                }

                if (array_key_exists('replace', $engineInfo) && array_key_exists($speed, $engineInfo['replace'])) {
                    $speed = val($speed, $engineInfo['replace']);
                }
                if (!$realSpeed) {
                    $speedcontext['format'] = 'drifing in space';
                }
                if (array_key_exists('verbs', $engineInfo)) {
                    $verbKey = array_rand($engineInfo['verbs']);
                    $verb = val($verbKey, $engineInfo['verbs']);
                    $speedcontext['verb'] = $verb;
                }

                $speedcontext['speed'] = $speed;
                break;
            }
        }

        $discussionID = val('DiscussionID', $discussion);

        // Close the thread
        $discussionModel = new DiscussionModel();
        $discussionModel->setField($discussionID, 'Closed', true);

        // Find the last page of commenters
        $commentsPerPage = c('Vanilla.Comments.PerPage', 40);
        $commenters = Gdn::SQL()->Select('InsertUserID', 'DISTINCT', 'UserID')
                        ->From('Comment')
                        ->Where('DiscussionID', $discussionID)
                        ->OrderBy('DateInserted', 'desc')
                        ->Limit($commentsPerPage)
                        ->Get()->ResultArray();

        Gdn::userModel()->joinUsers($commenters, array('UserID'), array(
            'Join' => array('UserID', 'Name', 'Email', 'Photo', 'Punished', 'Banned', 'Points')
        ));

        // Weed out jailed and offline people
        $eligible = array();
        foreach ($commenters as $commenter) {
            // No jailed users
            if ($commenter['Punished']) {
                continue;
            }

            // No banned users
            if ($commenter['Banned']) {
                continue;
            }

            // No offline users
            $userOnline = OnlinePlugin::instance()->getUser($commenter['UserID']);
            if (!$userOnline) {
                continue;
            }

            $commenter['LastOnline'] = time() - strtotime($userOnline['Timestamp']);
            $eligible[] = $commenter;
        }
        unset($commenters);

        // Sort by online, ascending
        usort($eligible, array('ThreadCyclePlugin', 'compareUsersByLastOnline'));

        // Get the top 10 by online, and choose the top 5 by points
        $eligible = array_slice($eligible, 0, 10);
        usort($eligible, array('ThreadCyclePlugin', 'compareUsersByPoints'));
        $eligible = array_slice($eligible, 0, 5);

        // Shuffle
        shuffle($eligible);

        // Get the top 2 users
        $primary = val(0, $eligible, array());
        $secondary = Getvalue(1, $eligible, array());

        // Build user alert message
        $message = T("This thread is no longer active, and will be recycled.\n");
        if ($speed) {
            $message .= sprintf(T("On average, this thread was %s\n"), formatString($speedcontext['format'], $speedcontext));
        }
        $message .= "\n";
        $acknowledge = T("Thread has been recycled.\n");

        $options = array(
            'Primary' => &$primary,
            'Secondary' => &$secondary
        );

        if (sizeof($primary)) {
            $message .= $primaryMessage = T(" {Primary.Mention} will create the new thread\n");
            $acknowledge .= str_replace('.Mention', '.Anchor', $primaryMessage);

            $primary['Mention'] = "@\"{$primary['Name']}\"";
            $primary['Anchor'] = userAnchor($primary);
        }

        if (sizeof($secondary)) {
            $message .= $secondaryMessage = T(" {Secondary.Mention} is backup\n");
            $acknowledge .= str_replace('.Mention', '.Anchor', $secondaryMessage);

            $secondary['Mention'] = "@\"{$secondary['Name']}\"";
            $secondary['Anchor'] = userAnchor($secondary);
        }

        // Post in the thread for the users to see
        $message = formatString($message, $options);
        MinionPlugin::instance()->message($primary, $discussion, $message, false);

        // Log that this happened
        $acknowledged = formatString($acknowledge, $options);
        MinionPlugin::instance()->log($acknowledged, $discussion);

        // Stop caring about posts in here
        MinionPlugin::instance()->monitor($discussion, array(
            'ThreadCycle' => false
        ));

        // Handle betting
        if ($betting) {
            $this->cycleWager($discussion, 'pay');
        }
    }

    /**
     * Handle saved discussion closure
     *
     * @param DiscussionModel $sender
     */
    public function DiscussionModel_AfterSaveDiscussion_Handler($sender) {
        $discussionID = $sender->EventArguments['DiscussionID'];
        $values = $sender->EventArguments['FormPostValues'];

        // We only care if this save closes the discussion
        $closed = val('Closed', $values, 0);
        if (!$closed) {
            return;
        }

        // We don't care if this is a new discussion
        $isNew = val('IsNewDiscussion', $sender->EventArguments, false);
        if ($isNew) {
            return;
        }

        // Don't execute anything if we're in the middle of cycling this thread
        $isCycling = array_key_exists($discussionID, self::$cycling);
        if ($isCycling) {
            return;
        }

        $this->cycleWager($discussionID, 'return');
    }

    /**
     * Handle explicit discussion closure
     *
     * @param DiscussionModel $sender
     */
    public function DiscussionModel_AfterSetField_Handler($sender) {
        $discussionID = $sender->EventArguments['DiscussionID'];
        $setfield = $sender->EventArguments['SetField'];

        // We only care if this save closes the discussion
        if (!array_key_exists('Closed', $setfield) || !$setfield['Closed']) {
            return;
        }

        // Don't execute anything if we're in the middle of cycling this thread
        $isCycling = array_key_exists($discussionID, self::$cycling);
        if ($isCycling) {
            return;
        }

        $this->cycleWager($discussionID, 'return');
    }

    /**
     * Cycle wager logic
     *
     * @param integer $discussion
     * @param string $mode default 'pay', supports 'pay' and 'return'
     */
    public function cycleWager($discussion, $mode = 'pay') {
        if (is_numeric($discussion)) {
            $discussionID = $discussion;
            $discussion = val($discussionID, self::$cycling, null);
            if (!$discussion) {
                $discussionModel = new DiscussionModel;
                $discussion = $discussionModel->getID($discussionID, DATASET_TYPE_ARRAY);
            }
        }

        $discussionID = val('DiscussionID', $discussion, null);
        if (is_null($discussionID)) {
            return false;
        }

        // Betting
        $wagerKey = sprintf(self::WAGER_KEY, $discussionID);
        $wagers = Gdn::userMetaModel()->getWhere(array(
            'Name' => $wagerKey
        ))->resultArray();
        $countWagers = count($wagers);
        if (!$countWagers) {
            return false;
        }

        // Re-index by userid
        $wagers = array_column($wagers, null, 'UserID');

        // If only one person bet, don't take their money.
        if ($countWagers < 2) {
            $mode = 'return';

            // Message thread
            MinionPlugin::instance()->message(null, $discussion, T("Not enough bets, returning points."), array(
                'Inform' => false
            ));
        }

        $records = array();

        if ($mode == 'pay') {

            // Determine winner
            $startTime = strtotime(val('DateInserted', $discussion));
            $endTime = time();
            $elapsed = $endTime - $startTime;

            $ordered = array();
            $potPoints = 0;
            foreach ($wagers as $wagerUserID => $wagerRow) {
                $wager = json_decode($wagerRow['Value'], true);
                $wager['UserID'] = $wagerUserID;
                $absTimeDiff = abs($elapsed - $wager['For']);
                $wager['Abs'] = $absTimeDiff;

                $records[$wagerUserID] = array(
                    'Points' => $wager['Points'],
                    'Reward' => 0
                );

                if (!array_key_exists($absTimeDiff, $ordered)) {
                    $ordered[$absTimeDiff] = array();
                }

                $ordered[$absTimeDiff][] = $wager;
                $potPoints += $wager['Points'];
            }
            ksort($ordered, SORT_NUMERIC);

            // Check if winner is most prolific
            // @TODO

            // What percent does the house take?
            $rakePercent = C('Minion.ThreadCycle.Wager.Rake', 5);
            $rakeMultiple = 1 - $rakePercent / 100;

            // Award points
            $winners = array_shift($ordered);
            $haveRunnersUp = false;

            // Runners Up (only if losers exist)
            if (count($ordered) > 2) {
                $haveRunnersUp = true;
                $runnersUp = array_shift($ordered);
                foreach ($runnersUp as &$rWager) {
                    $rUserID = $rWager['UserID'];
                    $potPoints -= $rWager['Points'];
                    $returnPoints = floor($rWager['Points'] * $rakeMultiple);
                    $rUser = Gdn::userModel()->getID($rUserID, DATASET_TYPE_ARRAY);
                    $rUser['Points'] += $returnPoints;
                    Gdn::userModel()->setField($rUserID, 'Points', $rUser['Points']);

                    // Record returned points
                    $records[$rUserID]['Reward'] = $returnPoints;

                    $rWager['Winnings'] = $returnPoints;
                    $rWager['User'] = $rUser;
                }
            }

            // Winners
            $split = (count($winners) > 1);
            $potPoints *= $rakeMultiple; // Remove house cut
            $potPoints = floor($potPoints);

            // If there are multiple winners, determine total points wagered by winning bettors

            $boost = C('Minion.ThreadCycle.Wager.Boost', 10);
            $earlyWager = C('Minion.ThreadCycle.Wager.Early', 15);
            $startTime = strtotime($discussion['DateInserted']);
            $threadDuration = time() - $startTime;

            $winnerPotPoints = 0;
            foreach ($winners as $winner) {
                $winnerPotPoints += $winner['Points'];
            }
            foreach ($winners as &$wWager) {
                $wUserID = $wWager['UserID'];
                // If there are multiple winners, calculate winnings according to betting ratio
                if ($split) {
                    $ratio = $wWager['Points'] / $winnerPotPoints;
                    $winnings = $ratio * $potPoints;
                } else {
                    $winnings = $potPoints;
                }

                // Boost winnings for early bets
                $wagerAt = strtotime($wWager['Date']) - $startTime;
                $wagerAtPerc = round(($wagerAt / $threadDuration) * 100, 0);
                if ($wagerAtPerc <= $earlyWager) {
                    $wWager['Boost'] = $boost;
                    $winnings *= (1 + ($boost / 100));
                }

                $winnings = floor($winnings);
                $wUser = Gdn::userModel()->getID($wUserID, DATASET_TYPE_ARRAY);
                $wUser['Points'] += $winnings;
                Gdn::userModel()->setField($wUserID, 'Points', $wUser['Points']);

                // Record won points
                $records[$wUserID]['Reward'] = $winnings;

                // Modify for formatting
                $wWager['Winnings'] = $winnings;
                $wWager['User'] = $wUser;
            }

            // Announce
            $counter = $elapsed;
            $t = [];
            $t['days'] = floor($counter / 84600); $counter %= 84600;
            $t['hours'] = floor($counter / 3600); $counter %= 3600;
            $t['mins'] = floor($counter / 60);    $counter %= 60;
            $t['secs'] = $counter;

            $out = array();
            foreach ($t as $tKey => $tVal) {
                if (!empty($tVal)) {
                    $out[] = sprintf('%d %s', $tVal, plural($tVal, rtrim($tKey,'s'), $tKey));
                }
            }
            $elapsedStr = implode(', ', $out);
            $message = sprintf(T("Discussion took <b>%s</b> to recycle."), $elapsedStr)."<br/>";

            $winnerDiffPoints = $potPoints - $winnerPotPoints;
            $winnerDiffPerc = abs(round(($winnerDiffPoints / $winnerPotPoints) * 100, 0));

            $winnerCount = count($winners);
            $message .= sprintf(T("There %s %d %s, earning about %d%% %s points than they wagered."),
                plural($winnerCount, 'was', 'were'),
                $winnerCount,
                plural($winnerCount, 'winner', 'winners'),
                $winnerDiffPerc,
                (($winnerDiffPoints > 0) ? 'more' : 'less')
            )."<br/>";
            $message .= "<br/>";

            $message .= sprintf("<b>%s</b><br/>", plural($winnerCount, 'Winner', 'Winners'));
            foreach ($winners as $winningWager) {
                svalr('Mention', $winningWager['User'], "@\"{$winningWager['User']['Name']}\"");
                $message .= formatString(T("{User.Mention}, who bet <b>{ForStr}</b> with {Points} points and received <b>{Winnings}</b>"), $winningWager);
                if (array_key_exists('Boost', $winningWager) && $winningWager['Boost']) {
                    $message .= sprintf(T(" (%d%% early bet bonus)"), $winningWager['Boost']);
                }
                $message .= "<br/>";
            }

            if ($haveRunnersUp) {
                $message .= sprintf("<b>%s</b><br/>", plural($winnerCount, 'Runner-up', 'Runners-up'));
                foreach ($runnersUp as $ruWager) {
                    svalr('Mention', $ruWager['User'], "@\"{$ruWager['User']['Name']}\"");
                    $message .= formatString(T("{User.Mention}, who bet <b>{ForStr}</b> with {Points} points and recovered <b>{Winnings}</b>"), $ruWager)."<br/>";
                }
            }

            // Message thread
            MinionPlugin::instance()->message(null, $discussion, $message, array(
                'Inform' => false
            ));

        } else {

            // Return all points
            foreach ($wagers as &$wagerRow) {
                $wager = json_decode($wagerRow['Value'], true);
                $lUser = Gdn::userModel()->getID($wagerRow['UserID'], DATASET_TYPE_ARRAY);
                $lUser['Points'] += $wager['Points'];
                Gdn::userModel()->setField($lUser['UserID'], 'Points', $lUser['Points']);
            }

        }

        // Delete all wagers
        foreach ($wagers as $wagerRow) {
            Gdn::userMetaModel()->setUserMeta($wagerRow['UserID'], $wagerRow['Name'], null);
        }

        // Update users' permanent records
        foreach ($records as $recordUserID => $record) {
            $wagerRecord = Gdn::userMetaModel()->getUserMeta($recordUserID, self::RECORD_KEY, null);
            $wagerRecord = val(self::RECORD_KEY, $wagerRecord);

            if (!is_array($wagerRecord)) {
                $wagerRecord = array(
                    'Wagers' => 0,
                    'Points' => array(
                        'Bet' => 0,
                        'Won' => 0
                    )
                );
            }

            if (is_array($wagerRecord) && array_key_exists('Wagers', $wagerRecord)) {
                $wagerRecord['Wagers']++;
                $wagerRecord['Points']['Bet'] += $record['Points'];
                $wagerRecord['Points']['Won'] += $record['Reward'];

                $wagerSave = json_encode($wagerRecord);
                Gdn::userMetaModel()->setUserMeta($recordUserID, self::RECORD_KEY, $wagerSave);
            }
        }
    }

   /**
    * Store a wager in UserMeta
    *
    * @param integer $userID
    * @param integer $discussionID
    * @param array $wager
    * @return boolean
    */
   protected function storeWager($userID, $discussionID, $wager) {
      if (is_array($wager)) {
         $wager = json_encode($wager);
      }

      $wagerKey = sprintf(self::WAGER_KEY, $discussionID);
      Gdn::userMetaModel()->setUserMeta($userID, $wagerKey, $wager);
      return true;
   }

   /**
    * Retrieve a wager from UserMeta
    *
    * @param integer $userID
    * @param integer $discussionID
    * @return array|boolean
    */
   protected function retrieveWager($userID, $discussionID) {
      $wagerKey = sprintf(self::WAGER_KEY, $discussionID);
      $wager = Gdn::userMetaModel()->getUserMeta($userID, $wagerKey, null);
      if (!is_array($wager) || !count($wager)) {
          return false;
      }

      $wager = val($wagerKey, $wager);
      if (!$wager) {
          return false;
      }

      $wager = json_decode($wager, true);
      if (!$wager) {
          return false;
      }

      return $wager;
   }

    /**
     * Calculate GCD
     *
     * @param integer $a
     * @param integer $b
     * @return integer
     */
    protected static function gcd($a,$b) {
        $a = abs($a); $b = abs($b);
        if( $a < $b) list($b,$a) = Array($a,$b);
        if( $b == 0) return $a;
        $r = $a % $b;
        while($r > 0) {
            $a = $b;
            $b = $r;
            $r = $a % $b;
        }
        return $b;
    }

    public static function compareUsersByPoints($a, $b) {
        return $b['Points'] - $a['Points'];
    }

    public static function compareUsersByLastOnline($a, $b) {
        return $a['LastOnline'] - $b['LastOnline'];
    }

    /*
     * MINION INTERFACE
     */

    /**
     * Parse a token from the current state
     *
     * @param MinionPlugin $sender
     */
    public function MinionPlugin_Token_Handler($sender) {
        $state = &$sender->EventArguments['State'];

        if (!$state['Method'] && in_array($state['CompareToken'], array('recycle'))) {
            $sender->consume($state, 'Method', 'threadcycle');
        }

        $threadCycle = $sender->monitoring($state['Sources']['Discussion'], 'ThreadCycle');
        if ($threadCycle) {
            if (!$state['Method'] && in_array($state['CompareToken'], array('wager', 'bet'))) {
                $sender->consume($state, 'Method', 'cyclewager');

                if (is_null($state['Toggle'])) {
                    $state['Toggle'] = MinionPlugin::TOGGLE_ON;
                }
            }
        }

        // Gather page
        if (val('Method', $state) == 'threadcycle' && in_array($state['CompareToken'], array('pages', 'page'))) {

            // Do a quick lookbehind
            if (is_numeric($state['LastToken'])) {
                $state['Targets']['Page'] = $state['LastToken'];
                $sender->consume($state);
            } else {
                $sender->consume($state, 'Gather', array(
                    'Node' => 'Page',
                    'Delta' => ''
                ));
            }
        }

        // Gather wager
        if (val('Method', $state) == 'cyclewager') {
            if (in_array($state['CompareToken'], array('point', 'points'))) {

                // Do a quick lookbehind
                if (is_numeric($state['LastToken'])) {
                    $state['Targets']['Wager'] = $state['LastToken'];
                    $sender->consume($state);
                } else {
                    $sender->consume($state, 'Gather', array(
                        'Node' => 'Wager',
                        'Type' => 'number',
                        'Delta' => ''
                    ));
                }
            }

            if (in_array($state['CompareToken'], array('for', 'because', 'with', 'on'))) {
                $sender->consumeUntilNextKeyword($state, 'For', false, true);
            }
        }
    }

    /**
     * Parse custom minion commands
     *
     * @param MinionPlugin $sender
     */
    public function MinionPlugin_Command_Handler($sender) {
        $actions = &$sender->EventArguments['Actions'];
        $state = &$sender->EventArguments['State'];

        switch ($state['Method']) {
            case 'threadcycle':

                $state['Targets']['Discussion'] = $state['Sources']['Discussion'];
                $actions[] = array('threadcycle', c('Minion.Access.Recycle','Garden.Moderation.Manage'), $state);
                break;

            case 'cyclewager':

                if (!C('Minion.ThreadCycle.Wager.Allow', true)) {
                    return;
                }
                $state['Targets']['Discussion'] = $state['Sources']['Discussion'];
                $actions[] = array('cyclewager', c('Minion.Access.CycleWager','Garden.SignIn.Allow'), $state);
                break;
        }
    }

    /**
     * Perform custom minion actions
     *
     * @param MinionPlugin $sender
     */
    public function MinionPlugin_Action_Handler($sender) {
        $action = $sender->EventArguments['Action'];
        $state = $sender->EventArguments['State'];

        switch ($action) {

            case 'threadcycle':

                $discussion = $state['Targets']['Discussion'];
                $threadCycle = $sender->monitoring($discussion, 'ThreadCycle', false);

                // Trying to call off a threadcycle
                $toggle = val('Toggle', $state, MinionPlugin::TOGGLE_ON);
                if ($toggle == MinionPlugin::TOGGLE_OFF) {

                    if (!$threadCycle) {
                        return;
                    }

                    // Call off the hunt
                    $sender->monitor($discussion, array(
                        'ThreadCycle' => null
                    ));

                    // Return any bets
                    $this->cycleWager($discussion, 'return');

                    $sender->acknowledge($state['Sources']['Discussion'], formatString(T("This thread will not be automatically recycled."), array(
                        'Discussion' => $discussion
                    )));

                // Trying start a threadcycle
                } else {

                    $cyclePage = val('Page', $state['Targets'], false);
                    if ($cyclePage) {

                        // Pick somewhere to end the discussion
                        $commentsPerPage = C('Vanilla.Comments.PerPage', 40);
                        $minComments = ($cyclePage - 1) * $commentsPerPage;
                        $commentNumber = $minComments + mt_rand(1, $commentsPerPage - 1);

                        // Monitor the thread
                        $sender->monitor($discussion, array(
                            'ThreadCycle' => array(
                                'Started' => time(),
                                'Page' => $cyclePage,
                                'Comment' => $commentNumber
                            )
                        ));

                        $acknowledge = T("Thread will be recycled after {Page}.");
                        $acknowledged = formatString($acknowledge, array(
                            'Page' => sprintf(Plural($cyclePage, '%d page', '%d pages'), $cyclePage),
                            'Discussion' => $state['Targets']['Discussion']
                        ));

                        $sender->acknowledge($state['Sources']['Discussion'], $acknowledged);
                        $sender->log($acknowledged, $state['Targets']['Discussion'], $state['Sources']['User']);

                    } else {
                        // Cycle immediately
                        $this->cycleThread($discussion, false);
                    }
                }

                break;

            case 'cyclewager':

                // Get discussion
                $comment = valr('Source.Comment', $state, null);
                $discussion = $state['Sources']['Discussion'];
                $discussionID = $discussion['DiscussionID'];
                $threadCycle = $sender->monitoring($discussion, 'ThreadCycle', false);

                try {

                    // Don't allow bets outside of recycling
                    if (!$threadCycle) {
                        throw new Exception(T("This thread is not currently scheduled for recyling, unable to bet."));
                    }

                    // Close betting when 75% of the thread has passed
                    $threadTerminateComment = val('Comment', $threadCycle);
                    $threadProgress = ($discussion['CountComments'] / $threadTerminateComment) * 100;
                    if ($threadProgress >= C('Minion.ThreadCycle.Wager.Cutoff', 75)) {
                        throw new Exception(T("Betting is now closed in this discussion."));
                    }

                    // Get wager info
                    //$wagerKey = "plugin.threadcycle.wager.{$discussionID}";
                    $user = $state['Sources']['User'];
                    $userID = $user['UserID'];
                    $wager = $this->retrieveWager($userID, $discussionID);

                    $toggle = val('Toggle', $state, MinionPlugin::TOGGLE_ON);
                    if (is_null($toggle)) {
                        $toggle = MinionPlugin::TOGGLE_ON;
                    }
                    if ($toggle == MinionPlugin::TOGGLE_OFF) {

                        if (!$wager) {
                            throw new Exception(T("You do not currently have a cycle wager in this discussion."));
                        }

                        // Cancel wager
                        $this->storeWager($userID, $discussionID, null);
                        //Gdn::userMetaModel()->setUserMeta($userID, $wagerKey, null);

                        // Give points back
                        $wagerPoints = val('Points', $wager, 0);
                        if ($wagerPoints) {
                            $user['Points'] += $wagerPoints;
                            Gdn::userModel()->setField($userID, 'Points', $user['Points']);
                        }

                        // Acknowledge the user
                        $acknowledge = T("Your wager of <b>%d points</b> has been <b>cancelled</b>.");
                        $acknowledged = sprintf($acknowledge, $wagerPoints, $wager['ForStr']);
                        $sender->acknowledge($discussion, $acknowledged, 'positive', $user, array(
                            'Inform' => true,
                            'Comment' => false
                        ));

                    } else {


                        // We require a wager!
                        if (!array_key_exists('Wager', $state['Targets'])) {
                            throw new Exception(T("You didn't supply a wager amount!"));
                        }

                        // We require a time!
                        if (!array_key_exists('Time', $state)) {
                            throw new Exception(T("You didn't supply a valid time!"));
                        }
                        $wagerTime = trim($state['Time'], ' .,!?/\\#@');

                        if (preg_match('`\d\.\d`i', $wagerTime)) {
                            throw new Exception(T("You're trying to use decimal points in your time. Instead, use multiple unit groups like <b>5 hours, 20 minutes</b>"));
                        }

                        // Note that we're modifying an existing wager
                        $modify = false;
                        if ($wager) {
                            $modify = true;
                        }

                        $newWagerPoints = round($state['Targets']['Wager'], 0);

                        // Don't allow negative points wagering
                        if ($newWagerPoints < 0) {
                            $sender->punish($user, $discussion, $comment, MinionPlugin::FORCE_LOW, array(
                                'Reason' => 'Trying to abuse Cycle Wagering for profit'
                            ));
                            throw new Exception(T("You must wager a positive number of points!"));
                        }

                        // Don't allow too low bets
                        if ($newWagerPoints < ($wagerMinimum = C('Minion.ThreadCycle.Wager.Minimum', 50))) {
                            throw new Exception(sprintf(T("Proposed wager is too low, you must risk at least <b>%d %s</b>"), $wagerMinimum, plural($wagerMinimum, 'point', 'points')));
                        }

                        $wagerPoints = val('Points', $wager, 0);
                        $myPoints = $user['Points'] + $wagerPoints;

                        // Check if the user has enough points
                        if ($newWagerPoints > $myPoints) {
                            $acknowledge = T("You do not have sufficient points to cover that wager! %d is less than %d");
                            $acknowledged = sprintf($acknowledge, $myPoints, $newWagerPoints);
                            throw new Exception($acknowledged);
                        }

                        // Build the wager
                        $wagerTimeString = $wagerTime;

                        $newWager = array(
                            'Points' => $newWagerPoints,
                            'Date' => date('Y-m-d H:i:s'),
                            'For' => abs(strtotime($wagerTimeString) - time()),
                            'ForStr' => $wagerTimeString
                        );
                        $this->storeWager($userID, $discussionID, $newWager);

                        // Update user points
                        $newUserPoints = $myPoints - $newWagerPoints;
                        $user['Points'] = $newUserPoints;
                        Gdn::userModel()->setField($userID, 'Points', $user['Points']);

                        // Acknowledge the user
                        $isNew = $modify ? 'new ' : '';
                        $acknowledge = T("Your %swager of <b>%d points</b> for <b>%s</b> has been entered!");
                        $acknowledged = sprintf($acknowledge, $isNew, $newWagerPoints, $wagerTimeString);
                        $sender->acknowledge($discussion, $acknowledged, 'positive', $user, array(
                            'Inform' => true,
                            'Comment' => false
                        ));

                    }

                } catch (Exception $ex) {
                    $sender->acknowledge($discussion, $ex->getMessage(), 'custom', Gdn::session()->User, array(
                        'Inform' => true,
                        'Comment' => false
                    ));
                }
                break;
        }
    }

    /**
     * Determine if we're at the comment that should trigger recycling
     *
     * @param MinionPlugin $sender
     */
    public function MinionPlugin_Monitor_Handler($sender) {
        $discussion = $sender->EventArguments['Discussion'];
        $threadCycle = $sender->monitoring($discussion, 'ThreadCycle', false);
        if (!$threadCycle) {
            return;
        }

        $cycleCommentNumber = val('Comment', $threadCycle);
        $comments = val('CountComments', $discussion);
        if ($comments >= $cycleCommentNumber) {
            $this->cycleThread($discussion);
        }
    }

    /**
     * Hook for E:Sanctions from MinionPlugin
     *
     * This event hook allows us to add core sanctions to the rule list.
     *
     * @param MinionPlugin $sender
     */
    public function MinionPlugin_Sanctions_Handler($sender) {

        // Show a warning if there are rules in effect

        $threadCycle = $sender->monitoring($sender->EventArguments['Discussion'], 'ThreadCycle', null);

        // Nothing happening?
        if (!$threadCycle) {
            return;
        }

        $page = val('Page', $threadCycle);

        $rules = &$sender->EventArguments['Rules'];
        $rules[] = wrap("<span class=\"icon icon-refresh\" title=\"".T('Auto recycle')."\"></span> Page {$page}", 'span', array('class' => 'MinionRule'));
    }

}
