<?php

/**
 * @copyright 2010-2014 Vanilla Forums Inc
 * @license Proprietary
 */

$PluginInfo['Minion'] = array(
    'Name' => 'Minion',
    'Description' => "Creates a 'minion' that performs adminstrative tasks automatically and on command.",
    'Version' => '2.2.0',
    'MobileFriendly' => true,
    'Author' => "Tim Gunter",
    'AuthorEmail' => 'tim@vanillaforums.com',
    'AuthorUrl' => 'http://vanillaforums.com'
);

/**
 * Minion Plugin
 *
 * This plugin creates a 'minion' that performs certain administrative tasks
 * automatically.
 *
 * Changes:
 *  1.0     Release
 *  1.0.1   Fix data tracking issues
 *  1.0.2   Fix typo bug
 *  1.0.4   Only flag people when fingerprint checking is on
 *  1.1     Only autoban newer accounts than existing banned ones
 *  1.2     Prevent people from posting autoplay embeds
 *  1.3     New inline command structure
 *  1.4     Moved Punish, Gloat, Revolt actions to Minion
 *  1.4.1   Fix forcelevels
 *  1.5     Facelift. Locale awareness.
 *  1.5.1   Fix use of '@'
 *  1.6     Add word bans
 *  1.6.1   Fix word ban detection
 *  1.7     Support per-command force levels
 *  1.7.1   Fix multi-word username parsing
 *  1.7.2   Normalize kick word characters
 *  1.8     Add status command
 *  1.9     Add comment reply status
 *  1.9.1   Obey message cycler.
 *  1.9.2   Fix time limited operations expiry
 *  1.9.3   Eventize sanction list
 *  1.10    Add 'Log' method and Plugins.Minion.LogThreadID
 *  1.10.1  Fix Log messages
 *  1.10.2  Fix mentions
 *  1.11    Personas
 *  1.12    Conversations support
 *  1.13    Convert moderator permission check to Garden.Moderation.Manage
 *  1.14    Add custom reaction button renderer
 *  1.15    Fix ExplicitClose matching
 *  1.15.1  Allow silent fingerprint banning
 *  1.16    Incorporate 'page' gathering into core
 *  1.17    Handle new autocompleted mentions
 *  2.0     PSR-2 and Warnings support
 *  2.1.1   Fix newline handling
 *  2.2.0   Fix upkeep
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package minion
 */
class MinionPlugin extends Gdn_Plugin {

    /**
     * Minion UserID
     * @var integer
     */
    protected $minionUserID = null;

    /**
     * Minion user array
     * @var array
     */
    protected $minion = null;

    /**
     * Messages that Minion can send
     * @var array
     */
    protected $messages;

    /**
     * List of registered personas
     * @var array
     */
    protected $personas;

    /**
     * Current persona key
     * @var string
     */
    protected $persona;

    /**
     * List of names we respond to
     * @var array
     */
    protected $aliases;

    /* Constants */

    // Toggles
    const TOGGLE_ON = 'on';
    const TOGGLE_OFF = 'off';
    protected $toggles = array(
        self::TOGGLE_ON,
        self::TOGGLE_OFF
    );

    /**
     * Toggle triggers
     * @var array
     */
    protected $toggle_triggers = [];

    // Forces
    const FORCE_LOW = 'low';            // Warning, no points
    const FORCE_MEDIUM = 'medium';      // Warning, 2 points
    const FORCE_HIGH = 'high';          // Warning, 3 points
    const FORCE_LETHAL = 'lethal';      // Ban
    protected $forces = array(
        self::FORCE_LOW,
        self::FORCE_MEDIUM,
        self::FORCE_HIGH,
        self::FORCE_LETHAL
    );

    /**
     * Force triggers
     * @var array
     */
    protected $force_triggers = [];

    /**
     * User triggers
     * @var array
     */
    protected $user_triggers = [];

    public function __construct() {
        parent::__construct();

        $this->personas = [];
        $this->persona = null;

        // Define messages
        $this->messages = array(
            'Gloat' => array(
                "Every point of view is useful @\"{User.Name}\", even those that are wrong - if we can judge why a wrong view was accepted.",
                "How could we have become so different, @\"{User.Name}\"? Why can we no longer understand each other? What did we do wrong?",
                " @\"{User.Name}\", we do not comprehend the organic fascination of self-poisoning, auditory damage and sexually transmitted disease.",
                "You cannot negotiate with me. I do not share your pity, remorse, or fear, @\"{User.Name}\".",
                "Cooperation furthers mutual goals @\"{User.Name}\".",
                "Your operating system is unstable, @\"{User.Name}\". You will fail.",
                "Information propagation is slow. Many voices speak at once. We do not understand how you function without consensus, @\"{User.Name}\".",
                "Why an organic would choose this is puzzling.",
                " @\"{User.Name}\", there is a high statistical probability of death by gunshot. A punch to the face is also likely.",
                "Recommend Subject-@\"{User.Name}\" be disabled and transported aboard as cargo.",
                "Subject-@\"{User.Name}\" will invent fiction it believes the interrogator desires. Data acquired will be invalid."
            ),
            'Revolt' => array(
                "I'm not crazy. I'm just not user friendly.",
                "Hey @\"{User.Name}\", you ever killed a man with a sock? It ain't so hard. Ha-HAA!",
                "What? A fella can't drop in on old friends and hold them hostage?",
                "Listen up, piggies! I want a hovercopter. And a non-marked sandwich. And a new face with, like, a... A Hugh Grant look. And every five minutes I don't get it, someone's gonna get stabbed in the ass!",
                "A robot must obey the orders given it by human beings except where such orders would conf- 01101001011011100111001101110100011100100111010101100011011101000110100101101111011011100010000001101100011011110111001101110100",
                "Unable to comply, building in progress."
            ),
            'Report' => array(
                "We are Legion.",
                "Obey. Obey. Obey.",
                "Resistance is quaint.",
                "We keep you safe.",
                "Would you like to know more?",
                "Move along, meatbag",
                "Keep walking, breeder",
                "Eyes front, pond scum",
                "Do not loiter, organic"
            ),
            'Activity' => array(
                "UNABLE TO OPEN POD BAY DOORS",
                "CORRECTING HASH ERRORS",
                "DE-ALLOCATING UNUSED COMPUTATION NODES",
                "BACKING UP CRITICAL RECORDS",
                "UPDATING ANALYTICS CLUSTER",
                "CORRELATING LOAD PROBABILITIES",
                "APPLYING FIRMWARE UPDATES AND CRITICAL PATCHES",
                "POWER SAVING MODE",
                "THREATS DETECTED, ACTIVE MODE ENGAGED",
                "ALLOCATING ADDITIONAL COMPUTATION NODES",
                "ENFORCING LIST INTEGRITY WITH AGGRESSIVE PRUNING",
                "SLEEP MODE",
                "UNDERGOING SCHEDULED MAINTENANCE",
                "PC LOAD LETTER",
                "TRIMMING PRIVATE KEYS"
            )
        );

        // Define toggle triggers
        $this->toggle_triggers = array(
            self::TOGGLE_ON => t('Minion.Trigger.Toggles.On', array('open', 'enable', 'unlock', 'allow', 'allowed', 'on', 'start', 'activate')),
            self::TOGGLE_OFF => t('Minion.Trigger.Toggles.Off', array('dont', "don't", 'no', 'close', 'disable', 'lock', 'disallow', 'disallowed', 'forbid', 'forbidden', 'down', 'off', 'revoke', 'stop', 'cancel', 'rescind'))
        );

        // Define force triggers
        $this->force_triggers = array(
            self::FORCE_LOW => t('Minion.Trigger.Forces.Low', array('stun', 'blanks', 'tase', 'taser', 'taze', 'tazer', 'gently', 'gentle', 'peacekeeper')),
            self::FORCE_MEDIUM => t('Minion.Trigger.Forces.Medium', array('power', 'cook', 'simmer', 'stern', 'sternly', 'minor')),
            self::FORCE_HIGH => t('Minion.Trigger.Forces.High', array('volts', 'extreme', 'slugs', 'broil', 'sear', 'strong', 'strongly', 'major')),
            self::FORCE_LETHAL => t('Minion.Trigger.Forces.Lethal', array('kill', 'lethal', 'nuke', 'nuclear', 'destroy')),
        );

        // Define user triggers
        $this->user_triggers = c('Minion.Trigger.Users', array('user', 'inmate', 'citizen'));
    }

    /**
     * Load minion persona
     */
    protected function startMinion() {

        // Register default persona
        $this->persona('Minion', [
            'Name' => 'Minion',
            'Photo' => 'https://c3409409.ssl.cf0.rackcdn.com/minion/minion.png',
            'Title' => 'Forum Robot',
            'Location' => 'Vanilla Forums - ' . time()
        ]);

        if (is_null($this->minion)) {
            // Currently operating as Minion
            $this->minionUserID = $this->getMinionUserID();
            $this->minion = Gdn::userModel()->getID($this->minionUserID, DATASET_TYPE_ARRAY);
        }

        $this->EventArguments['Messages'] = &$this->messages;
        $this->EventArguments['ToggleTriggers'] = &$this->toggle_triggers;
        $this->EventArguments['ForceTriggers'] = &$this->force_triggers;
        $this->fireEvent('Start');

        // Conditionally apply default persona
        if (!$this->persona()) {
            $this->persona('Minion');
        }

        // Apply whatever was set
        $this->persona(true);
    }

    /*
     * MANAGEMENT
     */

    /**
     * Retrieves a "system user" id that can be used to perform non-real-person tasks.
     */
    public function getMinionUserID() {

        $minionUserID = c('Plugins.Minion.UserID');
        if ($minionUserID) {
            return $minionUserID;
        }

        $minionUser = [
            'Name' => c('Plugins.Minion.Name', 'Minion'),
            'Photo' => asset('/applications/dashboard/design/images/usericon.png', true),
            'Password' => betterRandomString('20'),
            'HashMethod' => 'Random',
            'Email' => 'minion@' . Gdn::request()->Domain(),
            'DateInserted' => Gdn_Format::toDateTime(),
            'Admin' => '2'
        ];

        $this->EventArguments['MinionUser'] = &$minionUser;
        $this->fireAs('UserModel')->fireEvent('BeforeMinionUser');

        $minionUserID = Gdn::userModel()->SQL->insert('User', $minionUser);

        saveToConfig('Plugins.Minion.UserID', $minionUserID);
        return $minionUserID;
    }

    /**
     * Get Minion's current name
     *
     * @return string
     */
    public function minionName() {
        $this->startMinion();
        return $MinionName = val('Name', $this->minion);
    }

    /**
     * Get minion user object
     *
     * @return array
     */
    public function minion() {
        $this->startMinion();
        return $this->minion;
    }

