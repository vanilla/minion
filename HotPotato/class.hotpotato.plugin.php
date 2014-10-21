<?php

/**
 * @copyright 2003 Vanilla Forums, Inc
 * @license Proprietary
 */
$PluginInfo['HotPotato'] = [
    'Name' => 'Minion: HotPotato',
    'Description' => "HotPotato game and badges.",
    'Version' => '1.0a',
    'RequiredApplications' => [
        'Vanilla' => '2.1a',
        'Reputation' => '1.0'
    ],
    'RequiredPlugins' => [
        'Minion' => '1.12',
        'Reactions' => '1.2.1'
    ],
    'MobileFriendly' => true,
    'Author' => "Tim Gunter",
    'AuthorEmail' => 'tim@vanillaforums.com',
    'AuthorUrl' => 'http://vanillaforums.com'
];

/**
 * HotPotato Plugin
 *
 * This plugin uses Minion, Reactions, and Badges to create a forum game that
 * resembles Hot Potato.
 *
 * Badgers:
 *
 *  Typhoid Mary - Take a potato from one category to another
 *  Billy the Kid - Pass a potato in under 60 seconds
 *  Hurt Locker - Receive a potato with less than 30 seconds remaining on the clock
 *  EOD - Dispose of a potato which had an expiry under 30 seconds
 *  Many Hands - Be part of a potato chain that hits 100 people
 *  Potato Farmer - Receive 10 potatoes
 *  Hospital Pass - Pass a potato that expires and causes the recipient to go to jail
 *
 * Changes:
 *  1.0     Release
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package minion
 * @subpackage hotpotato
 */
class HotPotatoPlugin extends Gdn_Plugin {

    const POTATO_KEY = 'minion.hotpotato.potato.%s';

    const POTATO_CHECK_FREQ = 120;
    const POTATO_CHECK_KEY = 'minion.hotpotato.check';

    protected $throws = [
        'flings',
        'throws',
        'hurls',
        'tosses',
        'lobs',
        'heaves',
        'punts',
        'catapults',
        'chucks',
        'launches',
        'propels',
        'slings'
    ];

    /**
     * List of known potatoes
     * @var array
     */
    protected $potatoes;

    /**
     * Is HotPotato enabled?
     * @var boolean
     */
    protected $enabled;

    /**
     * Startup configuration
     *
     */
    public function __construct() {
        parent::__construct();

        $this->enabled = C('Plugins.HotPotato.Enabled', true);
        $this->potatoes = [];
    }

    /*
     * COMMAND INTERFACE
     *
     */

    /**
     * Hook for E:Token from MinionPlugin
     *
     * Parse a token from the current state while running checkCommands. This
     * method allows us to intercept Minion invocations and attach custom
     * functionality.
     *
     * @param MinionPlugin $sender
     */
    public function MinionPlugin_Token_Handler($sender) {
        $state = &$sender->EventArguments['State'];

        // Start hot potato
        if (!$state['Method'] && in_array($state['CompareToken'], [
            'toss',
            'lob'
        ])) {
            $sender->consume($state, 'Method', 'hotpotato');
        }

        // Get potato name
        if ($state['Method'] == 'hotpotato') {
            if (in_array($state['CompareToken'], ['a'])) {
                $sender->consume($state, 'Gather', [
                    'Node' => 'Potato',
                    'Type' => 'phrase',
                    'Delta' => '',
                    'Terminator' => true,
                    'Boundary' => ['to', 'at']
                ]);
            }
        }
    }

    /**
     * Hook for E:Command from MinionPlugin
     *
     * Parse custom minion commands. This method adds action handling for the
     * commands matched during token parsing and queueing associates command
     * execution.
     *
     * @param MinionPlugin $sender
     */
    public function MinionPlugin_Command_Handler($sender) {
        $actions = &$sender->EventArguments['Actions'];
        $state = &$sender->EventArguments['State'];

        // If we don't know the targetted user, try to detect by a quote
        if (!key_exists('User', $state['Targets'])) {
            $sender->matchQuoted($state);
        }

        switch ($state['Method']) {
            case 'hotpotato':
                $actions[] = ['hotpotato', null, $state];
                break;
        }
    }

