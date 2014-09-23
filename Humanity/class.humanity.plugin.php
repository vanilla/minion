<?php

/**
 * @copyright 2003 Vanilla Forums, Inc
 * @license Proprietary
 */

$PluginInfo['Humanity'] = array(
   'Name' => 'Minion: Humanity',
   'Description' => "Allow forum members to play Cards Against Humanity.",
   'Version' => '1.0a',
   'RequiredApplications' => array(
      'Vanilla' => '2.1a',
      'Reputation' => '1.0'
    ),
   'RequiredPlugins' => array(
      'Minion' => '1.14',
      'Reactions' => '1.2.1',
      'Gaming' => '1.0'
   ),
   'MobileFriendly' => TRUE,
   'Author' => "Tim Gunter",
   'AuthorEmail' => 'tim@vanillaforums.com',
   'AuthorUrl' => 'http://vanillaforums.com'
);

/**
 * Cards Against Humanity Plugin
 *
 * This plugin uses Minion, Reactions, and Badges to implement Cards Against
 * Humanity as a forum game.
 *
 * Changes:
 *  1.0     Release
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package minion
 */
class HumanityPlugin extends Gdn_Plugin {

   /**
    * Reaction model
    * @var ReactionModel
    */
   protected $reactionModel;

   /**
    * Badge model
    * @var BadgeModel
    */
   protected $badgeModel;

   /**
    * UserBadge model
    * @var UserBadgeModel
    */
   protected $userBadgeModel;

   /**
    * Activity model
    * @var ActivityModel
    */
   protected $activityModel;

   /**
    * Minion Plugin reference
    * @var MinionPlugin
    */
   protected $minion;
   protected $minionUser;

   protected $expansionsDir;
   protected $expansions = null;
   protected $cards = null;

   /**
    * Game info
    * @var array
    */
   protected $game = array();

   /**
    * Local inventory cache
    * All the invetories we know about right now. Lazy loaded.
    * @var array
    */
   protected $inventory;

   const CARD_TYPE_QUESTION = 'Q';
   const CARD_TYPE_ANSWER = 'A';

   const EXPANSIONS_KEY = 'plugin.humanity.expansions';
   const EXPANSION_KEY = 'plugin.humanity.expansion.%s';
   const GAME_KEY = 'game.%d';

   public function __construct() {
      parent::__construct();

      $this->reactionModel = new ReactionModel();
      $this->badgeModel = new BadgeModel();
      $this->userBadgeModel = new UserBadgeModel();
      $this->activityModel = new ActivityModel();

      $this->expansionsDir = paths($this->getPluginFolder(), 'data');
   }

   /**
    * Hook early and perform game actions
    *
    * @param Gdn_Dispatcher $sender
    */
   public function Gdn_Dispatcher_AppStartup_Handler($sender) {
      $this->minion = MinionPlugin::instance();
      $this->minionUser = (array)$this->minion->minion();
   }

   /**
    * Register the game
    *
    * @param GamingPlugin $sender
    */
   public function GamingPlugin_Register_Handler($sender) {
      $sender->registerGame('Cards Against Humanity', array(
         'cards against humanity',
         'cah'
      ), 'HumanityPlugin');
   }

   /**
    * Include HumanityController for /humanity requests
    *
    * Manually detect and include humanity controller when a request comes in
    * that probably uses it.
    *
    * @param Gdn_Dispatcher $sender
    */
   public function Gdn_Dispatcher_BeforeDispatch_Handler($sender) {
      $path = $sender->EventArguments['Request']->path();
      if (preg_match('`^/?humanity`i', $path)) {
         require_once($this->getResource('controllers/class.humanitycontroller.php'));
      }
   }

   /**
    * Add CAH custom CSS
    *
    * @param AssetModel $sender
    */
   public function AssetModel_StyleCss_Handler($sender) {
      $sender->addCssFile('humanity.css', 'plugins/Humanity');
   }

