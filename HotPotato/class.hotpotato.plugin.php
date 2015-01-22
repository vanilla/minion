<?php

/**
 * @copyright 2010-2014 Vanilla Forums Inc
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
        'Online' => '1.7',
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
 * This plugin uses Minion and Badges to create a forum game that resembles the
 * Hot Potato game played by children.
 *
 * Moderators can create new potatos, naming them in whatever manner pleases them,
 * and can pass those to other forum members. Those forum members can pass them on
 * amongst each other.
 *
 * Each potato has 2 timers: the total lifetime and the "hold" length. Once a potato
 * reaches the total lifetime timer it is destroyed, and whoever is currently holding
 * it receives a penalty (1 point warning). Any member who receives a potato and
 * does not pass it within the time specified by the "hold" timer is also penalized
 * with a 1 point infraction, and the potato is automatically passed to a random
 * "nearby" forum member. Each pass resets the "hold" timer, but the lifetime timer
 * ticks down sequentially.
 *
 * Creation syntax (for mods) is:
 *   Minion, toss|pass|lob|hurl|throw|chuck a|the|this|some foul smelling rat carcass at|to citizen Weaver
 *   Minion, toss|pass|lob|hurl|throw|chuck a|the|this|some dripping wet human arm to user Weaver
 *   Minion, toss|pass|lob|hurl|throw|chuck a|the|this|some charbroiled octopus at @Weaver
 *
 * Passing syntax (for users) is:
 *   Minion, toss|pass|lob|hurl|throw|chuck a|the|this|some <whatever, this part doesnt matter> at|to citizen Tube
 *
 * Several badger based achievements are possible.
 *
 * Badgers:
 *
 *  Typhoid Mary - Take a potato from one category to another
 *  Billy the Kid - Pass a potato in under 60 seconds
 *  Hurt Locker - Receive a potato with less than 30 seconds remaining on the clock
 *  EOD - Dispose of a potato which had less than 30 seconds to detonate
 *  Many Hands - Be part of a potato chain that hits 100 people
 *  Potato Farmer - Receive 10 potatoes
 *  Hospital Pass - Pass a potato that expires and causes the recipient to go to jail
 *
 * Changes:
 *  1.0a    Development
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package minion
 * @subpackage hotpotato
 */
class HotPotatoPlugin extends Gdn_Plugin {

    const POTATO_KEY = 'minion.hotpotato.potato.%s';

    const POTATO_CHECK_FREQ = 60;
    const POTATO_CHECK_KEY = 'minion.hotpotato.check';

    const ONLINE_MINUTES_AGO = 60;

    const AUTO_RECIPIENT_LIMIT = 30;

    /**
     * List of throwing actions
     * @var array
     */
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
     * List of potato honorifics
     * @var array
     */
    protected $honorifics = [
        'blessed',
        'glorious',
        'divine',
        'hallowed',
        'sacred',
        'holy',
        'dazzling',
        'magnificent',
        'splendid',
        'effulgent',
        'majestic',
        'resplendent'
    ];

    /**
     * List of concessions
     * @var array
     */
    protected $mistakes = [
        'Mistakes were made',
        'These things happen',
        'Meatbags are imperfect beings',
        'You win some you lose some'
    ];