    /**
     * Hook for E:Action from MinionPlugin
     *
     * Perform custom minion actions. This method handles the queued actions generated by matching commands from user
     * input.
     *
     * @param MinionPlugin $sender
     */
    public function MinionPlugin_Action_Handler($sender) {
        $action = $sender->EventArguments['Action'];
        $state = $sender->EventArguments['State'];

        switch ($action) {

            case 'hotpotato':

                $from = &$state['Sources']['User'];

                if (!key_exists('User', $state['Targets'])) {
                    $sender->acknowledge(null, T('You must supply a valid target user.'), 'custom', $from, [
                        'Comment' => false
                    ]);
                    break;
                }

                // Target already has a potato?
                $to = &$state['Targets']['User'];
                $targetHasPotato = $this->holding($to['UserID']);
                if ($targetHasPotato) {
                    $targetPotatoID = val('PotatoID', $targetHasPotato, null);
                    $targetPotato = $this->getPotato($targetPotatoID);
                    $sender->acknowledge(null, T("{Target.Name} is already holding a {Potato.Name}!"), 'custom', $from, [
                        'Comment' => false
                    ], [
                        'Target' => $to,
                        'Potato' => $targetPotato
                    ]);
                    break;
                }

                $potato = false;

                // Check access control

                // Someone who has a potato may toss it
                $haveHotPotato = $this->holding($from['UserID']);
                if ($haveHotPotato) {
                    $potatoID = val('PotatoID', $haveHotPotato, null);
                    $potato = $this->getPotato($potatoID);

                    // Check this potato!
                    if ($potato) {
                        $potatoOk = $this->checkPotato($potato);
                        if (!$potatoOk) {
                            $sender->acknowledge(null, T('The {Potato.Name} slips from your hand as you toss it!'), 'custom', $from, [
                                'Comment' => false
                            ],[
                                'Potato' => $potato
                            ]);
                            break;
                        }
                    }
                }

                // Moderators may create new potatos

                if (!$potato && Gdn::session()->checkPermission('Garden.Moderation.Manage')) {
                    $newPotatoName = valr('Targets.Potato', $state, null);
                    if (!$newPotatoName) {
                        $sender->acknowledge(null, T('You must supply a name for the new thing you want to toss!'), 'custom', $from, [
                            'Comment' => false
                        ]);
                        break;
                    }

                    $potatoExists = $this->findPotato($newPotatoName);
                    if ($potatoExists) {
                        $sender->acknowledge(null, T("It looks like there's already a {Name} floating around!"), 'custom', $from, [
                            'Comment' => false
                        ],[
                            'Name' => $newPotatoName
                        ]);
                        break;
                    }

                    // Got a potato name, try to create
                    $duration = val('Time', $state, null);
                    if (!is_null($duration)) {
                        $duration = $duration - time();
                        if ($duration <= 0) {
                            $duration = null;
                        }
                    }
                    if (!is_integer($duration)) {
                        $duration = strtotime('+'.C('Plugins.HotPotato.Duration', '10 days'));
                        $duration = $duration - time();
                    }

                    if (!is_integer($duration)) {
                        $duration = 84600;
                    }
                    $potato = $this->newPotato($newPotatoName, $duration, $from['UserID']);
                }

                // No potato, or failed to create potato
                if (!$potato) {
                    $sender->acknowledge(null, T("Couldn't find anything to toss!"), 'custom', $from, [
                        'Comment' => false
                    ]);
                    break;
                }

                // Check if recipient has already received this item before
                if ($this->hasReceived($potato, $to)) {
                    $sender->acknowledge(null, T("Come on, don't you think someone else deserves some alone time with that {Potato.Name}?"), 'custom', $from, [
                        'Comment' => false
                    ],[
                        'Potato' => $potato
                    ]);
                    break;
                }

                // Toss potato
                $this->toss($potato, $to, $from, true);

                shuffle($this->throws);
                $throwStyle = $this->throws[mt_rand(0, count($this->throws) - 1)];
                $sender->acknowledge($state['Sources']['Discussion'], T("{From.Name} {ThrowStyle} the {Potato.Name} in {To.Name}'s general direction."), 'custom', $from, [
                    'Comment' => false
                ],[
                    'To' => $to,
                    'From' => $from,
                    'Throws' => $throwStyle,
                    'Potato' => $potato
                ]);
                break;
        }
    }

    /*
     * EVENT HOOKS
     *
     */