   /**
    * Get a list of expansions
    */
   public function expansions() {
      if (is_null($this->expansions)) {
         $this->expansions = array();

         // Check the cache
         $expansions = Gdn::cache()->get(self::EXPANSIONS_KEY);
         if ($expansions) {
            $this->expansions = $expansions;
            return $this->expansions;
         }

         // Manually index
         $enabledExpansions = C('Plugins.Humanity.Expansions', true);
         $expansionScan = scandir($this->expansionsDir);
         foreach ($expansionScan as $expansionName) {
            if (substr($expansionName, 0, 1) == '.') continue;
            $expansionDir = paths($this->expansionsDir, $expansionName);
            if (!is_dir($expansionDir)) continue;

            $expansionFile = paths($expansionDir, 'expansion.json');
            $expansionData = file_get_contents($expansionFile);
            $expansion = json_decode($expansionData, true);

            $expansion = array_merge($expansion, array(
               'dir'       => $expansionDir,
               'enabled'   => (in_array($expansionName, $enabledExpansions) || $enabledExpansions === true) ? true : false
            ));
            $this->expansions[$expansionName] = $expansion;
         }

         Gdn::cache()->store(self::EXPANSIONS_KEY, $this->expansions);
      }

      return $this->expansions;
   }

   /**
    * Get cards
    *
    * Optionally filter by type.
    *
    * @param string $type optional
    */
   public function cards($type = null) {
      if (is_null($this->cards)) {

         $this->cards = array(
            self::CARD_TYPE_QUESTION   => array(),
            self::CARD_TYPE_ANSWER     => array()
         );

         $expansions = $this->expansions();
         foreach ($expansions as $expansionName => $expansion) {
            // Check the cache
            $expansionKey = sprintf(self::EXPANSION_KEY, $expansionName);
            $expansionCards = Gdn::cache()->get($expansionKey);

            // Manually index
            if ($expansionCards === Gdn_Cache::CACHEOP_FAILURE) {
               $expansionDir = paths($this->expansionsDir, $expansionName);

               $questionFile = paths($expansionDir, 'questions.json');
               $questionData = file_get_contents($questionFile);
               $questions = json_decode($questionData, true);

               $answerFile = paths($expansionDir, 'answers.json');
               $answerData = file_get_contents($answerFile);
               $answers = json_decode($answerData, true);

               $expansionCards = array(
                  self::CARD_TYPE_QUESTION   => $questions,
                  self::CARD_TYPE_ANSWER     => $answers
               );
               Gdn::cache()->store($expansionKey, $expansionCards);
            }

            // Add to array
            if (is_array($expansionCards)) {
               $this->cards[self::CARD_TYPE_QUESTION] = array_merge($this->cards[self::CARD_TYPE_QUESTION], $expansionCards[self::CARD_TYPE_QUESTION]);
               $this->cards[self::CARD_TYPE_ANSWER] = array_merge($this->cards[self::CARD_TYPE_ANSWER], $expansionCards[self::CARD_TYPE_ANSWER]);
            }
         }

         $this->cards[self::CARD_TYPE_QUESTION] = Gdn_DataSet::index($this->cards[self::CARD_TYPE_QUESTION], array('id'));
         $this->cards[self::CARD_TYPE_ANSWER] = Gdn_DataSet::index($this->cards[self::CARD_TYPE_ANSWER], array('id'));
      }

      if ($type && array_key_exists($type, $this->cards))
         return $this->cards[$type];
      return $this->cards;
   }

   /**
    * Get a card
    *
    * @param string $cardID
    */
   public function card($cardID) {
      // Check answers first. Most frequent.
      if (array_key_exists($cardID, $this->cards(self::CARD_TYPE_ANSWER)))
         return $this->cards[self::CARD_TYPE_ANSWER][$cardID];

      // Then questions
      if (array_key_exists($cardID, $this->cards(self::CARD_TYPE_QUESTION)))
         return $this->cards[self::CARD_TYPE_QUESTION][$cardID];

      return false;
   }

   /**
    * Render a card
    *
    * @param string|array $card card ID or card array
    * @return string
    */
   public function renderCard($card) {
      if (is_string($card))
         $card = $this->card($card);

      $cardType = $card['type'] == self::CARD_TYPE_ANSWER ? 'Answer' : 'Question';
      $cardText = str_replace('_', str_repeat('_', 10), $card['text']);
      $cardCode = "<div class=\"CardAgainstHumanity {$cardType}\">";
      $cardCode .= "   <div class=\"CardText\">{$cardText}</div>";
      $cardCode .= "   <div class=\"Expansion\">{$card['expansion']}</div>";
      $cardCode .= '</div>';
      return $cardCode;
   }