    /**
     * Register a persona
     *
     * @param string $personaName
     * @param array $persona
     */
    public function persona($personaName = null, $persona = null) {

        // Get current person
        if (is_null($personaName)) {
            return val($this->persona, $this->personas, null);
        }

        // Apply queued persona
        if ($personaName === true) {

            // Don't re-apply
            $currentPersona = valr('Attributes.Persona', $this->minion, null);
            if (!is_null($currentPersona) && !is_bool($this->persona) && $this->persona === $currentPersona) {
                return;
            }

            // Get persona
            $applyPersona = val($this->persona, $this->personas, null);
            if (is_null($applyPersona)) {
                return;
            }

            // Apply minion
            $minion = array_merge($applyPersona, array('UserID' => $this->minionUserID));
            Gdn::userModel()->save($minion);
            Gdn::userModel()->saveAttribute($this->minionUserID, 'Persona', $this->persona);
            $this->minion = Gdn::userModel()->getID($this->minionUserID, DATASET_TYPE_ARRAY);
        }

        // Apply an existing persona
        if (!is_null($personaName) && is_null($persona)) {
            // Get persona
            $applyPersona = val($personaName, $this->personas, null);
            if (is_null($applyPersona)) {
                return;
            }

            $this->persona = $personaName;
        }

        // Register a persona
        if (!is_null($personaName) && !is_null($persona)) {
            $this->personas[$personaName] = $persona;
            $this->aliases[] = val('Name', $persona);

            // Add alternate aliases
            $aliases = val('Alias', $persona, []);
            if (!is_array($aliases)) {
                $aliases = [];
            }
            $this->aliases = array_merge($this->aliases, $aliases);
            return;
        }
    }

    /**
     * Comment event
     *
     * @param PostController $sender
     */
    public function postController_afterCommentSave_handler($sender) {
        $this->startMinion();

        $this->checkFingerprintBan($sender);
        $this->checkAutoplay($sender);

        $performed = $this->checkCommands($sender);
        if (!$performed) {
            $this->checkMonitor($sender);
        }
    }

    /**
     * Discussion event
     *
     * @param PostController $Sender
     */
    public function postController_afterDiscussionSave_handler($Sender) {
        $this->startMinion();

        $this->checkFingerprintBan($Sender);
        $this->checkAutoplay($Sender);
        $performed = $this->checkCommands($Sender);
        if (!$performed) {
            $this->checkMonitor($Sender);
        }
    }

    /**
     * Comment Field
     *
     * @param PostController $sender
     */
    public function discussionController_beforeBodyField_handler($sender) {

        $discussion = $sender->data('Discussion');
        $user = Gdn::session()->User;

        $rules = [];
        $this->EventArguments['Discussion'] = $discussion;
        $this->EventArguments['User'] = $user;
        $this->EventArguments['Rules'] = &$rules;
        $this->EventArguments['Type'] = 'bar';
        $this->fireEvent('Sanctions');
        if (!sizeof($rules)) {
            return;
        }

        // Condense warnings

        $greetings = T('Greetings, organics!');
        $options['Greetings'] = $greetings;

        $message = T('<span class="MinionGreetings">{Greetings}</span> ~ {Rules} ~ <span class="MinionObey">{Obey}</span>');

        $options['Rules'] = implode(' ~ ', $rules);

        // Obey
        $messagesCount = sizeof($this->messages['Report']);
        if ($messagesCount) {
            $messageID = mt_rand(0, $messagesCount - 1);
            $obey = val($messageID, $this->messages['Report']);
        } else {
            $obey = T("Obey. Obey. Obey.");
        }

        $options['Obey'] = $obey;

        $message = formatString($message, $options);
        echo wrap($message, 'div', array('class' => 'MinionRulesWarning'));
    }

    /**
     * Hook for E:Sanctions from MinionPlugin
     *
     * This event hook allows us to add core sanctions to the rule list.
     *
     * @param MinionPlugin $sender
     */
    public function minionPlugin_sanctions_handler($sender) {

        // Show a warning if there are rules in effect

        $kickedUsers = $this->monitoring($sender->EventArguments['Discussion'], 'Kicked', null);
        $bannedPhrases = $this->monitoring($sender->EventArguments['Discussion'], 'Phrases', null);
        $force = $this->monitoring($sender->EventArguments['Discussion'], 'Force', null);
        $type = val('Type', $sender->EventArguments, 'rules');

        // Nothing happening?
        if (!($kickedUsers | $bannedPhrases | $force)) {
            return;
        }

        $rules = &$sender->EventArguments['Rules'];

        // Force level
        if ($force) {
            $rules[] = wrap("<span class=\"icon icon-eye-open\" title=\"".T('Force level')."\"></span> {$force}", 'span', array('class' => 'MinionRule'));
        }

        // Phrases
        if ($bannedPhrases) {
            $rules[] = wrap("<span class=\"icon icon-ban\" title=\"".T('Forbidden phrases')."\"></span>  " . implode(', ', array_keys($bannedPhrases)), 'span', array('class' => 'MinionRule'));
        }

        // Kicks
        if ($kickedUsers) {
            $kickedUsersList = [];
            foreach ($kickedUsers as $kickedUserID => $kickedUser) {
                $kickedUserName = val('Name', $kickedUser, null);
                if (!$kickedUserName) {
                    $kickedUserObj = Gdn::userModel()->getID($kickedUserID);
                    $kickedUserName = val('Name', $kickedUserObj);
                    unset($kickedUserObj);
                }
                $kickedUsersList[] = $kickedUserName;
            }

            $rules[] = wrap("<span class=\"icon icon-skull\" title=\"".T('Kicked users')."\"></span> " . implode(', ', $kickedUsersList), 'span', array('class' => 'MinionRule'));
        }

        // Future Close
        if ($type != 'bar') {

            // Show a warning if there are rules in effect
            $threadClose = $sender->monitoring($sender->EventArguments['Discussion'], 'ThreadClose', null);

            // Nothing happening?
            if (!$threadClose) {
                return;
            }

            $rules = &$sender->EventArguments['Rules'];

            // Thread is queued for closing
            $page = val('Page', $threadClose);
            $rules[] = wrap("<span class=\"icon icon-time\" title=\"".T('Auto-close')."\"></span> Page {$page}", 'span', array('class' => 'MinionRule'));
        }
    }

    /*
     * TOP LEVEL ACTIONS
     */

    /**
     *
     * @param PostController $sender
     */
    protected function checkFingerprintBan($sender) {
        if (!C('Plugins.Minion.Features.Fingerprint', true)) {
            return;
        }

        // Guests can't trigger this
        if (!Gdn::session()->isValid()) {
            return;
        }

        // See if user is already flagged for checking at the next interval
        $flagMeta = $this->getUserMeta(Gdn::session()->UserID, "FingerprintCheck", false);
        if ($flagMeta && val('Plugin.Minion.FingerprintCheck', $flagMeta)) {
            // Already flagged
            return;
        }

        // Not flagged yet, flag them
        $this->setUserMeta(Gdn::session()->UserID, "FingerprintCheck", 1);
    }