    /**
     * Hook for E:AnalyticsTick from Gdn_Statistics
     *
     * This hook handles checking and expiring potatoes currently in play. It
     * should be executed roughly once per self::POTATO_CHECK_FREQ seconds, and
     * has a built-in anti-dupe feature to prevent simultaneous execution.
     *
     * @param Gdn_Statistics $sender
     * @return type
     */
    public function Gdn_Statistics_AnalyticsTick_Handler($sender) {
        if (!$this->enabled) {
            return;
        }

        // If the key exists, don't check
        $locked = Gdn::cache()->get(self::POTATO_CHECK_KEY, [
            Gdn_Cache::FEATURE_LOCAL => false
        ]);
        if ($locked) {
            return;
        }

        // Set checking lock
        $lock = uniqid();
        Gdn::cache()->store(self::POTATO_CHECK_KEY, $lock, [
            Gdn_Cache::FEATURE_EXPIRY => self::POTATO_CHECK_FREQ
        ]);

        // Mutex
        $locked = Gdn::cache()->get(self::POTATO_CHECK_KEY, [
            Gdn_Cache::FEATURE_LOCAL => false
        ]);
        if ($locked != $lock) {
            return;
        }

        // Get all active potatoes
        $potatos = self::potatoModel()->getWhere([
            'Status' => 'active'
        ])->resultArray();
        if (!count($potatos)) {
            return;
        }

        // Check them!
        foreach ($potatos as $potato) {
            // Get current holder
            $holder = $this->holder($potato['hash']);

            // No holder? Finish.
            if (!$holder) {
                $this->finish($potato);
                continue;
            }

            // Holder dropped it?
            //if ($holder[''])
        }
    }

    /*
     * LIBRARY
     *
     */

    /**
     * Create a new potato
     *
     * @param string $name
     * @param integer $duration
     * @param array $user
     * @param array $discussion
     * @return string potato id hash
     */
    public function newPotato($name, $duration, $user, $discussion) {

        // Check if a potato with this name already exists and is active
        $potato = $this->findPotato($name, true);
        if ($potato) {
            return false;
        }

        // Create a new potato
        $potatoHash = md5($name);
        $expiry = time() + $duration;
        $hold = strtotime('+'.C('Plugins.HotPotato.Hold', '8 hours')) - time();
        $potato = [
            'Name' => $name,
            'Hash' => $potatoHash,
            'Status' => 'active',
            'Duration' => $duration,
            'Hold' => $hold,
            'Expiry' => $expiry,
            'Passes' => 0,
            'Misses' => 0,
            'InsertUserID' => $user['UserID']
        ];
        $potatoID = self::potatoModel()->save($potato);
        $potato['PotatoID'] = $potatoID;
        $this->potatoes[$potatoID] = $potato;

        // Assign to current user
        self::potatoLogModel()->save([
            'UserID' => $user['UserID'],
            'PotatoID' => $potato['PotatoID'],
            'TimeReceived' => time(),
            'ReceivedDiscussionID' => $discussion['DiscussionID'],
            'Passed' => 'holding'
        ]);

        MinionPlugin::instance()->monitor();

        return $potato;
    }

    /**
     * Find an activate potato with this name
     *
     * @param string $name
     * @return array|false potato row, or false
     */
    public function findPotato($name) {
        $hash = md5($name);
        $potato = self::potatoModel()->getHash($hash, true);

        return $potato ? $potato : false;
    }

    /**
     * Get a potato
     *
     * @param string $potatoID
     * @param boolean $hardCheck force database/memcache check
     */
    public function getPotato($potatoID, $hardCheck = false) {
        $potato = null;

        if (!$hardCheck) {
            $potato = val($potatoID, $this->potatoes, null);

            // Known non potato
            if ($potato === false) {
                return false;
            }
        }

        // Unknown, query
        if (!$potato) {
            $potato = self::potatoModel()->getID($potatoID, DATASET_TYPE_ARRAY);
            if ($potato) {
                $this->potatoes[$potatoID] = $potato;
            }
        }
        return $potato;
    }

    /**
     * Save a modified potato
     *
     * @param array $potato
     * @return array the modified potato
     */
    public function savePotato($potato) {
        $saved = self::potatoModel()->save($potato);
        if ($saved) {
            $potatoID = $potato['PotatoID'];
            $this->potatoes[$potatoID] = $potato;
        }

        return $potato;
    }

    /**
     * Process a toss from someone to someone
     *
     * @param array $potato
     * @param array $to
     * @param array $from
     * @param array $discussion the location where the toss took place
     * @param boolean $voluntary optional. whether this was a voluntary toss, or a drop
     */
    public function toss($potato, $to, $from, $discussion, $voluntary = true) {

        $holder = $this->holder($potato['PotatoID']);
        $holder['ReceiverID'] = $to['UserID'];

        // Increment potato toss counter
        if ($voluntary) {
            $potato['Passes']++;
            $holder['Passed'] = 'passed';
        } else {
            $potato['Misses']++;
            $holder['Passed'] = 'dropped';
        }
        $this->savePotato($potato);

        $holder['TimePassed'] = time();
        $holder['Held'] = $holder['TimePassed'] - $holder['TimeReceived'];

        // Log potato transactions

        // Record new recipient
        $expiry = time() + $potato['Hold'];
        if ($potato['Expiry'] < $expiry) {
            $expiry = $potato['Expiry'];
        }
        MinionPlugin::instance()->monitor($to, [
            'hotpotato' => [
                'id' => $potato['PotatoID'],
                'expiry' => time() + $potato['Hold']
            ]
        ]);
        self::potatoLogModel()->save([
            'UserID' => $to['UserID'],
            'PotatoID' => $potato['PotatoID'],
            'TimeReceived' => time(),
            'ReceivedDiscussionID' => $discussion['DiscussionID'],
            'PasserID' => $from['UserID'],
            'Passed' => 'holding'
        ]);

        // Update old recipient
        MinionPlugin::instance()->monitor($from, [
            'hotpotato' => null
        ]);
        self::potatoLogModel()->save($holder);
    }