   /**
    * Start a new game
    *
    * @param array $discussion
    * @param array $user
    */
   public function startGame(&$discussion, &$user) {

      $discussionID = val('DiscussionID', $discussion);
      $userID = val('UserID', $user);

      // Tag the discussion
      $this->minion->monitor($discussion, array(
         'humanity' => array(
            'type'         => 'op',
            'user'         => $user['UserID']
         )
      ));

      // Create the game
      $game = array(
         'discussion'      => $discussionID,
         'user'            => $userID,
         'mode'            => 'join',
         'players'         => array(),
         'rounds'          => 0,
         'rules'           => array()
      );
      $gameKey = sprintf(self::GAME_KEY, $discussionID);
      $this->SetUserMeta(0, $gameKey, $game);
      $this->game[$discussionID] = $game;

      // Announce new game
      $message = <<<HUMANITY
Starting a new game of Cards Against Humanity!

To join in, click "Play" at the bottom of this post.

Players:
HUMANITY;
      $message = T('Plugins.Humanity.JoinMessage', $message);
      $comment = $this->minion->message($user, $discussion, $message);
      $this->minion->monitor($comment, array(
         'humanity'  => array(
            'type'   => 'join'
         )
      ));
   }

   /**
    * Stop an active game
    *
    * @param array $discussion
    * @param array $user
    */
   public function stopGame(&$discussion, &$user) {

   }

   /**
    * Load a game's info
    *
    * @param array $discussion
    */
   public function game($discussion) {
      $discussionID = val('DiscussionID', $discussion);
      if (!array_key_exists($discussionID, $this->game)) {
         $running = $this->running($discussion);
         if (!$running)
            return $this->game[$discussionID] = false;

         $gameKey = sprintf(self::GAME_KEY, $discussionID);
         $game = $this->GetUserMeta(0, $gameKey);
         $this->game[$discussionID] = $game;
      }
      return $this->game[$discussionID];
   }

   /**
    * Save updates to this game
    *
    * @param array $discussion
    * @param array $game
    */
   public function saveGame(&$discussion, $game) {

   }

   /**
    * Toggle a game rule on or off
    *
    * @param array $discussion
    * @param string $rule
    * @param bool $toggle
    */
   public function rule(&$discussion, $rule, $toggle) {

      // Rebooting the Universe - Exchange an Awesome Point to redraw cards
      // Packing Heat - Draw an extra card when question is a Pick 2
      // Rando Cardrissian - Phantom player
      // Never Have I Ever - Discard a card at any time, admit shameful ignorance
      // Gambling - Use an awesome point to play twice

   }

   /**
    * Show the available and active rules
    *
    * @param array $discussion
    */
   public function rules(&$discussion) {

   }

   /**
    * Get a user's inventory in a discussion
    *
    * @param array $discussion
    * @param integer $userid optional. default current user
    */
   public function inventory(&$discussion, $userid = null) {
      if (!$this->playing($discussion)) return false;

      $discussionID = val('DiscussionID', $discussion);
      if (is_null($userid))
         $userid = Gdn::Session()->UserID;
      $key = "{$discussionID}:{$userid}";

      // Already have it?
      if (array_key_exists($key, $this->inventory) && $this->inventory[$key] instanceof Inventory)
         return $this->inventory[$key];

      // Don't have it?
      if (!array_key_exists($key, $this->inventory)) {
         $this->inventory[$key] = Inventory::get($userid, 'discussion', $discussionID);
         return $this->inventory[$key];
      }

      // Key exists but is not an inventory
      return false;
   }

   /**
    * Check if the supplied discussion is a game
    *
    * @param array $discussion
    */
   public function running(&$discussion) {
      // Only active for humanity posts
      $game = $this->gameminion->monitoring($discussion, 'humanity');
      if (!$game) return false;

      $mode = val('mode', $game);
      if ($mode == 'stopped') return false;
      return $game;
   }

   /**
    * Check if the current user is playing
    *
    * @param array $discussion
    * @return boolean
    */
   public function playing(&$discussion, $user = null) {
      if (!Gdn::Session()->IsValid()) return false;

      $game = $this->game($discussion);
      if (!$game) return false;

      $players = val('players', $game);
      return array_key_exists(Gdn::Session()->UserID, $players);
   }

   /**
    * Add a player to the game
    *
    * @param type $discussion
    * @param type $user
    * @return boolean
    */
   public function join(&$discussion, $user) {
      $game = $this->running($discussion);
      $players = val('players', $game);
      $isPlaying = array_key_exists($user['UserID'], $players);
      if ($isPlaying) return true;

      // Add to players
      $players[$user['UserID']] = array(
         'points'
      );
   }

   /*
    * API
    */

   public function HumanityController_Play_Create($sender) {
      $sender->Render();
   }

   /*
    * REACTIONS
    */