    /**
     * List of transfer rituals
     * @var array
     */
    protected $rituals = [
        'the Ritual of Starchy Transference',
        'Ancient Tuberous Rites',
        'Forced Gluten Intake',
        'the Ceremony of Deep Frying',
        'the Parade of Perfectly Sliced French Fries',
        'the Ritual of Boiling',
        'the Sacrifice of Hash Browns',
        'the Blessing of AXOMAMMA',
        'Summoning of Garlic Mash'
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
    public function minionPlugin_token_handler($sender) {
        $state = &$sender->EventArguments['State'];

        // Start hot potato
        if (!$state['Method'] && in_array($state['CompareToken'], [
            'toss',
            'pass',
            'throw',
            'hurl',
            'lob',
            'chuck'
        ])) {
            $sender->consume($state, 'Method', 'hotpotato');
        }

        // Get potato name
        if ($state['Method'] == 'hotpotato') {
            if (valr('Gather.Node', $state, null) != 'Potato') {
                if (in_array($state['CompareToken'], ['a','an','this','some','the'])) {
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
    public function minionPlugin_command_handler($sender) {
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
    public function minionPlugin_action_handler($sender) {
        $action = $sender->EventArguments['Action'];
        $state = $sender->EventArguments['State'];

        switch ($action) {

            case 'hotpotato':

                $from = &$state['Sources']['User'];
                $givenPotatoName = valr('Targets.Potato', $state, null);

                if (!key_exists('User', $state['Targets']) || !val('User.UserID', $state['Targets'])) {
                    $sender->acknowledge(null, T('You must supply a valid target user.'), 'custom', $from, [
                        'Comment' => false
                    ]);
                    break;
                }

                // Check discussion ability to participate in potato warfare

                $discussion = (array)val('Discussion', $state['Sources']);
                $comment = (array)val('Comment', $state['Sources']);

                // Category doesn't quality (comments section)

                $bannedCategories = C('Plugins.HotPotato.DMZ', []);
                if (count($bannedCategories)) {
                    $categoryID = val('CategoryID', $discussion, null);
                    if ($categoryID && in_array($categoryID, $bannedCategories)) {
                        $sender->acknowledge(null, T("Sorry, this fascist category is opposed to potato based-fun!"), 'custom', $from, [
                            'Comment' => false
                        ]);
                        break;
                    }

                    $category = CategoryModel::categories($categoryID);
                    $parentCategoryID = val('ParentCategoryID', $category, null);
                    if ($parentCategoryID && in_array($parentCategoryID, $bannedCategories)) {
                        $sender->acknowledge(null, T("Sorry, this fascist category is opposed to potato-based fun!"), 'custom', $from, [
                            'Comment' => false
                        ]);
                        break;
                    }
                }

                // Check target ability to receive potatos

                // Target doesn't qualify (already has a potato)
                $to = &$state['Targets']['User'];
                $targetHasPotato = $this->holding($to['UserID']);
                if ($targetHasPotato) {
                    $targetPotatoID = val('PotatoID', $targetHasPotato, null);
                    $targetPotato = $this->getPotato($targetPotatoID);
                    $sender->acknowledge(null, T("{Target.Mention} is already holding a {Honorific} <b>{Potato.Name}</b>!"), 'custom', $from, [
                        'Comment' => false
                    ], [
                        'Honorific' => $this->getHonorific(),
                        'Target' => MinionPlugin::formatUser($to),
                        'Potato' => $targetPotato
                    ]);
                    break;
                }

                // Target doesn't qualify (bot)
                if ($to['Admin'] == 2) {
                    $sender->acknowledge(null, T("{Target.Mention} is a mechanical unit."), 'custom', $from, [
                        'Comment' => false
                    ], [
                        'Target' => MinionPlugin::formatUser($to)
                    ]);
                    break;
                }

                // Target doesn't qualify (bot)
                if ($to['Punished']) {
                    $sender->acknowledge(null, T("{Target.Mention} is currently on a time-out."), 'custom', $from, [
                        'Comment' => false
                    ], [
                        'Target' => MinionPlugin::formatUser($to)
                    ]);
                    break;
                }

                // Target doesn't qualify (low rank)
                if ($to['RankID'] == 1) {
                    $sender->acknowledge(null, T("{Target.Mention} is a little too new here to be trusted with a {Honorific} <b>{Potato.Name}</b>."), 'custom', $from, [
                        'Comment' => false
                    ], [
                        'Honorific' => $this->getHonorific(),
                        'Target' => MinionPlugin::formatUser($to),
                        'Potato' => [
                            'Name' => $givenPotatoName
                        ]
                    ]);
                    break;
                }

                // Target doesn't qualify (not online recently)
                $onlineAfter = time() - (self::ONLINE_MINUTES_AGO * 60);
                if (!(strtotime($to['LastOnlineDate']) > $onlineAfter) && !(strtotime($to['DateLastActive']) > $onlineAfter)) {
                    $sender->acknowledge(null, T("I haven't seen {Target.Mention} around recently, pick someone more active to receive your <b>{Potato.Name}</b> blessing!"), 'custom', $from, [
                        'Comment' => false
                    ], [
                        'Target' => MinionPlugin::formatUser($to),
                        'Potato' => [
                            'Name' => $givenPotatoName
                        ]
                    ]);
                    break;
                }

                $potato = false;

                // Check source ability to throw potato

                // Source doesn't qualify (potato not good/expired)
                $haveHotPotato = $this->holding($from['UserID']);
                if ($haveHotPotato) {
                    $potatoID = val('PotatoID', $haveHotPotato, null);
                    $potato = $this->getPotato($potatoID);

                    // Check this potato!
                    if ($potato) {
                        $potatoOk = $this->checkPotato($potato, $from);
                        if (!$potatoOk) {
                            $sender->acknowledge(null, T('The {Honorific} <b>{Potato.Name}</b> slips from your hand as you toss it!'), 'custom', $from, [
                                'Comment' => false
                            ],[
                                'Honorific' => $this->getHonorific(),
                                'Potato' => $potato
                            ]);
                            break;
                        }
                    }
                }

                // Moderators may create new potatos

                if (!$potato && Gdn::session()->checkPermission('Garden.Moderation.Manage')) {

                    // Command invalid (no name)
                    if (!$givenPotatoName) {
                        $sender->acknowledge(null, T('You must supply a name for the new thing you want to toss!'), 'custom', $from, [
                            'Comment' => false
                        ]);
                        break;
                    }

                    // Command invalid (name taken)
                    $potatoExists = $this->findPotato($givenPotatoName);
                    if ($potatoExists) {
                        $sender->acknowledge(null, T("It looks like there's already a {Honorific} <b>{Potato.Name}</b> floating around, and you aren't holding it!"), 'custom', $from, [
                            'Comment' => false
                        ],[
                            'Honorific' => $this->getHonorific(),
                            'Potato' => $potatoExists
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
                        $duration = strtotime('+'.C('Plugins.HotPotato.Duration', '3 hours'));
                        $duration = $duration - time();
                    }

                    if (!is_integer($duration)) {
                        $duration = 84600;
                    }
                    $potato = $this->newPotato($givenPotatoName, $duration, $from, $discussion);
                    $potato['isnew'] = true;
                }

                // No potato, or failed to create potato
                if (!$potato) {
                    $sender->acknowledge(null, T("You're not holding anything right now!"), 'custom', $from, [
                        'Comment' => false
                    ]);
                    break;
                }

                // Target doesn't qualify (already received this potato)
                if ($this->hasReceived($potato, $to)) {
                    $sender->acknowledge(null, T("Don't you think someone new deserves alone time with that {Honorific} <b>{Potato.Name}</b>?"), 'custom', $from, [
                        'Comment' => false
                    ],[
                        'Honorific' => $this->getHonorific(),
                        'Potato' => $potato
                    ]);
                    break;
                }

                // Issue minion comment

                $honorific = $this->getHonorific();
                $starts = $honorific[0];
                if (stristr('aeiouy', $starts) !== false) {
                    $connector = 'an';
                } else {
                    $connector = 'a';
                }

                $minionComment = $sender->acknowledge($state['Sources']['Discussion'], T("{From.Mention} {Throws} {Connector} {Honorific} <b>{Potato.Name}</b> in {To.Mention} 's general direction."), 'custom', $from, [
                    'Comment' => true
                ],[
                    'To' => MinionPlugin::formatUser($to),
                    'From' => MinionPlugin::formatUser($from),
                    'Throws' => $this->getThrow(),
                    'Connector' => $connector,
                    'Honorific' => $honorific,
                    'Potato' => $potato
                ]);

                // Delete self comment
                if (!key_exists('isnew', $potato)) {
                    $commentModel = new CommentModel();
                    $commentModel->delete($comment['CommentID']);
                }

                // Toss potato
                $this->toss($potato, $to, $from, $discussion, $minionComment, true);

                break;
        }
    }

    /*
     * METHODS
     */

    public function pluginController_hotPotato_create($sender) {
        $this->dispatch($sender, $sender->RequestArgs);
    }

    /**
     * Handle timer dismissal
     *
     * @param PluginController $sender
     */
    public function controller_dismiss($sender) {
        $sender->deliveryMethod(DELIVERY_METHOD_JSON);
        $sender->deliveryType(DELIVERY_TYPE_DATA);

        $user = (array)Gdn::Session()->User;
        $hotpotato = MinionPlugin::instance()->monitoring($user, 'hotpotato', false);
        if (is_array($hotpotato)) {
            $hotpotato['dismissed'] = true;
            MinionPlugin::instance()->monitor($user, [
                'hotpotato' => $hotpotato
            ]);
        }

        $sender->render();
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
     * has a built-in anti-concurrency lock to prevent simultaneous execution.
     *
     * @param Gdn_Statistics $sender
     * @return type
     */
    public function gdn_statistics_analyticsTick_handler($sender) {
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

        $this->upkeep();
    }

    /**
     * Display PM timer output
     *
     * @param Gdn_Controller $sender
     */
    public function base_render_before($sender) {
        if (!$this->enabled) {
            return;
        }
        if ($sender->deliveryType() != DELIVERY_TYPE_ALL) {
            return;
        }
        if (!Gdn::session()->isValid()) {
            return;
        }

        $user = (array)Gdn::session()->User;

        // Timer deployment
        $hotpotato = MinionPlugin::instance()->monitoring($user, 'hotpotato', false);
        if ($hotpotato) {

            // Timer has been dismissed
            if (val('dismissed', $hotpotato, false)) {
                return;
            }

            $holder = $this->holding($user['UserID']);
            $potato = $this->getPotato($holder['PotatoID']);
            if (!$potato) {
                MinionPlugin::instance()->monitor($user, [
                    'hotpotato' => null
                ]);
                return;
            }

            $timer = $this->getTimer($potato, $holder);

            $sender->addDefinition('PotatoExpiry', $timer);
            $sender->addDefinition('PotatoName', $potato['Name']);
            $sender->addDefinition('PotatoBot', MinionPlugin::instance()->minionName());
            $sender->addJsFile('hotpotato.js', 'plugins/HotPotato');
        }
    }

    /*
     * LIBRARY
     *
     */

    /**
     * Run upkeep on current potatos
     *
     * Check for expired potatos, dropped potatos, old potatos. Inspect
     * non-checked potato logs for achievements.
     *
     * @return void
     */
    public function upkeep() {

        // Get all active potatoes
        $activePotatoes = self::potatoModel()->getWhere([
            'Status' => 'active'
        ])->resultArray();
        if (count($activePotatoes)) {

            // Check them for important events
            foreach ($activePotatoes as $potato) {

                // Get current holder
                $holder = $this->holder($potato['PotatoID']);

                // No holder? Deactivate.
                if (!$holder) {
                    $this->deactivate($potato);
                    continue;
                }

                $from = Gdn::userModel()->getID($holder['UserID'], DATASET_TYPE_ARRAY);

                // Potato expired? Fumble and deactivate.
                if ($potato['Expiry'] < time()) {
                    $this->fumble($potato, $from, false);
                    continue;
                }

                // Holder dropped it? Pass it on.
                $heldFor = time() - $holder['TimeReceived'];
                if ($heldFor > $potato['Hold']) {
                    $this->fumble($potato, $from);
                    continue;
                }
            }
        }

        sleep(1);

        // Get all unchecked log entries
        $logs = self::potatoLogModel()->getWhere([
            'Checked' => 0,
            'Passed' => [
                'thrown',
                'passed',
                'dropped',
                'forfeit'
            ]
        ])->resultArray();
        if (count($logs)) {
            // Process them for achievements / punishments

            /*
             *  Typhoid Mary    - Take a potato from one category to another
             *  Billy the Kid   - Pass a potato in under 60 seconds
             *  Hurt Locker     - Receive a potato with less than 30 seconds remaining on the clock
             *  EOD             - Dispose of a potato which had an expiry under 30 seconds
             *  Potato Farmer   - Receive 10 potatoes
             *  Hospital Pass   - Pass a potato that expires and causes the recipient to go to jail
             */

            foreach ($logs as $log) {

                // Skip #1
                if (!empty($log['PasserID'])) {

                    // Get and check potato
                    $potato = $this->getPotato($log['PotatoID']);
                    if (!$potato) {
                        self::potatoLogModel()->delete([
                            'PotatoLogID' => $log['PotatoLogID']
                        ]);
                        continue;
                    }

                    $user = Gdn::userModel()->getID($log['UserID'], DATASET_TYPE_ARRAY);
                    if (($potato['Expiry'] - $log['TimeReceived']) <= 30) {
                        // Hurt Locker
                        $this->award($user, 'hurtlocker');
                    }
                    $userWasJailed = $user['Punished'];

                    switch ($log['Passed']) {
                        case 'thrown':
                            break;

                        case 'passed':

                            if ($log['ReceivedDiscussionID'] != $log['PassedDiscussionID']) {
                                $receivedDiscussion = (array)self::discussionModel()->getID($log['ReceivedDiscussionID'], DATASET_TYPE_ARRAY);
                                $passedDiscussion = (array)self::discussionModel()->getID($log['PassedDiscussionID'], DATASET_TYPE_ARRAY);

                                if ($receivedDiscussion && $passedDiscussion && $receivedDiscussion['CategoryID'] != $passedDiscussion['CategoryID']) {
                                    // Typhoid Mary
                                    $this->award($user, 'typhoidmary');
                                }
                            }
                            if ($log['Held'] < 60) {
                                // Billy the Kid
                                $this->award($user, 'billythekid');
                            }

                            $expiresIn = ($potato['Expiry'] - $log['TimeReceived']);
                            if ($expiresIn <= 30) {
                                // EOD
                                $this->award($user, 'eod');
                            }
                            break;

                        case 'dropped':
                        case 'forfeit':

                            $discussion = (array)self::discussionModel()->getID($log['ReceivedDiscussionID'], DATASET_TYPE_ARRAY);

                            // Punish
                            if ($log['passed'] == 'dropped') {
                                $reason = formatString(T("Hoarding the {Honorific} <b>{Potato.Name}</b>"), [
                                    'Honorific' => $this->getHonorific(),
                                    'Potato' => $potato
                                ]);
                            }
                            if ($log['passed'] == 'forfeit') {
                                $reason = formatString(T("Being caught holding the {Honorific} <b>{Potato.Name}</b>"), [
                                    'Honorific' => $this->getHonorific(),
                                    'Potato' => $potato
                                ]);
                            }
                            MinionPlugin::instance()->punish($user, null, null, MinionPlugin::FORCE_MEDIUM, [
                                'Reason' => $reason,
                                'Points' => 1,
                                'Invoker' => MinionPlugin::instance()->minion()
                            ]);

                            $user = Gdn::userModel()->getID($log['UserID'], DATASET_TYPE_ARRAY);
                            if (!$userWasJailed && $user['Punished']) {
                                // Hospital Pass
                                $passer = Gdn::userModel()->getID($log['PasserID'], DATASET_TYPE_ARRAY);
                                $this->award($passer, 'hospitalpass');
                            }
                            break;
                    }
                }

                $log['Checked'] = 1;
                self::potatoLogModel()->save($log);
            }
        }

        sleep(1);

        // Get all inactive potatoes
        $inactivePotatoes = self::potatoModel()->getWhere([
            'Status' => 'inactive'
        ])->resultArray();
        if (count($inactivePotatoes)) {

            // Process them for achivements
            foreach ($inactivePotatoes as $potato) {
                $this->process($potato);
            }
        }
    }

    /**
     * Create a new potato
     *
     * @param string $name name of potato
     * @param integer $duration how many seconds this potato lasts for
     * @param array $user creating user
     * @param array $discussion discussion where potato originated
     * @return array new potato
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
        $hold = strtotime('+'.C('Plugins.HotPotato.Hold', '10 minutes')) - time();
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
            'Passed' => 'thrown',
            'Held' => 0
        ]);

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
     * Test if a potato is ok to be tossed
     *
     * @param array $potato
     * @param array $from
     */
    public function checkPotato($potato, $from) {
        if ($potato['Status'] != 'active' || $potato['Expiry'] < time()) {
            $this->fumble($potato, $from);
            return false;
        }

        return true;
    }

    /**
     * Process a toss from someone to someone
     *
     * @param array $potato
     * @param array $to
     * @param array $from
     * @param array $discussion optional. the location where the toss took place. if not supplied, same as $holder['ReceivedDiscussionID']
     * @param array $comment optional. the comment that caused the toss. if not supplied, minion makes a post to the discussion
     * @param boolean $voluntary optional. whether this was a voluntary toss, or a drop
     */
    public function toss(&$potato, $to, $from, $discussion = null, $comment = null, $voluntary = true) {

        $holder = $this->holder($potato['PotatoID']);

        // Stay in the discussion we received it in, if fumbling
        if (is_null($discussion)) {
            $discussion = (array)self::discussionModel()->getID($holder['ReceivedDiscussionID'], DATASET_TYPE_ARRAY);
        }

        // Update old holder
        $holder['TimePassed'] = time();
        $holder['Held'] = $holder['TimePassed'] - $holder['TimeReceived'];
        $holder['PassedDiscussionID'] = $discussion['DiscussionID'];

        // Increment potato toss counter
        if ($voluntary) {
            $potato['Passes']++;
            if ($holder['Passed'] != 'thrown') {
                $holder['Passed'] = 'passed';
            }
        } else {
            $potato['Misses']++;
            $holder['Passed'] = is_null($to) ? 'forfeit' : 'dropped';
        }
        $this->savePotato($potato);

        // Log potato transactions

        if (!is_null($to)) {
            // Record new recipient
            $holder['ReceiverID'] = $to['UserID'];
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
            $toPotatoLogID = self::potatoLogModel()->save([
                'UserID' => $to['UserID'],
                'PotatoID' => $potato['PotatoID'],
                'TimeReceived' => time(),
                'ReceivedDiscussionID' => $discussion['DiscussionID'],
                'PasserID' => $from['UserID'],
                'Passed' => 'holding',
                'Held' => 0
            ]);
            $toPotatoLog = self::potatoLogModel()->getID($toPotatoLogID, DATASET_TYPE_ARRAY);
        } else {
            $toPotatoLog = null;
        }

        // Update old recipient
        MinionPlugin::instance()->monitor($from, [
            'hotpotato' => null
        ]);
        $fromPotatoLogID = self::potatoLogModel()->save($holder);
        $fromPotatoLog = self::potatoLogModel()->getID($fromPotatoLogID, DATASET_TYPE_ARRAY);

        if (is_null($comment)) {
            if (!is_null($to)) {
                $comment = MinionPlugin::instance()->acknowledge($discussion, T("The {Honorific} <b>{Potato.Name}</b> has been transferred from {From.Mention} to {To.Mention} via {Ritual}. {Mistake}... {From.Mention}"), 'custom', $from, null, [
                    'To' => MinionPlugin::formatUser($to),
                    'From' => MinionPlugin::formatUser($from),
                    'Honorific' => $this->getHonorific(),
                    'Mistake' => $this->getMistake(),
                    'Ritual' => $this->getRitual(),
                    'Potato' => $potato
                ]);
            } else {
                $comment = MinionPlugin::instance()->acknowledge($discussion, T("The {Honorific} <b>{Potato.Name}</b> was dropped by {From.Mention}! {Mistake}..."), 'custom', $from, null, [
                    'From' => MinionPlugin::formatUser($from),
                    'Honorific' => $this->getHonorific(),
                    'Mistake' => $this->getMistake(),
                    'Ritual' => $this->getRitual(),
                    'Potato' => $potato
                ]);
            }
        }

        // Handle notifications, instant toss achievements
        $this->tossed($potato, $comment, $from, $fromPotatoLog, $to, $toPotatoLog);
    }

    /**
     * Handle toss notifications
     *
     * @param array $potato
     * @param array $comment
     * @param array $from
     * @param array $fromLog
     * @param array $to optional.
     * @param array $toLog optional.
     */
    public function tossed(&$potato, $comment, $from, $fromLog, $to = null, $toLog = null) {

        if (!is_null($to)) {

            $honorific = $this->getHonorific();
            $starts = $honorific[0];
            if (stristr($starts, 'aeiouy') !== false) {
                $connector = 'an';
            } else {
                $connector = 'a';
            }

            // Notify
            $activity = [
                'ActivityUserID' => $from['UserID'],
                'NotifyUserID' => $to['UserID'],
                'HeadlineFormat' => T("{ActivityUserID,user} has tossed {Data.Connector} {Data.Honorific} <b>{Data.Potato.Name}</b> at you. You have <b>{Data.Time}</b> to pass it on!"),
                'RecordType' => 'Comment',
                'RecordID' => $comment['CommentID'],
                'Route' => commentUrl($comment),
                'Data' => [
                    'Connector' => $connector,
                    'Honorific' => $honorific,
                    'Time' => $this->getTimer($potato, $toLog, true),
                    'Potato' => $potato
                ]
            ];
            $this->activity($activity);
        }

    }

    /**
     * Handle potato drops
     *
     * @param array $potato potato record
     * @param array $from passing user
     * @param boolean whether to pass this potato to someone else
     */
    public function fumble(&$potato, $from, $pass = true) {

        // Potato expired? This won't be a pass
        if ($pass && $potato['Expiry'] < time()) {
            $pass = false;
        }

        $holder = $this->holder($potato['PotatoID']);
        $discussion = (array)self::discussionModel()->getID($holder['ReceivedDiscussionID'], DATASET_TYPE_ARRAY);

        $to = null;
        if ($pass) {
            // Determine new potato receipient
            $tries = self::AUTO_RECIPIENT_LIMIT;
            $exclude = [];
            $to = null;
            do {
                $person = $this->getAutoRecipient($discussion, $exclude);
                $tries--;
                if (!$person) {
                    break;
                }

                if (!$this->canReceive($potato, $person)) {
                    if ($tries < 1) {
                        break;
                    }

                    $exclude[] = $person['UserID'];
                    continue;
                }
                $to = $person;

            } while(is_null($to));
        }

        // Toss, and deactivate if we couldn't find someone
        $this->toss($potato, $to, $from, null, null, false);
        if (!$to) {
            $this->deactivate($potato);
        }
    }

    /**
     * Get the next available recipient
     *
     * @param array $discussion
     * @param array $exclude optional. list of userids to exclude.
     * @return array user
     */
    public function getAutoRecipient($discussion, $exclude = null) {

        $lastActiveDate = date('Y-m-d H:i:s', time() - (self::ONLINE_MINUTES_AGO * 60));
        $query = Gdn::sql()->select(['UserID', 'DateLastActive'])
                ->from('User')
                ->where('DateLastActive>', $lastActiveDate)
                ->limit(self::AUTO_RECIPIENT_LIMIT * 2)
                ->orderBy('DateLastActive,Points', 'desc');

        $candidates = $query->get();

        $row = null;
        while ($candidate = $candidates->nextRow(DATASET_TYPE_ARRAY)) {
            if (in_array($candidate['UserID'], $exclude)) {
                continue;
            }

            $row = $candidate;
            break;
        }

        if (!$row) {
            return false;
        }

        /*
        $timeSlot = gmdate('Y-m-d', Gdn_Statistics::timeSlotStamp('w', false));

        $query = Gdn::sql()->select('*')->from('UserPoints')
            ->where([
                'TimeSlot' => $timeSlot,
                'SlotType' => 'w',
                'Source' => 'Total',
                'CategoryID' => 0
            ]);

        if (is_array($exclude) && count($exclude)) {
            $query->whereNotIn('UserID', $exclude);
        }

        $row = $query->orderBy('Points','desc')
            ->limit(1)
            ->get()
            ->firstRow(DATASET_TYPE_ARRAY);
        */

        $user = Gdn::userModel()->getID($row['UserID'], DATASET_TYPE_ARRAY);
        return $user;
    }

    /**
     * Deactivate a potato
     *
     * @param array $potato
     */
    public function deactivate(&$potato) {
        $potato['Status'] = 'inactive';
        $this->savePotato($potato);
    }

    /**
     * Process an inactive potato to award achievements
     *
     * @param array $potato
     */
    public function process(&$potato) {
        $potato['Status'] = 'completed';
        $this->savePotato($potato);

        // Find forfeit row
        $forfeit = $this->forfeit($potato['PotatoID']);
        $user = Gdn::userModel()->getID($forfeit['UserID'], DATASET_TYPE_ARRAY);
        $discussion = (array)self::discussionModel()->getID($forfeit['ReceivedDiscussionID'], DATASET_TYPE_ARRAY);

        /*
         *  Chain-based achievements
         */

        // Many Hands
        if (($potato['Passes'] + $potato['Misses']) >= 100) {
            $logs = self::potatoLogModel()->getWhere([
                'PotatoID' => $potato['PotatoID']
            ])->resultArray();

            foreach ($logs as $log) {
                $logUser = Gdn::userModel()->getID($log['UserID']);
                $this->award($logUser, 'manyhands');
            }
        }

        // Announce completion
        $countDead = mt_rand(2,80);
        $killing = $countDead.' '.plural($countDead, 'person', 'people');
        MinionPlugin::instance()->acknowledge($discussion, T("The {Honorific} <b>{Potato.Name}</b> came to rest in {Target.Mention} 's hands where it exploded into many tiny pieces, killing {Killing} including {Target.Mention}."), 'custom', $user, null, [
            'Honorific' => $this->getHonorific(),
            'Killing' => $killing,
            'Target' => MinionPlugin::formatUser($user),
            'Potato' => $potato
        ]);
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
     * Check if a target can receive a certain potato
     *
     * @param array $potato
     * @param array $recipient
     * @return boolean
     */
    public function canReceive($potato, $recipient) {

        // No bots
        if ($recipient['Admin'] == 2) {
            return false;
        }

        // No jailed people
        if ($recipient['Punished']) {
            return false;
        }

        // Target doesn't qualify (low rank)
        if ($recipient['RankID'] == 1) {
            return false;
        }

        // Target doesn't qualify (already has a potato)
        if ($this->holding($recipient['UserID'])) {
            return false;
        }

        // Target doesn't quality (not online recently)
        $onlineAfter = time() - (self::ONLINE_MINUTES_AGO * 60);
        if (!(strtotime($recipient['LastOnlineDate']) > $onlineAfter) && !(strtotime($recipient['DateLastActive']) > $onlineAfter)) {
            return false;
        }

        // Target has already received this potato
        if ($this->hasReceived($potato, $recipient)) {
            return false;
        }

        return true;
    }

    /**
     * Get the current holding row for this potato
     *
     * @param integer $potatoID
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
     * Get the forfeit user for this potato
     *
     * @param integer $potatoID
     * @return array|false holder row, or false
     */
    public function forfeit($potatoID) {
        $forfeit = self::potatoLogModel()->getWhere([
            'PotatoID' => $potatoID,
            'Passed' => 'forfeit'
        ])->firstRow(DATASET_TYPE_ARRAY);

        return $forfeit ? $forfeit : false;
    }

    /**
     * Create an activity with defaults
     *
     * @staticvar ActivityModel $activityModel
     * @param array $activity
     */
    protected function activity($activity) {
        static $activityModel = null;
        if (is_null($activityModel)) {
            $activityModel = new ActivityModel();
        }

        $activity = array_merge(array(
            'ActivityType'    => 'HotPotato',
            'Force'           => true,
            'Notified'        => ActivityModel::SENT_PENDING
        ), $activity);
        $activityModel->save($activity);
    }

    /**
     * Get time left to toss
     *
     * This method takes the lower of Potato Expiry or Log Hold Expiry and
     * returns it, either as seconds or in pretty form.
     *
     * @param array $potato
     * @param array $log
     * @param boolean $pretty optional. return human readable time? default false
     */
    public function getTimer($potato, $log, $pretty = false) {
        $expires = $potato['Expiry'];
        $holdExpires = $log['TimeReceived'] + $potato['Hold'];

        $deadline = (($expires < $holdExpires) ? $expires : $holdExpires);
        $seconds = $deadline - time();
        if (!$pretty) {
            return $seconds;
        }

        return $this->formatTime($seconds);
    }

    /**
     * Get human formatted time
     *
     * @param integer $inSeconds
     * @return string
     */
    public function formatTime($inSeconds) {
        $days = $inSeconds ? floor($inSeconds / 84600) : 0;
        $inSeconds -= $days * 84600;

        $hours = $inSeconds ? floor($inSeconds / 3600) : 0;
        $inSeconds -= $hours * 3600;

        $minutes = $inSeconds ? floor($inSeconds / 60) : 0;
        $inSeconds -= $minutes * 60;

        $seconds = $inSeconds;

        $time = [];
        if ($days) {
            $time[] = $days.' '.plural($days, 'day', 'days');
        }

        if ($hours) {
            $time[] = $hours.' '.plural($hours, 'hour', 'hours');
        }

        if ($minutes) {
            $time[] = $minutes.' '.plural($minutes, 'minute', 'minutes');
        }

        if ($seconds || !count($time)) {
            $time[] = $seconds.' '.plural($seconds, 'second', 'seconds');
        }

        return implode(', ', $time);
    }

    /**
     * Get potato handling mistake
     *
     * @return string
     */
    public function getMistake() {
        shuffle($this->mistakes);
        return $this->mistakes[mt_rand(0, count($this->mistakes) - 1)];
    }

    /**
     * Get potato throw style
     *
     * @return string
     */
    public function getThrow() {
        shuffle($this->throws);
        return $this->throws[mt_rand(0, count($this->throws) - 1)];
    }

    /**
     * Get potato honorific
     *
     * @return string
     */
    public function getHonorific() {
        shuffle($this->honorifics);
        return $this->honorifics[mt_rand(0, count($this->honorifics) - 1)];
    }

    /**
     * Get potato transfer ritual
     *
     * @return string
     */
    public function getRitual() {
        shuffle($this->rituals);
        return $this->rituals[mt_rand(0, count($this->rituals) - 1)];
    }

    /**
     * Award a badge to a user
     *
     * @staticvar array $badges
     * @staticvar UserBadgeModel $userBadge
     * @param array $user
     * @param string $badgeSlug
     * @return boolean
     */
    public function award($user, $badgeSlug) {
        static $badges = null;
        static $userBadge = null;

        // Get all potato badges
        if (is_null($badges)) {
            $badgeModel = new BadgeModel();

            $badges = $badgeModel->getWhere([
                'Class' => 'HotPotato'
            ])->resultArray();
            $badges = array_column($badges, null, 'Slug');
        }

        // Look for badge by slug
        if (!key_exists($badgeSlug, $badges)) {
            return false;
        }

        if (is_null($userBadge)) {
            $userBadge = new UserBadgeModel();
        }

        return $userBadge->give($user['UserID'], $badges[$badgeSlug]['BadgeID'], "Playing Hot Potato!");
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
     * Get a DiscussionModel
     *
     * @staticvar DiscussionModel $discussionModel
     * @return DiscussionModel
     */
    public static function discussionModel() {
        static $discussionModel = null;
        if (!is_a($discussionModel, 'DiscussionModel')) {
            $discussionModel = new DiscussionModel();
        }
        return $discussionModel;
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
            ->column('Status', ['active', 'inactive', 'completed'], 'active', 'index.status')
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
            ->column('PassedDiscussionID', 'int(11)', true)
            ->column('PasserID', 'int(11)', true)
            ->column('ReceiverID', 'int(11)', true)
            ->column('Passed', ['thrown','holding','dropped','passed','forfeit'], 'holding', 'index.check')
            ->column('TimePassed', 'int(11)', true)
            ->column('Held', 'int(11)', 0)
            ->column('Checked', 'int(1)', 0, 'index.check')
            ->set(false, false);

        $badgeModel = new BadgeModel();
        $badges = [
            [
                'name' => 'Typhoid Mary',
                'description' => 'Take a potato from one category to another',
                'points' => 10
            ],
            [
                'name' => 'Billy the Kid',
                'description' => 'Pass a potato less than 60 seconds after receiving it',
                'points' => 15
            ],
            [
                'name' => 'Hurt Locker',
                'description' => 'Receive a potato with less than 30 seconds remaining on the clock',
                'points' => 10
            ],
            [
                'name' => 'EOD',
                'description' => 'Dispose of a potato which had less than 30 seconds to detonate',
                'points' => 100
            ],
            [
                'name' => 'Many Hands',
                'description' => 'Be part of a potato chain that hits 100 people',
                'points' => 15
            ],
            [
                'name' => 'Potato Farmer',
                'description' => 'Receive 10 potatoes',
                'points' => 10
            ],
            [
                'name' => 'Hospital Pass',
                'description' => 'Pass a potato that expires and causes the recipient to go to jail',
                'points' => 25
            ]
        ];

        foreach ($badges as $badge) {
            $slug = strtolower(str_replace(' ','',$badge['name']));
            $badgeModel->define([
                'Name' => $badge['name'],
                'Slug' => $slug,
                'Type' => 'Manual',
                'Body' => $badge['description'],
                'Photo' => "http://badges.vni.la/100/hotpotato/{$slug}.png",
                'Points' => $badge['points'],
                'Class' => 'HotPotato',
                'Level' => 1,
                'CanDelete' => 0
            ]);
        }
    }

}