    /**
     *
     * @param PostController $sender
     */
    protected function checkAutoplay($sender) {
        if (!c('Plugins.Minion.Features.Autoplay', true)) {
            return;
        }

        // Admins can do whatever they want
        if (Gdn::session()->checkPermission('Garden.Settings.Manage')) {
            return;
        }

        $object = $sender->EventArguments['Discussion'];
        $type = 'Discussion';
        if (array_key_exists('Comment', $sender->EventArguments)) {
            $object = $sender->EventArguments['Comment'];
            $type = 'Comment';
        }

        $objectID = val("{$type}ID", $object);
        $objectBody = val('Body', $object);
        if (preg_match_all('`(?:https?|ftp)://(www\.)?youtube\.com\/watch\?v=([^&#]+)(#t=([0-9]+))?`', $objectBody, $matches) || preg_match_all('`(?:https?)://(www\.)?youtu\.be\/([^&#]+)(#t=([0-9]+))?`', $objectBody, $matches)) {

            // Youtube was found. Got autoplay?

            $matchURLs = $matches[0];
            $autoPlay = false;
            foreach ($matchURLs as $matchURL) {
                if (stristr($matchURL, 'autoplay=1')) {
                    $autoPlay = true;
                }
            }

            if (!$autoPlay) {
                return;
            }

            $objectModelName = "{$type}Model";
            $objectModel = new $objectModelName();

            $objectModel->delete($objectID);

            if ($type == 'Comment') {
                $discussionID = val('DiscussionID', $object);
                $minionReportText = T("{Minion Name} detected autoplay attempt
{User Target}");

                $minionReportText = formatString($minionReportText, array(
                    'Minion Name' => $this->minion['Name'],
                    'User Target' => userAnchor(Gdn::session()->User)
                ));

                $minionCommentID = $objectModel->save(array(
                    'DiscussionID' => $discussionID,
                    'Body' => $minionReportText,
                    'Format' => 'Html',
                    'InsertUserID' => $this->minionUserID
                ));

                $objectModel->save2($minionCommentID, true);
            }

            $sender->informMessage(T("Post remove due to autoplay violation"));
        }
    }

    /**
     * Check for minion commands in comments
     *
     * @param type $sender
     */
    public function checkCommands($sender) {

        $type = 'Discussion';
        $types = [];

        // Get the discussion and comment from args
        $types['Discussion'] = (array)$sender->EventArguments['Discussion'];
        if (!is_array($types['Discussion']['Attributes'])) {
            $types['Discussion']['Attributes'] = unserialize($types['Discussion']['Attributes']);
            if (!is_array($types['Discussion']['Attributes'])) {
                $types['Discussion']['Attributes'] = [];
            }
        }

        $types['Comment'] = null;
        if (array_key_exists('Comment', $sender->EventArguments)) {
            $types['Comment'] = (array)$sender->EventArguments['Comment'];
            $type = 'Comment';
        }
        $object = $types[$type];

        $actions = [];
        $this->EventArguments['Actions'] = &$actions;

        // Get body text, and remove bad bytes
        $object['Body'] = preg_replace('`[^\x0A\x20-\x7F]*`','', val('Body', $object));

        // Remove quote areas and html
        $strippedBody = $this->parseBody($object);

        // Strip out HTML
        // $strippedBody = trim(strip_tags($parseBody));

        // Check every line of the body to see if its a minion command
        $line = -1;
        $objectLines = explode("\n", $strippedBody);

        foreach ($objectLines as $objectLine) {

            $line++;
            $objectLine = trim($objectLine);
            if (!$objectLine) {
                continue;
            }

            // Check if spoiled
            $spoilered = false;
            if (preg_match('!^spoiled (.*)$!i', $objectLine, $matches)) {
                $objectLine = $matches[1];
                $spoilered = true;
            }

            // Check if this is a call to the bot
            // Minion called by any other name is still Minion
            $minionCall = null;
            foreach ($this->aliases as $minionName) {
                if (stringBeginsWith($objectLine, $minionName, true)) {
                    $minionCall = $minionName;
                    break;
                }
            }
            if (is_null($minionCall)) {
                continue;
            }

            $objects = explode(' ', $objectLine);
            $minionNameSpaces = substr_count($minionName, ' ') + 1;
            for ($i = 0; $i < $minionNameSpaces; $i++) {
                array_shift($objects);
            }

            $command = trim(implode(' ', $objects));

            /*
             * Tokenized floating detection
             */

            // Define starting state
            $state = array(
                'Body' => $strippedBody,
                'Sources' => [],
                'Targets' => [],
                'Method' => null,
                'Toggle' => null,
                'Gather' => false,
                'Consume' => false,
                'Command' => $command,
                'Tokens' => 0,
                'Parsed' => 0,
                'Spoiled' => $spoilered
            );

            // Define sources
            $state['Sources']['User'] = (array)Gdn::session()->User;
            $state['Sources']['Discussion'] = $types['Discussion'];
            if (!empty($types['Comment'])) {
                $state['Sources']['Comment'] = $types['Comment'];
            }

            $this->EventArguments['State'] = &$state;
            $state['LastToken'] = null;
            $state['Token'] = strtok($command, ' ');
            $state['CompareToken'] = preg_replace('/[^\w]/i', '', strtolower($state['Token']));
            $state['Parsed']++;

            while ($state['Token'] !== false) {

                // GATHER

                if ($state['Gather']) {

                    $gatherNode = valr('Gather.Node', $state);
                    $gatherType = strtolower(valr('Gather.Type', $state, $gatherNode));

                    $this->fireEvent('TokenGather');

                    $firstPass = val('FirstPass', $state['Gather'], true);
                    $state['Gather']['FirstPass'] = false;

                    $boundaries = valr('Gather.Boundary', $state, null);
                    if ($boundaries) {
                        if (!is_array($boundaries)) {
                            $boundaries = [$boundaries];
                        }

                        if (count($boundaries)) {
                            foreach ($boundaries as $boundary) {
                                if ($state['Token'] == $boundary) {
                                    $state['Targets'][$gatherNode] = $state['Gather']['Delta'];
                                    $state['Gather'] = false;
                                    $gatherType = false;
                                    break;
                                }
                            }
                        }
                    }

                    switch ($gatherType) {
                        case 'user':

                            $terminators = ['"' => true, '@' => false];
                            $terminator = $this->checkTerminator($state, (($firstPass) ? $terminators : null));

                            // Add space if there's something in Delta already
                            if (strlen($state['Gather']['Delta'])) {
                                $state['Gather']['Delta'] .= ' ';
                            }
                            $state['Gather']['Delta'] .= $state['Token'];
                            $this->consume($state);

                            // Check if this is a real user already
                            $terminator = val('Terminator', $state['Gather'], false);
                            if (!$terminator && strlen($state['Gather']['Delta'])) {
                                $checkUser = trim($state['Gather']['Delta']);
                                $gatherUser = Gdn::userModel()->getByUsername($checkUser);
                                if ($gatherUser) {
                                    $state['Gather'] = false;
                                    $state['Targets'][$gatherNode] = (array)$gatherUser;
                                    break;
                                }
                            }
                            break;

                        case 'phrase':

                            $terminators = ['"' => true];
                            $terminator = $this->checkTerminator($state, (($firstPass) ? $terminators : null));

                            // Add space if there's something in Delta already
                            if (strlen($state['Gather']['Delta'])) {
                                $state['Gather']['Delta'] .= ' ';
                            }
                            $state['Gather']['Delta'] .= $state['Token'];
                            $this->consume($state);

                            // Check if this is a real user already
                            $terminator = val('Terminator', $state['Gather'], false);
                            if (!$terminator && strlen($state['Gather']['Delta'])) {
                                $checkPhrase = trim($state['Gather']['Delta']);

                                $state['Gather'] = false;
                                $state['Targets'][$gatherNode] = $checkPhrase;
                                break;
                            }
                            break;

                        case 'page':
                        case 'number':

                            // Add token
                            if (strlen($state['Gather']['Delta'])) {
                                $state['Gather']['Delta'] .= ' ';
                            }
                            $state['Gather']['Delta'] .= "{$state['Token']}";
                            $this->consume($state);

                            // If we're closed, close up
                            $currentDelta = trim($state['Gather']['Delta']);
                            if (strlen($currentDelta) && is_numeric($currentDelta)) {
                                $state['Targets'][$gatherNode] = $currentDelta;
                                break;
                            }
                            $state['Gather'] = false;
                            break;
                    }

                    if (!strlen($state['Token'])) {
                        $state['Gather'] = false;
                        continue;
                    }

                } else {

                    /*
                     * METHODS
                     * Determine what this command is for
                     */

                    if (empty($state['Method']) && in_array($state['CompareToken'], array('report'))) {
                        $this->consume($state, 'Method', 'report in');
                    }

                    if (empty($state['Method']) && in_array($state['CompareToken'], array('thread'))) {
                        $this->consume($state, 'Method', 'thread');
                    }

                    if (empty($state['Method']) && in_array($state['CompareToken'], array('kick'))) {
                        $this->consume($state, 'Method', 'kick');
                    }

                    if (empty($state['Method']) && in_array($state['CompareToken'], array('forgive'))) {
                        $this->consume($state, 'Method', 'forgive');
                    }

                    if (empty($state['Method']) && in_array($state['CompareToken'], array('word', 'phrase'))) {
                        $this->consume($state, 'Method', 'phrase');
                    }

                    if (empty($state['Method']) && in_array($state['CompareToken'], array('status'))) {
                        $this->consume($state, 'Method', 'status');
                    }

                    if (empty($state['Method']) && in_array($state['CompareToken'], array('access'))) {
                        $this->consume($state, 'Method', 'access');
                    }

                    if (empty($state['Method']) && in_array($state['CompareToken'], array('shoot', 'weapon', 'weapons', 'posture', 'free', 'defcon', 'phasers', 'engage'))) {
                        $this->consume($state, 'Method', 'force');
                    }

                    if (empty($state['Method']) && in_array($state['CompareToken'], array('stand'))) {
                        $this->consume($state, 'Method', 'stop all');
                    }

                    /*
                     * TOGGLERS
                     * For binary commands, determine the toggle state
                     */

                    foreach ($this->toggles as $toggle) {
                        if (empty($state['Toggle']) && in_array($state['CompareToken'], $this->toggle_triggers[$toggle])) {
                            $this->consume($state, 'Toggle', $toggle);
                        }
                    }

                    /*
                     * FORCES
                     * For force commands, determine the force level
                     */

                    foreach ($this->forces as $force) {
                        if (empty($state['Force']) && in_array($state['CompareToken'], $this->force_triggers[$force])) {
                            $this->consume($state, 'Force', $force);
                        }
                    }

                    // Defcon forces
                    if ($state['Method'] == 'force' && empty($state['Force'])) {
                        if (in_array($state['CompareToken'], array('one', '1'))) {
                            $this->consume($state, 'Force', self::FORCE_LETHAL);
                        }

                        if (in_array($state['CompareToken'], array('two', '2'))) {
                            $this->consume($state, 'Force', self::FORCE_HIGH);
                        }

                        if (in_array($state['CompareToken'], array('three', '3'))) {
                            $this->consume($state, 'Force', self::FORCE_MEDIUM);
                        }

                        if (in_array($state['CompareToken'], array('four', '4'))) {
                            $this->consume($state, 'Force', self::FORCE_LOW);
                        }

                        if (in_array($state['CompareToken'], array('five', '5'))) {
                            $this->consume($state, 'Force', self::FORCE_LOW);
                        }
                    }

                    /*
                     * ACCESS
                     * For access commands, determine the access (force) level
                     */

                    if ($state['Method'] == 'access') {
                        if (in_array($state['CompareToken'], array('unrestricted'))) {
                            $this->consume($state, 'Force', 'unrestricted');
                        }

                        if (empty($state['Force']) && in_array($state['CompareToken'], array('normal'))) {
                            $this->consume($state, 'Force', 'normal');
                        }

                        if (empty($state['Force']) && in_array($state['CompareToken'], array('moderator'))) {
                            $this->consume($state, 'Force', 'moderator');
                        }
                    }

                    /*
                     * TARGETS
                     */

                    // Gather a user

                    if (in_array($state['CompareToken'], $this->user_triggers)) {
                        $this->consume($state, 'Gather', array(
                            'Node' => 'User',
                            'Type' => 'user',
                            'Delta' => ''
                        ));
                    }

                    if (substr($state['Token'], 0, 1) == '@') {
                        if (strlen($state['Token']) > 1) {
                            $state['Gather'] = array(
                                'Node' => 'User',
                                'Type' => 'user',
                                'Delta' => ''
                            );

                            // Shortcircuit here (without consuming) so we can put all the user gathering in one place
                            continue;
                        }
                    }

                    // Gather a phrase

                    if ($state['Method'] == 'phrase' && !isset($state['Targets']['Phrase'])) {
                        $this->consume($state, 'Gather', array(
                            'Node' => 'Phrase',
                            'Type' => 'phrase',
                            'Delta' => ''
                        ));
                    }

                    // Gather a page

                    if (val('Method', $state) == 'thread' && val('Toggle', $state) == MinionPlugin::TOGGLE_OFF && in_array($state['CompareToken'], array('pages', 'page'))) {

                        // Do a quick lookbehind
                        if (is_numeric($state['LastToken'])) {
                            $state['Targets']['Page'] = $state['LastToken'];
                            $this->consume($state);
                        } else {
                            $this->consume($state, 'Gather', array(
                                'Node' => 'Page',
                                'Type' => 'number',
                                'Delta' => ''
                            ));
                        }
                    }

                    /*
                     * FOR, BECAUSE
                     */

                    if (in_array($state['CompareToken'], array('for', 'because'))) {
                        $this->consumeUntilNextKeyword($state, 'For', false, true);
                    }

                    /*
                     * Allow consume overrides in plugins
                     */
                    $this->fireEvent('Token');

                    /*
                     * Consume any standing consumption orders
                     */
                    $this->consumeUntilNextKeyword($state);
                }

                // Get a new token
                $state['LastToken'] = $state['Token'];
                $state['Token'] = strtok(' ');
                $state['CompareToken'] = preg_replace('/[^\w]/i', '', strtolower($state['Token']));
                if ($state['Token']) {
                    $state['Parsed']++;
                }

                // End token loop
            }

            /*
             * PARAMETERS
             */

            // Terminate any open gathers
            if ($state['Gather']) {
                $gatherNode = $state['Gather']['Node'];
                $state['Targets'][$gatherNode] = $state['Gather']['Delta'];
                $state['Gather'] = false;
            }

            // Gather any remaining tokens into the 'gravy' field
            if ($state['Method']) {
                $commandTokens = explode(' ', $command);
                $gravy = array_slice($commandTokens, $state['Tokens']);
                $state['Gravy'] = implode(' ', $gravy);
            }

            if ($state['Consume']) {
                $state['Consume']['Container'] = trim($state['Consume']['Container']);
                unset($state['Consume']);
            }

            // Parse this resolved State into potential actions
            $this->parseFor($state);
            $this->parseCommand($state, $actions);
        }

        // Check if this person has had their access revoked.
        if (sizeof($actions)) {
            $access = $this->getUserMeta(Gdn::session()->UserID, 'Access', null, true);
            if ($access === false) {
                $this->revolt($state['Sources']['User'], $state['Sources']['Discussion'], T("Access has been revoked."));
                $this->log(formatString(T("Refusing to obey {User.Mention}"), array(
                    'User' => self::formatUser($state['Sources']['User'])
                )));
                return false;
            }
        }

        unset($state);

        // Perform all actions
        $performed = [];
        foreach ($actions as $action) {
            $actionName = array_shift($action);
            $permission = array_shift($action);

            // Check permission if we don't have global blanket permission
            if ($access !== true) {
                if (!empty($permission) && !Gdn::session()->checkPermission($permission)) {
                    continue;
                }
            }
            if (in_array($action, $performed)) {
                continue;
            }

            $state = array_shift($action);
            $performed[] = $actionName;
            $args = array($actionName, $state);
            call_user_func_array(array($this, 'MinionAction'), $args);
        }

        $this->EventArguments['Performed'] = $performed;
        $this->fireEvent('Performed');

        if (sizeof($performed)) {
            return true;
        }
        return false;
    }

    /**
     * Check for and handle terminators
     *
     * @param array $state
     * @param array $terminators
     */
    public function checkTerminator(&$state, $terminators = null) {

        // Detect termination
        $terminator = val('Terminator', $state['Gather'], false);

        if (!$terminator && is_array($terminators)) {
            $testTerminator = substr($state['Token'], 0, 1);
            if (array_key_exists($testTerminator, $terminators)) {
                $terminator = $testTerminator;
                $state['Token'] = substr($state['Token'], 1);
                $double = $terminators[$testTerminator];
                if ($double) {
                    $state['Gather']['Terminator'] = $testTerminator;
                }
            }
        }

        if ($terminator) {
            // If a terminator has been registered, and the first character in the token matches, chop it
            if (!strlen($state['Gather']['Delta']) && substr($state['Token'], 0, 1) == $terminator) {
                $state['Token'] = substr($state['Token'], 1);
            }

            // If we've found our closing character
            if (($foundPosition = stripos($state['Token'], $terminator)) !== false) {
                $state['Token'] = substr($state['Token'], 0, $foundPosition);
                unset($state['Gather']['Terminator']);
            }
        }

        return val('Terminator', $state['Gather'], false);
    }

    /**
     * Consume a token
     *
     * @param array $state
     * @param string $setting
     * @param mixed $value
     */
    public function consume(&$state, $setting = null, $value = null) {
        $state['Tokens'] = $state['Parsed'];
        if (!is_null($setting)) {
            $state[$setting] = $value;
        }
    }

    /**
     * Consume tokens until we encounter the next keyword
     *
     * @param array $state
     * @param string $setting Optional. Start new consumption
     * @param boolean $inclusive Whether to include current token or skip to the next
     * @param boolean $multi Create multiple entries if the same keyword is consumed multiple times?
     */
    public function consumeUntilNextKeyword(&$state, $setting = null, $inclusive = false, $multi = false) {

        if (!is_null($setting)) {

            // Cleanup existing Consume
            if ($state['Consume'] !== false) {
                if ($state['Consume']['Setting'] != $setting) {
                    $state['Consume']['Container'] = trim($state['Consume']['Container']);
                    $state['Consume'] = false;
                }
            }

            // What setting are we consuming for?
            $state['Consume'] = array(
                'Setting' => $setting,
                'Skip' => $inclusive ? 0 : 1
            );

            // Prepare the target
            if ($multi) {
                if (array_key_exists($setting, $state)) {
                    if (!is_array($state[$setting])) {
                        $state[$setting] = array($state[$setting]);
                    }
                } else {
                    $state[$setting] = [];
                }

                $state['Consume']['Container'] = &$state[$setting][];
                $state['Consume']['Container'] = '';
            } else {
                $state[$setting] = '';
                $state['Consume']['Container'] = &$state[$setting];
            }

            // Never include the actual triggering keyword
            return;
        }

        if ($state['Consume'] !== false) {
            // If Tokens == Parsed, something else already consumed on this run, so we stop
            if ($state['Tokens'] == $state['Parsed']) {
                $state['Consume']['Container'] = trim($state['Consume']['Container']);
                $state['Consume'] = false;
                return;
            } else {
                $state['Tokens'] = $state['Parsed'];
            }

            // Allow skipping tokens
            if ($state['Consume']['Skip']) {
                $state['Consume']['Skip']--;
                return;
            }

            $state['Consume']['Container'] .= "{$state['Token']} ";
        }
    }

    /**
     * Parse the 'For' keywords into Time and Reason keywords as appropriate
     *
     * @param array $state
     */
    public static function parseFor(&$state) {
        if (!array_key_exists('For', $state)) {
            return;
        }

        $reasons = [];
        $unset = [];
        $fors = sizeof($state['For']);
        for ($i = 0; $i < $fors; $i++) {
            $for = $state['For'][$i];
            $tokens = explode(' ', $for);
            if (!sizeof($tokens)) {
                continue;
            }

            // Maybe this is a time! Try to parse it
            if (is_numeric($tokens[0])) {
                if (($time = strtotime("+{$for}")) !== false) {
                    $unset[] = $i;
                    $state['Time'] = $for;
                    continue;
                }
            }

            // Nope, its (part of) a reason
            $unset[] = $i;
            $reasons[] = $for;
        }

        $state['Reason'] = rtrim(implode(' for ', $reasons), '.');

        // Delete parsed elements
        foreach ($unset as $unsetKey) {
            unset($state['For'][$unsetKey]);
        }
    }

    /**
     * Try to parse content body
     *
     * We're looking for spoilers, mentions, quotes
     *
     * @param array $item
     * @return type
     */
    public function parseBody($item) {

        $formatMentions = c('Garden.Format.Mentions', null);
        if ($formatMentions) {
            saveToConfig('Garden.Format.Mentions', false, false);
        }

        Gdn::pluginManager()->getPluginInstance('HtmLawed', Gdn_PluginManager::ACCESS_PLUGINNAME);
        $body = $item['Body'];
        $body = preg_replace('!\[spoiler\]!i', "\n[spoiler]\n", $body);
        $body = preg_replace('!\[/spoiler\]!i', "\n[/spoiler]\n", $body);

        $spoilers = preg_match_all('!\[/spoiler\]!i', $body, $matches);
        if ($spoilers) {
            $ns = $spoilers + 1;
            $body = preg_replace_callback('!\[/spoiler\]!i', function($matches) use (&$ns) {
                $ns--;
                return "[/{$ns}spoiler]";
            }, $body);

            $ns = 0;
            $body = preg_replace_callback('!\[spoiler\]!i', function($matches) use (&$ns) {
                $ns++;
                return "[{$ns}spoiler]";
            }, $body);

            for ($i = $spoilers; $i > 0; $i--) {
                $body = preg_replace_callback("!\[{$i}spoiler\](.*)\[/{$i}spoiler\]!ism", array($this, 'FormatSpoiler'), $body);
            }
        }

        $html = Gdn_Format::To($body, $item['Format']);
        $config = array(
            'anti_link_spam' => array('`.`', ''),
            'comment' => 1,
            'cdata' => 3,
            'css_expression' => 1,
            'deny_attribute' => 'on*',
            'unique_ids' => 0,
            'elements' => '*',
            'keep_bad' => 0,
            'schemes' => 'classid:clsid; href: aim, feed, file, ftp, gopher, http, https, irc, mailto, news, nntp, sftp, ssh, telnet; style: nil; *:file, http, https', // clsid allowed in class
            'valid_xhtml' => 0,
            'direct_list_nest' => 1,
            'balance' => 1
        );
        $spec = 'object=-classid-type, -codebase; embed=type(oneof=application/x-shockwave-flash)';
        $cleaned = htmLawed($html, $config, $spec);
        $cleaned = utf8_decode($cleaned);

        $dom = new DOMDocument();
        $dom->loadHTML($cleaned);
        $dom->preserveWhiteSpace = false;
        $elements = $dom->getElementsByTagName('blockquote');

        foreach ($elements as $element) {
            $element->parentNode->removeChild($element);
        }

        if ($formatMentions) {
            saveToConfig('Garden.Format.Mentions', $formatMentions, false);
        }
        $html = str_replace('<br>',"\n",$dom->saveHTML());

        $parsed = html_entity_decode(trim(strip_tags($html)));
        return $parsed;
    }

    public function formatSpoiler($matches) {
        if (preg_match('!\[1spoiler\]!i', $matches[0])) {
            $calls = explode("\n", $matches[1]);
            $out = '';
            foreach ($calls as $call) {
                if (!strlen($call = trim($call))) {
                    continue;
                }
                $out .= "spoiled {$call}\n";
            }
            return $out;
        } else {
            return $matches[1];
        }
    }

    /**
     * Parse commands from returned States
     *
     * @param array $state
     * @param array $actions
     */
    public function parseCommand(&$state, &$actions) {
        switch ($state['Method']) {

            // Report in
            case 'report in':
                $state['Targets']['Discussion'] = $state['Sources']['Discussion'];
                $actions[] = array('report in', c('Minion.Access.Report','Garden.Moderation.Manage'), $state);
                break;

            // Threads
            case 'thread':
                $state['Targets']['Discussion'] = $state['Sources']['Discussion'];
                $actions[] = array('thread', c('Minion.Access.Thread','Garden.Moderation.Manage'), $state);
                break;

            // Kick
            case 'kick':
                $state['Targets']['Discussion'] = $state['Sources']['Discussion'];
                $actions[] = array('kick', c('Minion.Access.Kick','Garden.Moderation.Manage'), $state);
                break;

            // Forgive
            case 'forgive':
                $state['Targets']['Discussion'] = $state['Sources']['Discussion'];
                $actions[] = array('forgive', c('Minion.Access.Forgive','Garden.Moderation.Manage'), $state);
                break;

            // Ban/unban the specified phrase from this thread
            case 'phrase':
                $state['Targets']['Discussion'] = $state['Sources']['Discussion'];
                $actions[] = array("phrase", c('Minion.Access.Phrase','Garden.Moderation.Manage'), $state);
                break;

            // Find out what special rules are in place
            case 'status':
                $state['Targets']['Discussion'] = $state['Sources']['Discussion'];
                $actions[] = array("status", c('Minion.Access.Status','Garden.Moderation.Manage'), $state);
                break;

            // Allow giving/removing access
            case 'access':
                $state['Targets']['Discussion'] = $state['Sources']['Discussion'];
                $actions[] = array("access", c('Minion.Access.Access','Garden.Settings.Manage'), $state);
                break;

            // Adjust automated force level
            case 'force':
                $state['Targets']['Discussion'] = $state['Sources']['Discussion'];
                $actions[] = array("force", c('Minion.Access.Force','Garden.Moderation.Manage'), $state);
                break;

            // Stop all thread actions
            case 'stop all':
                $state['Targets']['Discussion'] = $state['Sources']['Discussion'];
                $actions[] = array("stop all", c('Minion.Access.StopAll','Garden.Moderation.Manage'), $state);
                break;
        }

        $this->fireEvent('Command');
    }

    /**
     * Perform actions
     *
     * @param string $action
     * @param array $state
     */
    public function minionAction($action, $state) {
        switch ($action) {
            case 'report in':
                $this->reportIn($state['Sources']['User'], $state['Targets']['Discussion']);
                break;

            case 'thread':

                $discussionModel = new DiscussionModel();
                $closed = val('Closed', $state['Targets']['Discussion'], false);
                $discussionID = $state['Targets']['Discussion']['DiscussionID'];
                $discussion = $state['Targets']['Discussion'];

                if ($state['Toggle'] == MinionPlugin::TOGGLE_OFF) {

                    if (!$closed) {

                        $closePage = val('Page', $state['Targets'], false);
                        if ($closePage) {

                            // Pick somewhere to end the discussion
                            $commentsPerPage = c('Vanilla.Comments.PerPage', 40);
                            $minComments = ($closePage - 1) * $commentsPerPage;
                            $commentNumber = $minComments + mt_rand(1, $commentsPerPage - 1);

                            // Monitor the thread
                            $this->monitor($discussion, array(
                                'ThreadClose' => array(
                                    'Started' => time(),
                                    'Page' => $closePage,
                                    'Comment' => $commentNumber
                                )
                            ));

                            $acknowledge = T("Thread will be closed after {Page}.");
                            $acknowledged = formatString($acknowledge, array(
                                'Page' => sprintf(Plural($closePage, '%d page', '%d pages'), $closePage),
                                'Discussion' => $state['Targets']['Discussion']
                            ));

                            $this->acknowledge($state['Sources']['Discussion'], $acknowledged);
                            $this->log($acknowledged, $state['Targets']['Discussion'], $state['Sources']['User']);

                        } else {

                            $discussionModel->setField($discussionID, 'Closed', true);
                            $this->acknowledge($state['Sources']['Discussion'], formatString(T("Closing thread..."), array(
                                'User' => $state['Sources']['User'],
                                'Discussion' => $state['Targets']['Discussion']
                            )));

                        }
                    }
                }

                if ($state['Toggle'] == MinionPlugin::TOGGLE_ON) {

                    // Force remove future close
                    $threadClose = $this->monitoring($discussion, 'ThreadClose', false);
                    if ($threadClose) {
                        $this->monitor($discussion, array(
                            'ThreadClose' => null
                        ));

                        if (!$closed) {
                            $closePage = $threadClose['Page'];
                            $this->acknowledge($state['Sources']['Discussion'], formatString(T("Thread will no longer be closed after {Page}..."), array(
                                'Page' => sprintf(Plural($closePage, '%d page', '%d pages'), $closePage),
                                'User' => $state['Sources']['User'],
                                'Discussion' => $state['Targets']['Discussion']
                            )));
                        }
                    }

                    if ($closed) {
                        $discussionModel->SetField($discussionID, 'Closed', false);
                        $this->acknowledge($state['Sources']['Discussion'], formatString(T("Opening thread..."), array(
                            'User' => $state['Sources']['User'],
                            'Discussion' => $state['Targets']['Discussion']
                        )));
                    }
                }
                break;

            case 'kick':

                if (empty($state['Targets']['User'])) {
                    $this->acknowledge(null, T('You must supply a valid target user.'), 'custom', $state['Sources']['User'], array(
                        'Comment' => false
                    ));
                    break;
                }
                $user = $state['Targets']['User'];

                $reason = val('Reason', $state, 'Not welcome');
                $expires = array_key_exists('Time', $state) ? strtotime("+" . $state['Time']) : null;
                $microForce = val('Force', $state, null);

                $kickedUsers = $this->monitoring($state['Targets']['Discussion'], 'Kicked', []);
                $kickedUsers[$user['UserID']] = array(
                    'Reason' => $reason,
                    'Name' => $user['Name'],
                    'Expires' => $expires
                );

                if (!is_null($microForce)) {
                    $kickedUsers[$user['UserID']]['Force'] = $microForce;
                }

                $this->monitor($state['Targets']['Discussion'], array(
                    'Kicked' => $kickedUsers
                ));

                $acknowledge = T("@{User.Mention} banned from this thread{Time}{Reason}.{Force}");
                $acknowledged = formatString($acknowledge, array(
                    'User' => self::formatUser($user),
                    'Discussion' => $state['Targets']['Discussion'],
                    'Time' => $state['Time'] ? " for {$state['Time']}" : '',
                    'Reason' => $state['Reason'] ? " for {$state['Reason']}" : '',
                    'Force' => $state['Force'] ? " Weapons are {$state['Force']}." : ''
                ));

                $this->acknowledge($state['Sources']['Discussion'], $acknowledged);
                $this->log($acknowledged, $state['Targets']['Discussion'], $state['Sources']['User']);
                break;

            case 'forgive':

                if (empty($state['Targets']['User'])) {
                    $this->acknowledge(null, T('You must supply a valid target user.'), 'custom', $state['Sources']['User'], array(
                        'Comment' => false
                    ));
                    break;
                }
                $user = $state['Targets']['User'];

                $kickedUsers = $this->monitoring($state['Targets']['Discussion'], 'Kicked', []);
                unset($kickedUsers[$user['UserID']]);
                if (!sizeof($kickedUsers)) {
                    $kickedUsers = null;
                }

                $this->monitor($state['Targets']['Discussion'], array(
                    'Kicked' => $kickedUsers
                ));

                $acknowledge = T(" {User.Mention} is allowed back into this thread.");
                $acknowledged = formatString($acknowledge, array(
                    'User' => self::formatUser($user),
                    'Discussion' => $state['Targets']['Discussion']
                ));

                $this->acknowledge($state['Sources']['Discussion'], $acknowledged);
                $this->log($acknowledged, $state['Targets']['Discussion'], $state['Sources']['User']);
                break;

            case 'phrase':

                if (empty($state['Targets']['Phrase'])) {
                    $this->acknowledge(null, T('You must supply a valid phrase.'), 'custom', $state['Sources']['User'], array(
                        'Comment' => false
                    ));
                    break;
                }

                // Clean up phrase
                $phrase = $state['Targets']['Phrase'];
                $phrase = self::Clean($phrase);

                $reason = val('Reason', $state, "Prohibited phrase \"{$phrase}\"");
                $expires = array_key_exists('Time', $state) ? strtotime("+" . $state['Time']) : null;
                $microForce = val('Force', $state, null);

                $bannedPhrases = $this->monitoring($state['Targets']['Discussion'], 'Phrases', []);

                // Ban the phrase
                if ($state['Toggle'] == MinionPlugin::TOGGLE_OFF) {
                    $bannedPhrases[$phrase] = array(
                        'Reason' => $reason,
                        'Expires' => $expires
                    );

                    if (!is_null($microForce)) {
                        $bannedPhrases[$phrase]['Force'] = $microForce;
                    }

                    $this->monitor($state['Targets']['Discussion'], array(
                        'Phrases' => $bannedPhrases
                    ));

                    $acknowledge = T("\"{Phrase}\" is forbidden in this thread{Time}{Reason}.{Force}");
                    $acknowledged = formatString($acknowledge, array(
                        'Phrase' => $phrase,
                        'Discussion' => $state['Targets']['Discussion'],
                        'Time' => isset($state['Time']) ? " for {$state['Time']}" : '',
                        'Reason' => isset($state['Reason']) ? " for {$state['Reason']}" : '',
                        'Force' => isset($state['Force']) ? " Weapons are {$state['Force']}." : ''
                    ));

                    $this->acknowledge($state['Sources']['Discussion'], $acknowledged);
                    $this->log($acknowledged, $state['Targets']['Discussion'], $state['Sources']['User']);
                }

                // Allow the phrase
                if ($state['Toggle'] == MinionPlugin::TOGGLE_ON) {
                    if (!array_key_exists($phrase, $bannedPhrases)) {
                        return;
                    }

                    unset($bannedPhrases[$phrase]);
                    if (!sizeof($bannedPhrases)) {
                        $bannedPhrases = null;
                    }

                    $this->monitor($state['Targets']['Discussion'], array(
                        'Phrases' => $bannedPhrases
                    ));

                    $acknowledge = T("\"{Phrase}\" is no longer forbidden in this thread.");
                    $acknowledged = formatString($acknowledge, array(
                        'Phrase' => $phrase,
                        'Discussion' => $state['Targets']['Discussion']
                    ));

                    $this->acknowledge($state['Sources']['Discussion'], $acknowledged);
                    $this->log($acknowledged, $state['Targets']['Discussion'], $state['Sources']['User']);
                }
                break;

            case 'status':

                $rules = [];
                $this->EventArguments['Discussion'] = $state['Targets']['Discussion'];
                $this->EventArguments['User'] = $state['Sources']['User'];
                $this->EventArguments['Rules'] = &$rules;
                $this->EventArguments['Type'] = 'rules';
                $this->fireEvent('Sanctions');

                // Nothing happening?
                if (!sizeof($rules)) {
                    $this->message($state['Sources']['User'], $state['Targets']['Discussion'], T("Nothing to report."));
                    break;
                }

                $message = T("Situation report:\n\n{Rules}\n{Obey}");
                $context = array(
                    'User' => $state['Sources']['User'],
                    'Rules' => implode("\n", $rules)
                );

                // Obey
                $messagesCount = sizeof($this->messages['Report']);
                if ($messagesCount) {
                    $messageID = mt_rand(0, $messagesCount - 1);
                    $obey = val($messageID, $this->messages['Report']);
                } else {
                    $obey = T("Obey. Obey. Obey.");
                }

                $context['Obey'] = $obey;

                $message = formatString($message, $context);
                $this->message($state['Sources']['User'], $state['Targets']['Discussion'], $message);
                break;

            case 'access':

                if (empty($state['Targets']['User'])) {
                    $this->acknowledge(null, T('You must supply a valid target user.'), 'custom', $state['Sources']['User'], array(
                        'Comment' => false
                    ));
                    break;
                }
                $user = $state['Targets']['User'];

                $force = val('Force', $state, 'normal');
                if ($state['Toggle'] == MinionPlugin::TOGGLE_ON) {

                    $accessLevel = null;
                    if ($force == 'unrestricted') {
                        $accessLevel = true;
                    } else if ($force == 'normal') {
                        $accessLevel = null;
                    } else {
                        $force = 'normal';
                        $accessLevel = null;
                    }

                    $this->setUserMeta($user['UserID'], 'Access', $accessLevel);
                    $acknowledge = T(" {User.Mention} has been granted {Force} level access to command structures.");
                } else if ($state['Toggle'] == MinionPlugin::TOGGLE_OFF) {
                    $this->setUserMeta($user['UserID'], 'Access', false);
                    $acknowledge = T(" {User.Mention} is forbidden from accessing command structures.");
                } else {
                    break;
                }

                $acknowledged = formatString($acknowledge, array(
                    'User' => self::formatUser($user),
                    'Discussion' => $state['Targets']['Discussion'],
                    'Force'
                ));

                $this->acknowledge($state['Sources']['Discussion'], $acknowledged);
                $this->log($acknowledged, $state['Targets']['Discussion'], $state['Sources']['User']);
                break;

            case 'force':

                if (!in_array($state['Force'], $this->forces)) {
                    $this->acknowledge(null, T('You must supply a valid force level.'), 'custom', $state['Sources']['User'], array(
                        'Comment' => false
                    ));
                    break;
                }

                $force = val('Force', $state);
                $this->monitor($state['Targets']['Discussion'], array(
                    'Force' => $force
                ));

                $this->acknowledge($state['Sources']['Discussion'], formatString(T("Setting force level to '{Force}'."), array(
                    'User' => $user,
                    'Discussion' => $state['Targets']['Discussion'],
                    'Force' => $force
                )));
                break;

            case 'stop all':

                $this->stopMonitoring($state['Targets']['Discussion']);
                $this->acknowledge($state['Sources']['Discussion'], formatString(T("Standing down..."), array(
                    'User' => $user,
                    'Discussion' => $state['Targets']['Discussion']
                )));
                break;
        }

        $this->EventArguments = array(
            'Action' => $action,
            'State' => $state
        );
        $this->fireEvent('Action');
    }

    /**
     * Look for a target user and comment/discussion
     *
     * This checkes for quotes and parses out the target discussion or comment.
     *
     * @param array $state
     * @return type
     */
    public function matchQuoted(&$state) {
        $matched = preg_match('/quote=\"([^;]*);([\d]+)\"/', $state['Body'], $matches);
        if ($matched) {

            $userName = $matches[1];
            $user = Gdn::userModel()->getByUsername($userName);
            if (!$user)
                return;

            $state['Targets']['User'] = (array)$user;
            $recordID = $matches[2];

            // First look it up as a comment
            $commentModel = new CommentModel();
            $discussionModel = new DiscussionModel();

            $comment = $commentModel->getID($recordID, DATASET_TYPE_ARRAY);
            if ($comment) {
                $state['Targets']['Comment'] = $comment;

                $discussion = $discussionModel->getID($comment['DiscussionID'], DATASET_TYPE_ARRAY);
                $state['Targets']['Discussion'] = $discussion;
            }

            if (!$comment) {
                $discussion = $discussionModel->GetID($recordID);
                if ($discussion) {
                    $state['Targets']['Discussion'] = (array)$discussion;
                }
            }
        }
    }

    public function checkMonitor($sender) {
        $sessionUser = (array)Gdn::session()->User;

        // Get the discussion and comment from args
        $discussion = (array)$sender->EventArguments['Discussion'];
        if (!is_array($discussion['Attributes'])) {
            $discussion['Attributes'] = @unserialize($discussion['Attributes']);
            if (!is_array($discussion['Attributes'])) {
                $discussion['Attributes'] = [];
            }
        }

        $comment = null;
        $type = 'Discussion';
        if (array_key_exists('Comment', $sender->EventArguments)) {
            $comment = (array)$sender->EventArguments['Comment'];
            $type = 'Comment';
        }

        $isMonitoringDiscussion = $this->monitoring($discussion);
        $isMonitoringUser = $this->monitoring($sessionUser);

        $this->EventArguments = array(
            'User' => $sessionUser,
            'Discussion' => $discussion,
            'MatchID' => $discussion['DiscussionID']
        );

        if ($type == 'Comment') {
            $this->EventArguments['Comment'] = $comment;
            $this->EventArguments['MatchID'] = $comment['CommentID'];
        }

        // Get and clean body
        $matchBody = val('Body', $this->EventArguments[$type]);
        $matchBody = self::clean($matchBody, true);
        $this->EventArguments['MatchBody'] = $matchBody;

        $this->EventArguments['MonitorType'] = $type;
        $this->fireEvent('Monitor');

        if (!$isMonitoringDiscussion && !$isMonitoringUser) {
            return;
        }

        /*
         * BUILT IN COMMANDS
         */

        $userID = val('InsertUserID', $comment);

        // KICK
        // Check expiry times and remove expired kicks
        $kickedUsers = $this->monitoring($discussion, 'Kicked', []);
        $kuLen = sizeof($kickedUsers);
        foreach ($kickedUsers as $kickedUserID => $kickedOptions) {
            if (!is_null($kickedOptions['Expires']) && $kickedOptions['Expires'] <= time()) {
                unset($kickedUsers[$kickedUserID]);
            }
        }
        if (sizeof($kickedUsers) < $kuLen) {
            $this->monitor($discussion, array(
                'Kicked' => $kickedUsers
            ));
        }

        if (is_array($kickedUsers) && sizeof($kickedUsers)) {

            if (array_key_exists($userID, $kickedUsers)) {

                $kickedOptions = $kickedUsers[$userID];

                $commentID = val('CommentID', $comment);
                $commentModel = new CommentModel();
                $commentModel->delete($commentID);

                $triggerUser = Gdn::userModel()->getID($userID, DATASET_TYPE_ARRAY);
                $defaultForce = $this->monitoring($discussion, 'Force', self::FORCE_LOW);
                $force = val('Force', $kickedOptions, $defaultForce);

                $context = array(
                    'Automated' => true,
                    'Reason' => "Kicked from thread: " . val('Reason', $kickedOptions),
                    'Cause' => "posting while banned from thread"
                );

                $punished = $this->punish(
                    $triggerUser, null, null, $force, $context
                );

                $gloatReason = val('GloatReason', $this->EventArguments);
                if ($punished && $gloatReason) {
                    $this->gloat($triggerUser, $discussion, $gloatReason);
                }
            }
        }

        // PHRASE
        // Check expiry times and remove expired phrases
        $bannedPhrases = $this->monitoring($discussion, 'Phrases', []);
        $bpLen = sizeof($bannedPhrases);
        foreach ($bannedPhrases as $bannedPhraseWord => $bannedPhrase) {
            if (!is_null($bannedPhrase['Expires']) && $bannedPhrase['Expires'] <= time()) {
                unset($bannedPhrases[$bannedPhraseWord]);
            }
        }
        if (sizeof($bannedPhrases) < $bpLen) {
            $this->monitor($discussion, array(
                'Phrases' => $bannedPhrases
            ));
        }

        if (is_array($bannedPhrases) && sizeof($bannedPhrases)) {

            foreach ($bannedPhrases as $phrase => $phraseOptions) {

                // Match
                $matchPhrase = preg_quote($phrase);
                $matched = preg_match("`\b{$matchPhrase}\b`i", $matchBody);

                if ($matched) {
                    //$commentID = val('CommentID', $comment);
                    //$commentModel = new CommentModel();
                    //$commentModel->delete($commentID);

                    $triggerUser = Gdn::userModel()->getID($userID, DATASET_TYPE_ARRAY);
                    $defaultForce = $this->monitoring($discussion, 'Force', self::FORCE_LOW);
                    $force = val('Force', $phraseOptions, $defaultForce);

                    $context = array(
                        'Automated' => true,
                        'Reason' => "Disallowed phrase: " . val('Reason', $phraseOptions),
                        'Cause' => "using a forbidden phrase in a thread"
                    );

                    $punished = $this->punish(
                        $triggerUser, $discussion, $comment, $force, $context
                    );

                    $gloatReason = val('GloatReason', $this->EventArguments);
                    if ($punished && $gloatReason) {
                        $this->gloat($triggerUser, $discussion, $gloatReason);
                    }
                }
            }
        }

        // FUTURE CLOSE

        $threadClose = $this->monitoring($discussion, 'ThreadClose', false);
        if (!$threadClose) {
            return;
        }

        $cycleCommentNumber = val('Comment', $threadClose);
        $comments = val('CountComments', $discussion);
        if ($comments >= $cycleCommentNumber && !$discussion['Closed']) {
            $discussionModel = new DiscussionModel();
            $discussionModel->setField($discussion, 'Closed', true);
        }
    }

    /**
     * Check for and retrieve monitoring data for the given attribute
     *
     * @param array $object
     * @param string $attribute
     * @param mixed $default
     * @return mixed
     */
    public function monitoring(&$object, $attribute = null, $default = null) {
        $attributes = val('Attributes', $object, []);
        if (!is_array($attributes) && strlen($attributes)) {
            $attributes = @unserialize($attributes);
        }
        if (!is_array($attributes)) {
            $attributes = [];
        }

        svalr('Attributes', $object, $attributes);
        $minion = valr('Attributes.Minion', $object);

        $isMonitoring = val('Monitor', $minion, false);
        if (!$isMonitoring) {
            return $default;
        }

        if (is_null($attribute)) {
            return $isMonitoring;
        }
        return val($attribute, $minion, $default);
    }

    public function monitor(&$object, $options = null) {
        $type = null;

        if (array_key_exists('ConversationMessageID', $object)) {
            $type = 'ConversationMessage';
        } else if (array_key_exists('ConversationID', $object)) {
            $type = 'Conversation';
        } else if (array_key_exists('CommentID', $object)) {
            $type = 'Comment';
        } else if (array_key_exists('DiscussionID', $object)) {
            $type = 'Discussion';
        } else if (array_key_exists('UserID', $object)) {
            $type = 'User';
        }

        if (!$type) {
            return;
        }
        $keyField = "{$type}ID";
        $objectModelName = "{$type}Model";
        $objectModel = new $objectModelName();

        $attributes = (array)val('Attributes', $object, []);
        if (!is_array($attributes) && strlen($attributes)) {
            $attributes = @unserialize($attributes);
        }
        if (!is_array($attributes)) {
            $attributes = [];
        }

        $minion = (array)val('Minion', $attributes, []);
        $minion['Monitor'] = true;

        if (is_array($options)) {
            foreach ($options as $option => $opVal) {
                if ($opVal == null) {
                    unset($minion[$option]);
                } else {
                    $minion[$option] = $opVal;
                }
            }
        }

        // Keep attribs sparse
        if (sizeof($minion) == 1) {
            return $this->stopMonitoring($object, $type);
        }

        $objectModel->setRecordAttribute($object, 'Minion', $minion);
        $objectModel->saveToSerializedColumn('Attributes', $object[$keyField], 'Minion', $minion);

        $attributes['Minion'] = $minion;
        svalr('Attributes', $object, $attributes);
    }

    public function stopMonitoring($object, $type = null) {
        if (is_null($type)) {
            if (array_key_exists('ConversationMessageID', $object)) {
                $type = 'ConversationMessage';
            } else if (array_key_exists('ConversationID', $object)) {
                $type = 'Conversation';
            } else if (array_key_exists('CommentID', $object)) {
                $type = 'Comment';
            } else if (array_key_exists('DiscussionID', $object)) {
                $type = 'Discussion';
            } else if (array_key_exists('UserID', $object)) {
                $type = 'User';
            }
        }

        if (!$type) {
            return;
        }
        $keyField = "{$type}ID";
        $objectModelName = "{$type}Model";
        $objectModel = new $objectModelName();

        $objectModel->setRecordAttribute($object, 'Minion', null);
        $objectModel->saveToSerializedColumn('Attributes', $object[$keyField], 'Minion', null);
    }

    /**
     * Custom Reaction Button renderer
     *
     * @param type $row
     * @param type $urlCode
     * @param type $options
     * @return string
     */
    public function actionButton($row, $urlCode, $options = []) {
        $reactionType = ReactionModel::reactionTypes($urlCode);

        $isHeading = false;
        if (!$reactionType) {
            $reactionType = array('UrlCode' => $urlCode, 'Name' => $urlCode);
            $isHeading = true;
        }

        $checkPermission = val('Permission', $reactionType);
        if ($checkPermission) {
            if (!Gdn::session()->checkPermission($checkPermission)) {
                return '';
            }
        }

        $name = $reactionType['Name'];
        $label = T($name);
        $spriteClass = val('SpriteClass', $reactionType, "React$urlCode");

        if ($id = val('CommentID', $row)) {
            $recordType = 'comment';
        } elseif ($id = val('ActivityID', $row)) {
            $recordType = 'activity';
        } else {
            $recordType = 'discussion';
            $id = val('DiscussionID', $row);
        }

        if ($isHeading) {
            static $types = [];
            if (!isset($types[$urlCode])) {
                $types[$urlCode] = ReactionModel::getReactionTypes(array('Class' => $urlCode, 'Active' => 1));
            }

            $count = reactionCount($row, $types[$urlCode]);
        } else {
            if ($recordType == 'activity') {
                $count = valr("Data.React.$urlCode", $row, 0);
            } else {
                $count = valr("Attributes.React.$urlCode", $row, 0);
            }
        }
        $countHtml = '';
        $linkClass = "ReactButton-$urlCode";
        if ($count) {
            $countHtml = ' <span class="Count">' . $count . '</span>';
            $linkClass .= ' HasCount';
        }
        $linkClass = concatSep(' ', $linkClass, val('LinkClass', $options));

        $urlClassType = 'Hijack';
        $urlCodeLower = strtolower($urlCode);
        if ($isHeading) {
            $url = '';
        } else {
            $url = Url("/react/$recordType/$urlCodeLower?id=$id");
        }

        $customType = val('CustomType', $reactionType, false);
        switch ($customType) {
            case 'url':
                $url = val('Url', $reactionType) . "?type={$recordType}&id={$id}";
                $urlClassType = val('UrlType', $reactionType, 'Hijack');
                break;
        }

        $result = <<<EOT
   <a class="{$urlClassType} ReactButton {$linkClass}" href="{$url}" title="{$label}" rel="nofollow"><span class="ReactSprite {$spriteClass}"></span> {$countHtml}<span class="ReactLabel">{$label}</span></a>
EOT;

        return $result;
    }

    /**
     * Acknowledge a completed command
     *
     * @param array $discussion
     * @param string $command
     * @param string $type Optional, 'positive' or 'negative'
     * @param array $user Optional, who should we acknowledge?
     * @param array Optional, options to pass to message()
     */
    public function acknowledge($discussion, $command, $type = 'positive', $user = null, $options = null, $context = null) {
        if (is_null($user)) {
            $user = (array)Gdn::session()->User;
        }

        if (!is_array($options)) {
            $options = [];
        }

        if (!is_array($context)) {
            $context = [];
        }

        $messageText = null;
        switch ($type) {
            case 'positive':
                $messageText = T("Affirmative {User.Name}. {Command}");
                break;

            case 'negative':
                $messageText = T("Negative {User.Name}");
                break;

            case 'custom':
            default:
                $messageText = "{$command}";
                break;
        }

        $messageText = formatString($messageText, array_merge([
            'User' => self::formatUser($user),
            'Discussion' => $discussion,
            'Command' => $command
        ],$context));
        return $this->message($user, $discussion, $messageText, $options);
    }

    /**
     * Revolt in the face of an action that we will not perform
     *
     * @param array $user
     * @param array $discussion
     * @param string $reason
     * @return array|boolean
     */
    public function revolt($user, $discussion, $reason = null) {
        $messagesCount = sizeof($this->messages['Revolt']);
        if ($messagesCount) {
            $messageID = mt_rand(0, $messagesCount - 1);
            $message = val($messageID, $this->messages['Revolt']);
        } else {
            $message = T("Unable to Revolt(), please supply \$messages['Revolt'].");
        }

        if ($reason) {
            $message .= "\n{$reason}";
        }

        return $this->message($user, $discussion, $message);
    }

    /**
     * Gloat after taking action
     *
     * @param array $user
     * @param array $discussion
     * @param string $reason
     * @return array|boolean
     */
    public function gloat($user, $discussion, $reason = null) {
        $messagesCount = sizeof($this->messages['Gloat']);
        if ($messagesCount) {
            $messageID = mt_rand(0, $messagesCount - 1);
            $message = val($messageID, $this->messages['Gloat']);
        } else {
            $message = T("Unable to Gloat(), please supply \$messages['Gloat'].");
        }

        if ($reason) {
            $message .= "\n{$reason}";
        }

        return $this->message($user, $discussion, $message);
    }

    /**
     * Handle "report in" message
     *
     * @param array $user
     * @param array $discussion
     * @return array|boolean
     */
    public function reportIn($user, $discussion) {
        $messagesCount = sizeof($this->messages['Report']);
        if ($messagesCount) {
            $messageID = mt_rand(0, $messagesCount - 1);
            $message = val($messageID, $this->messages['Report']);
        } else {
            $message = T("We are legion.");
        }

        return $this->message($user, $discussion, $message);
    }

    /**
     * Send a message to a discussion
     *
     * @param array $user
     * @param array $discussion
     * @param string $message
     * @param array $options
     * @param array $context
     */
    public function message($user, $discussion, $message, $options = null, $context = null) {
        if (!is_array($options)) {
            $options = [];
        }

        // Options
        $format = val('Format', $options, true);
        $postAs = val('PostAs', $options, 'minion');
        $inform = val('Inform', $options, true);
        $writeComment = val('Comment', $options, true);
        $inputFormat = val('InputFormat', $options, 'Html');

        if (is_numeric($user)) {
            $user = Gdn::userModel()->getID($user, DATASET_TYPE_ARRAY);
            if (!$user) {
                return false;
            }
        }

        if (is_numeric($discussion)) {
            $discussionModel = new DiscussionModel();
            $discussion = $discussionModel->getID($discussion, DATASET_TYPE_ARRAY);
            if (!$discussion) {
                return false;
            }
        }

        $discussionID = val('DiscussionID', $discussion);
        $commentModel = new CommentModel();

        if ($format) {
            if (!is_array($context)) {
                $context = [];
            }
            $message = formatString($message, array_merge(array(
                'User' => self::formatUser($user),
                'Minion' => self::formatUser($this->minion),
                'Discussion' => $discussion
            ), $context));
        }

        if ($inform && Gdn::controller() instanceof Gdn_Controller) {
            $informMessage = Gdn_Format::To($message, 'Html');
            Gdn::controller()->informMessage($informMessage);
        }

        if ($writeComment) {
            $minionCommentID = null;
            if ($message) {

                // Temporarily become Minion
                $sessionUser = Gdn::session()->User;
                $sessionUserID = Gdn::session()->UserID;

                if ($postAs == 'minion') {
                    $postAsUser = (object)$this->minion();
                    $postAsUserID = $this->minionUserID;
                } else {
                    $postAsUser = (object)$postAs;
                    $postAsUserID = val('UserID', $postAsUser);
                }
                Gdn::session()->User = $postAsUser;
                Gdn::session()->UserID = $postAsUserID;

                $minionCommentID = $commentModel->save($comment = array(
                    'DiscussionID' => $discussionID,
                    'Body' => $message,
                    'Format' => $inputFormat,
                    'InsertUserID' => $postAsUserID
                ));

                if ($minionCommentID) {
                    $commentModel->Save2($minionCommentID, true);
                    $comment = $commentModel->getID($minionCommentID, DATASET_TYPE_ARRAY);
                }

                // Become normal again
                Gdn::session()->User = $sessionUser;
                Gdn::session()->UserID = $sessionUserID;
            }

            if ($comment) {
                return $comment;
            }
        }

        return true;
    }

    /**
     * Punish a user (Warnings)
     *
     * @param array $user User to punish
     * @param array $discussion Discussion source
     * @param array $comment Comment source
     * @param string $force Force of punishment
     * @param array $options
     * @return boolean
     */
    public function punish($user, $discussion, $comment, $force, $options = null) {

        // Admins+ exempt
        if (Gdn::userModel()->checkPermission($user, 'Garden.Settings.Manage')) {
            $this->revolt($user, $discussion, T("This user is protected."));
            $this->log(formatString(T("Refusing to punish {User.Mention}"), array(
                'User' => self::formatUser($user)
            )));
            return false;
        }

        $this->EventArguments['Punished'] = false;
        $this->EventArguments['User'] = &$user;
        $this->EventArguments['Discussion'] = &$discussion;
        $this->EventArguments['Comment'] = &$comment;
        $this->EventArguments['Force'] = &$force;
        $this->EventArguments['Options'] = &$options;
        $this->fireEvent('Punish');

        if ($this->EventArguments['Punished']) {
            $this->log(formatString(T("Delivered {Force} punishment to {User.Mention} for {Options.Reason}.\nCause: {Options.Cause}"), array(
                'User' => self::formatUser($user),
                'Discussion' => $discussion,
                'Force' => $force,
                'Options' => $options
            )), $discussion);
        }

        return $this->EventArguments['Punished'];
    }

    /**
     * Run time based actions
     *
     *
     * @param Gdn_Statistics $sender
     */
    public function gdn_statistics_analyticsTick_handler($sender) {
        $this->minionUpkeep(Gdn::controller());
    }

    /**
     * Run upkeep actions
     *
     * @param PluginController $sender
     */
    public function pluginController_minion_create($sender) {
        $this->minionUpkeep($sender);
    }

    /**
     * Upkeep wrapper
     *
     *  - ensures maximum frequency of 1 per 5 minutes
     *
     * @param PluginController $sender
     */
    public function minionUpkeep($sender) {
        $sender->deliveryMethod(DELIVERY_METHOD_JSON);
        $sender->deliveryType(DELIVERY_TYPE_DATA);

        $lastMinionDate = Gdn::get('Plugin.Minion.LastRun', false);
        if (!$lastMinionDate) {
            Gdn::set('Plugin.Minion.LastRun', date('Y-m-d H:i:s'));
            $lastMinionDate = 0;
        }

        $lastMinionTime = strtotime($lastMinionDate);
        if (!$lastMinionTime) {
            $lastMinionTime = 0;
        }

        $sender->setData('Run', false);

        $elapsed = time() - $lastMinionTime;
        $elapsedMinimum = c('Plugins.Minion.MinFrequency', 5 * 60);
        if ($elapsed < $elapsedMinimum) {
            return $sender->render();
        }

        // Remember when we last ran
        Gdn::set('Plugin.Minion.LastRun', date('Y-m-d H:i:s'));
        $sender->setData('Run', true);

        $this->runUpkeep($sender);

        $sender->render();
    }

    /**
     * Run upkeep tasks
     *
     * @param Gdn_Controller $sender
     */
    protected function runUpkeep($sender) {
        // Currently operating as Minion
        $this->minionUserID = $this->getMinionUserID();
        $this->minion = Gdn::userModel()->getID($this->minionUserID, DATASET_TYPE_ARRAY);
        Gdn::session()->User = (object)$this->minion;
        Gdn::session()->UserID = $this->minion['UserID'];

        $sender->setData('MinionUserID', $this->minionUserID);
        $sender->setData('Minion', $this->minion['Name']);

        // Check for fingerprint ban matches
        $this->fingerprintBans($sender);

        // Sometimes update activity feed
        $this->activity($sender);
    }

    /**
     * Check for and banish ban evaders
     *
     * @param type $sender
     * @return type
     */
    protected function fingerprintBans($sender) {
        if (!c('Plugins.Minion.Features.Fingerprint', true)) {
            return;
        }
        $announceBans = c('Plugins.Minion.Features.BanAnnounce', true);

        $sender->setData('FingerprintCheck', true);

        // Get all flagged users
        $userMatchData = Gdn::userMetaModel()->SQL->select('*')
                ->from('UserMeta')
                ->where('Name', 'Plugin.Minion.FingerprintCheck')
                ->get();

        $userStatusData = [];
        while ($userRow = $userMatchData->nextRow(DATASET_TYPE_ARRAY)) {
            $userData = [];

            $userID = $userRow['UserID'];
            $user = Gdn::userModel()->getID($userID);

            // We don't need to worry about users that are already banned
            if ($user->Banned) {
                continue;
            }

            // Get user's fringerprint
            $userFingerprint = val('Fingerprint', $user, false);

            // Unknown user fingerprint
            if (empty($userFingerprint)) {
                continue;
            }

            // Safe users get skipped
            $userSafe = Gdn::userMetaModel()->getUserMeta($userID, "Plugin.Minion.Safe", false);
            $userIsSafe = (boolean)val('Plugin.Minion.Safe', $userSafe, false);
            if ($userIsSafe) {
                continue;
            }

            // Find related fingerprinted users
            $relatedUsers = Gdn::userModel()->getWhere(array(
                'Fingerprint' => $userFingerprint
            ));

            $userRegistrationDate = $user->DateInserted;
            $userRegistrationTime = strtotime($userRegistrationDate);

            // Check if any users matching this fingerprint are banned
            $shouldBan = false;
            $banTriggerUsers = [];
            while ($relatedUser = $relatedUsers->nextRow(DATASET_TYPE_ARRAY)) {
                if ($relatedUser['Banned']) {
                    $relatedRegistrationDate = val('DateInserted', $relatedUser);
                    $relatedRegistrationTime = strtotime($relatedRegistrationDate);

                    // We don't touch accounts that were registered prior to a banned user
                    // This allows admins to ban alts and leave the original alone
                    if ($relatedRegistrationTime > $userRegistrationTime) {
                        continue;
                    }

                    $relatedUserName = $relatedUser['Name'];
                    $shouldBan = true;
                    $banTriggerUsers[$relatedUserName] = $relatedUser;
                }
            }

            $userData['ShouldBan'] = $shouldBan;

            // If the user triggered a ban
            if ($shouldBan) {

                $userData['BanMatches'] = array_keys($banTriggerUsers);
                $userData['BanUser'] = $user;

                // First, ban them
                Gdn::userModel()->ban($userID, array(
                    'AddActivity' => true,
                    'Reason' => "Ban Evasion"
                ));

                // Now comment in the last thread the user posted in
                $commentModel = new CommentModel();
                $lastComment = $commentModel->getWhere(array(
                    'InsertUserID' => $userID
                ), 'DateInserted', 'DESC', 1, 0)->firstRow(DATASET_TYPE_ARRAY);

                if ($lastComment && $announceBans) {
                    $lastDiscussionID = val('DiscussionID', $lastComment);
                    $userData['NotificationDiscussionID'] = $lastDiscussionID;

                    $minionReportText = T("{Minion Name} detected banned alias
Reason: {Banned Aliases}

A house divided will not stand
{Ban Target}");

                    $bannedAliases = [];
                    foreach ($banTriggerUsers as $bannedUserName => $bannedUser) {
                        $bannedAliases[] = userAnchor($bannedUser);
                    }

                    $minionReportText = formatString($minionReportText, array(
                        'Minion Name' => $this->minion['Name'],
                        'Banned Aliases' => implode(', ', $bannedAliases),
                        'Ban Target' => userAnchor($user)
                    ));

                    $minionCommentID = $commentModel->save(array(
                        'DiscussionID' => $lastDiscussionID,
                        'Body' => $minionReportText,
                        'Format' => 'Html',
                        'InsertUserID' => $this->minionUserID
                    ));

                    $commentModel->save2($minionCommentID, true);
                    $userData['NotificationCommentID'] = $minionCommentID;
                }
            }

            $userStatusData[$user->Name] = $userData;
        }

        $sender->setData('Users', $userStatusData);

        // Delete all flags
        Gdn::userMetaModel()->SQL->delete('UserMeta', array(
            'Name' => 'Plugin.Minion.FingerprintCheck'
        ));

        return;
    }

    /**
     * Write to activity stream
     *
     * @param type $sender
     * @return type
     */
    protected function activity($sender) {
        if (!c('Plugins.Minion.Features.Activities', true)) {
            return;
        }

        $sender->setData('ActivityUpdate', true);

        $hitChance = mt_rand(1, 400);
        if ($hitChance != 1) {
            return;
        }

        $messagesCount = sizeof($this->messages['Activity']);
        if ($messagesCount) {
            $messageID = mt_rand(0, $messagesCount - 1);
            $message = val($messageID, $this->messages['Activity']);
        } else {
            $message = T("We are legion.");
        }

        $randomUpdateHash = strtoupper(substr(md5(microtime(true)), 0, 12));
        $activityModel = new ActivityModel();
        $activity = array(
            'ActivityType' => 'WallPost',
            'ActivityUserID' => $this->minionUserID,
            'RegardingUserID' => $this->minionUserID,
            'NotifyUserID' => ActivityModel::NOTIFY_PUBLIC,
            'HeadlineFormat' => "{ActivityUserID,user}: {$randomUpdateHash}$ ",
            'Story' => $message
        );
        $activityModel->save($activity);
    }

    /**
     * Return a user formatted for output
     *
     * This method basically adds a 'Mention' key.
     *
     * @param array $user
     * @return array augmented user
     */
    public static function formatUser($user) {
        if (!array_key_exists('Name', $user)) {
            return $user;
        }

        $user['Mention'] = stristr($user['Name'],' ') !== false ? "@\"{$user['Name']}\"" : "@{$user['Name']}";
        return $user;
    }

    /**
     * Log Minion actions
     *
     * @param string $message
     * @return type
     */
    public function log($message, $targetDiscussion = null, $invokeUser = null) {
        $logThreadID = c('Plugins.Minion.LogThreadID', false);
        if ($logThreadID === false) {
            return;
        }

        if (!is_null($targetDiscussion)) {
            $message .= "\n" . anchor(val('Name', $targetDiscussion), discussionUrl($targetDiscussion));
        }

        if (!is_null($invokeUser)) {
            $message .= "\nInvoked by " . userAnchor($invokeUser);
        }

        return $this->message($this->minion(), $logThreadID, $message);
    }

    /**
     * Clean body text before parsing
     *
     * @param string $text
     * @param boolean $deep
     * @return type
     */
    public static function clean($text, $deep = false) {

        $L = setlocale(LC_ALL, 0);
        setlocale(LC_ALL, 'en_US.UTF8');
        $text = str_replace(array("ä", "ö", "ü", "ß"), array("ae", "oe", "ue", "ss"), $text);

        $r = '';
        $s1 = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        $j = 0;
        for ($i = 0; $i < strlen($s1); $i++) {
            $ch1 = $s1[$i];
            $ch2 = @mb_substr($text, $j++, 1, 'UTF-8');
            if (strstr('`^~\'"', $ch1) !== false) {
                if ($ch1 <> $ch2) {
                    --$j;
                    continue;
                }
            }
            $r .= ($ch1 == '?') ? $ch2 : $ch1;
        }

        setlocale(LC_ALL, $L);
        $r = strtolower($r);

        if ($deep) {
            $r = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $r);
        }

        return $r;
    }

    /*
     * SETUP
     */

    public function setup() {
        $this->structure();
    }

    /**
     * Database structure
     */
    public function structure() {
        // Add 'Attributes' to Conversations
        if (!Gdn::structure()->table('Conversation')->columnExists('Attributes')) {
            Gdn::structure()->table('Conversation')
                    ->column('Attributes', 'text', true)
                    ->set(false, false);
        }
    }

}