   /**
    * Show different reactions based on post type
    *
    * join - this is an invitation for people to join. show join/quit buttons.
    *
    * @param type $sender
    * @return type
    */
   public function DiscussionController_AfterReactions_Handler($sender) {
      if (!Gdn::Session()->IsValid()) return;

      $object = null;
      if (array_key_exists('Discussion', $sender->EventArguments))
         $object = $discussion = (array)$sender->EventArguments['Discussion'];

      if (array_key_exists('Comment', $sender->EventArguments))
         $object = $comment = (array)$sender->EventArguments['Comment'];

      if (!$object) return;

      $buttons = array();

      // Only active for humanity posts
      $game = $this->minion->monitoring($discussion, 'humanity');
      if (!$game) return;

      $mode = val('mode', $game);

      $players = val('players', $game);
      $isPlayer = array_key_exists(Gdn::Session()->UserID, $players);

      $humanity = $this->minion->monitoring($object, 'humanity');
      if ($humanity) {
         $type = val('type', $humanity);

         switch ($type) {
            case 'join':
               switch ($mode) {
                  case 'join':
                     if (!$isPlayer) $buttons[] = 'PlayHumanity';
                     break;
                  default:
                     if ($isPlayer) $buttons[] = 'QuitHumanity';
                     break;
               }
               break;

            // A black card
            case 'question':
               break;

            // One or more white cards
            case 'answer':
               break;

            default:

         }
      }

      // Add the buttons
      $this->addButtons($buttons, $object);
   }

   /**
    * Add game reaction buttons
    *
    * @param array $types
    * @param array $object
    */
   public function addButtons($types, $object) {
      $types = (array)$types;
      if (!sizeof($types)) return;

      echo Gdn_Theme::BulletItem('Humanity');
      echo '<span class="Humanity ReactMenu">';
         echo '<span class="ReactButtons">';

         foreach ($types as $type)
            echo $this->minion->actionButton($object, $type);

         echo '</span>';
      echo '</span>';
   }

   /*
    * POST STYLES
    */

   /**
    * Prepare to render comments
    *
    * @param Controller $sender
    */

   public function Base_BeforeCommentDisplay_Handler($sender) {
      //$this->rowStyle($sender, $sender->EventArguments['Author'], $sender->EventArguments['Comment']);
   }

   /**
    * Perform checks on object to determine rendering
    *
    * Adds hint to comments and discussions when needed. Also scrambles
    * body text.
    *
    * @param Controller $sender
    * @param array $author
    * @param array $comment
    */
   public function rowStyle($sender, &$author, &$comment) {

      // Get user info
      $user = (array)Gdn::Session()->User;
      $userID = $user['UserID'];
      $author = $author;
      $authorID = val('UserID', $author);

      // Determine state

      $sender->EventArguments['CssClass'] .= ' Kidnapper';
   }

   public function DiscussionController_BeforeBodyField_Handler($sender) {
      return;
      $discussion = (array)$sender->Data('Discussion');

      $playing = $this->playing($discussion);
      if (!$playing) return;
      $inventory = $this->inventory($discussion);

      var_dump($inventory);
      die();

      $game = $this->minion->monitoring($discussion, 'Humanity');
      $mode = val('mode', $game);

      switch ($mode) {
         case 'play':
            $inventoryMode = 'Active';
            break;
         case 'judge':
            $inventoryMode = 'Inactive';
            break;
      }

      $message = '';

      echo Wrap($message, 'div', array('class' => "HumanityAvailableCards {$inventoryMode}"));
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
      $State = &$sender->EventArguments['State'];

      if (!$State['Method'] && in_array($State['CompareToken'], array('play', 'playing'))) {
         $sender->consume($State, 'Method', 'play');

         $sender->consume($State, 'Gather', array(
            'Node'   => 'Phrase',
            'Delta'  => ''
         ));
      }

      if (!$State['Method'] && in_array($State['CompareToken'], array('rule'))) {
         $sender->consume($State, 'Method', 'rule');

         $sender->consume($State, 'Gather', array(
            'Node'   => 'Phrase',
            'Delta'  => ''
         ));
      }

      if (!$State['Method'] && in_array($State['CompareToken'], array('rules'))) {
         $sender->consume($State, 'Method', 'rules');
      }
   }