    /**
     * Finish a potato
     *
     * @param array $potato
     */
    public function finish($potato) {
        $potato['Status'] = 'inactive';
        self::potatoModel()->save($potato);


    }

    /**
     * Check if a certain user has already received this potato
     *
     * @param array $potato
     * @param array $recipient
     * @return array|false holder row, or false
     */
    public function hasReceived($potato, $recipient) {
        $holder = self::potatoLogModel()->getWhere([
            'PotatoID' => $potato['PotatoID'],
            'UserID' => $recipient['UserID']
        ])->firstRow(DATASET_TYPE_ARRAY);

        return $holder ? $holder : false;
    }

    /**
     * Get the current holding row for this potato
     *
     * @param array $potatoID
     * @return array|false holder row, or false
     */
    public function holder($potatoID) {
        $holder = self::potatoLogModel()->getWhere([
            'PotatoID' => $potatoID,
            'Passed' => 'holding'
        ])->firstRow(DATASET_TYPE_ARRAY);

        return $holder ? $holder : false;
    }

    /**
     * Check if the given user is holding a potato
     *
     * @param integer $userID
     * @return array|false holder row, or false
     */
    public function holding($userID) {
        $holder = self::potatoLogModel()->getWhere([
            'UserID' => $userID,
            'Passed' => 'holding'
        ])->firstRow(DATASET_TYPE_ARRAY);

        return $holder ? $holder : false;
    }

    /**
     * Get a PotatoModel
     *
     * @staticvar PotatoModel $potatoModel
     * @return PotatoModel
     */
    public static function potatoModel() {
        static $potatoModel = null;
        if (!is_a($potatoModel, 'PotatoModel')) {
            $potatoModel = new PotatoModel();
        }
        return $potatoModel;
    }

    /**
     * Get a PotatoLogModel
     *
     * @staticvar PotatoLogModel $potatoLogModel
     * @return PotatoLogModel
     */
    public static function potatoLogModel() {
        static $potatoLogModel = null;
        if (!is_a($potatoLogModel, 'PotatoLogModel')) {
            $potatoLogModel = new PotatoLogModel();
        }
        return $potatoLogModel;
    }

    /**
     * On-enable setup
     */
    public function setup() {
        $this->structure();
    }

    /**
     * Run database structure modifications
     *
     * This is executed once when the plugin is enabled, and also whenever
     * /utility/update or /utility/structure is called.
     */
    public function structure() {
        Gdn::structure()->reset();

        Gdn::structure()->table('Potato')
            ->primaryKey('PotatoID')
            ->column('Name', 'varchar(64)', false, 'index')
            ->column('Hash', 'varchar(64)', false, 'index')
            ->column('Status', ['active', 'inactive', 'completed'], 'active')
            ->column('Duration', 'int(11)', false)
            ->column('Hold', 'int(11)', false)
            ->column('Expiry', 'int(11)', false)
            ->column('Passes', 'int(11)', 0)
            ->column('Misses', 'int(11)', 0)
            ->column('InsertUserID', 'int(11)', false)
            ->column('DateInserted', 'datetime', false)
            ->set(false, false);

        Gdn::structure()->table('PotatoLog')
            ->primaryKey('PotatoLogID')
            ->column('UserID', 'int(11)', false, 'index')
            ->column('PotatoID', 'int(11)', false, 'index')
            ->column('TimeReceived', 'int(11)', false)
            ->column('ReceivedDiscussionID', 'int(11)', false)
            ->column('PasserID', 'int(11)', true)
            ->column('ReceiverID', 'int(11)', true)
            ->column('Passed', ['holding','dropped','passed','forfeit'], 'holding')
            ->column('TimePassed', 'int(11)', true)
            ->column('Held', 'inf(11)', 0)
            ->set(false, false);
    }

}