   /**
    * Parse custom minion commands
    *
    * @param MinionPlugin $sender
    */
   public function MinionPlugin_Command_Handler($sender) {
      $Actions = &$sender->EventArguments['Actions'];
      $State = &$sender->EventArguments['State'];

      switch ($State['Method']) {
         case 'play':

            // Games must be started in the OP
            if (array_key_exists('Comment', $State['Sources']))
               return;

            // Games must have a name
            if (!array_key_exists('Phrase', $State['Targets']))
               return;

            $Actions[] = array('play', 'Garden.Moderation.Manage', $State);
            break;

         case 'rule':
            // Rules must have a name
            if (!array_key_exists('Phrase', $State['Targets']))
               return;

            $Actions[] = array('rule', null, $State);
            break;

         case 'rules':
            $Actions[] = array('rules', null, $State);
            break;
      }

   }

   /**
    * Perform custom minion actions
    *
    * @param MinionPlugin $sender
    */
   public function MinionPlugin_Action_Handler($sender) {
      $Action = $sender->EventArguments['Action'];
      $State = &$sender->EventArguments['State'];

      switch ($Action) {

         // Play a game, or shut one down
         case 'play':

            $gameName = strtolower(valr('Targets.Phrase', $State));
            if (!in_array($gameName, array(
               'cards against humanity',
               'cah'
            ))) {
               break;
            }

            // Games must be started in the OP
            if (array_key_exists('Comment', $State['Sources']))
               return;

            switch ($State['Toggle']) {

               case 'off':
                  $this->stopGame($State['Sources']['Discussion'], $State['Sources']['User']);
                  break;

               case 'on':
               default:
                  $this->startGame($State['Sources']['Discussion'], $State['Sources']['User']);
                  break;

            }

            break;

         // Add a game rule
         case 'rule':
            $this->rule($State['Sources']['Discussion'], $State['Targets']['Phrase'], $State['Toggle']);
            break;

         // Display the available and activated game rules
         case 'rules':
            $this->rules($State['Sources']['Discussion']);
            break;
      }
   }

   /**
    * Add [card] BBCode rule to NBBC
    *
    * @param NBBCPlugin $sender
    */
   public function NBBCPlugin_AfterNBBCSetup_Handler($sender) {
      $bbcode = &$sender->EventArguments['BBCode'];
      $bbcode->AddRule('card', array(
         'mode' => BBCODE_MODE_CALLBACK,
         'method' => array($this, "nbbcCard"),
         'allow_in' => array('listitem', 'block', 'columns', 'inline', 'link'),
         'end_tag' => BBCODE_REQUIRED,
         'content' => BBCODE_REQUIRED,
         'plain_start' => "[card]",
         'plain_content' => array(),
         ));
   }

   /**
    * Render a card
    *
    * @param type $bbcode
    * @param type $action
    * @param type $name
    * @param type $default
    * @param type $params
    * @param type $content
    * @return boolean|string
    */
   public function nbbcCard($bbcode, $action, $name, $default, $params, $content) {
      if ($action == BBCODE_CHECK)
         return true;

      $content = trim($bbcode->UnHTMLEncode(strip_tags($content)));
      if (!$content && $default)
         $content = $default;

      $cardCode = $this->renderCard($content);
      if ($cardCode) {
         return $cardCode;
      }

      return htmlspecialchars($params['_tag']) . htmlspecialchars($content) . htmlspecialchars($params['_endtag']);
   }

   /**
    * Plugin setup on-enable
    */
   public function Setup() {
      $this->Structure();
   }

   /**
    * Database structure
    */
   public function Structure() {

      // Define Game reactions

      if (Gdn::Structure()->Table('ReactionType')->ColumnExists('Hidden')) {

         // Play
         $this->reactionModel->DefineReactionType(array(
            'UrlCode' => 'PlayHumanity',
            'Name' => 'Play',
            'Sort' => 0,
            'Class' => 'Humanity',
            'Hidden' => 1,
            'Description' => "Join this game of Cards Against Humanity.",
            'Custom' => 1,
            'CustomType' => 'url',
            'Url' => '/humanity/play'
         ));

         // Quit
         $this->reactionModel->DefineReactionType(array(
            'UrlCode' => 'QuitHumanity',
            'Name' => 'Quit',
            'Sort' => 0,
            'Class' => 'Humanity',
            'Hidden' => 1,
            'Description' => "Quit this game of Cards Against Humanity.",
            'Custom' => 1,
            'CustomType' => 'url',
            'Url' => '/humanity/quit'
         ));

      }

      // Define Game badges
      Gdn::Structure()->Reset();

      $this->activityModel->DefineType('Humanity', array(
         'Notify'    => 1,
         'Public'    => 0
      ));

   }

}