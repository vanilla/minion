<?php if (!defined('APPLICATION')) exit();

/**
 * Hunter Plugin
 *
 * This plugin uses Minion, Reactions, and Badges to create a 'Hunt'. One or
 * more users are targetted by "the law" (minion's persona) and are hunted
 * throughout the forum.
 *
 * Their fellow posters decide if they live or die by either "reporting" them
 * to the authorities, or "hiding" them.
 *
 * Changes:
 *  1.0     Release
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @copyright 2003 Vanilla Forums, Inc
 * @license Proprietary
 * @package misc
 */

$PluginInfo['Hunter'] = array(
   'Name' => 'Minion: Hunter',
   'Description' => "Creates a 'wanted user' game.",
   'Version' => '1.0',
   'RequiredApplications' => array(
      'Vanilla' => '2.1a',
      'Reputation' => '1.0'
    ),
   'RequiredPlugins' => array(
      'Minion' => '1.4.2',
      'Reactions' => '1.2.1'
   ),
   'MobileFriendly' => TRUE,
   'Author' => "Tim Gunter",
   'AuthorEmail' => 'tim@vanillaforums.com',
   'AuthorUrl' => 'http://vanillaforums.com'
);

class HunterPlugin extends Gdn_Plugin {

   /**
    * List of messages that Minion will use
    * @var array
    */
   protected $Messages;

   public function __construct() {
      parent::__construct();

      $this->Messages = array(
         'Stalker'      => array(
            "You can't shake the feeling that you're being watched...",
            "A cold breeze stirs through the forum. You shiver and hurry on your way.",
            "The comments rustle ominously, as though something very large is trying to move quietly through them.",
            "The muffled sound of an avatar snapping underfoot reaches your ears from somewhere nearby...",
            "A low whirring sound seems to draw nearer for a while, and then abruptly diappears.",
            "Out of the corner of your eye you catch something shiny glinting in the dim light. And then it vanishes.",
            "The hairs on the back of your neck stand up. You whirl around, but find nothing.",
            "A large shadow falls across the ground. You shut your eyes tightly for a moment, and when you open them again, the shadow is gone."
         ),
         'Catch'        => array(
            "Thanks to @\"{Player.Name}\", @\"{Fugitive.Name}\" has been taken into custody and will be processed for... 'questioning'."
         ),
         'Escape'       => array(
            "@\"{Fugitive.Name}\" has escaped across international lines. Ending pursuit protocols. Suspect @\"{Player.Name}\" may warrant additional investigation..."
         )
      );

      $this->EventArguments['Messages'] = &$this->Messages;
      $this->FireEvent('Start');
   }

   public function ReactionModel_GetReaction_Handler($sender, $args) {
      $reactionType = &$args['ReactionType'];
      if (!in_array($reactionType['UrlCode'], array('HideCriminal', 'AlertAuthorities'))) return;
      $reactionType['Active'] = true;
   }

   /*
    * ACTIONS
    */

   /**
    * The fugitive was caught
    *
    * @param array $User
    */
   public function FugitiveCatch($User, $Record, $Player) {

      // Badges
      $BadgeModel = new BadgeModel();
      $UserBadgeModel = new UserBadgeModel();

      // Award the Criminal badge
      $CriminalBadge = $BadgeModel->GetID('criminal');
      $UserBadgeModel->Give($User['UserID'], $CriminalBadge['BadgeID']);

      // Award the Snitch badge
      $SnitchBadge = $BadgeModel->GetID('snitch');
      $UserBadgeModel->Give($Player['UserID'], $SnitchBadge['BadgeID']);

      // Gloat
      $MessagesCount = sizeof($this->Messages['Catch']);
      if ($MessagesCount) {
         $MessageID = mt_rand(0, $MessagesCount-1);
         $Message = GetValue($MessageID, $this->Messages['Catch']);
      } else
         $Message = T("Unable to Gloat, please supply \$Messages['Catch'].");

      $Message = FormatString($Message, array(
         'Fugitive'  => $User,
         'Player'    => $Player
      ));
      MinionPlugin::Instance()->message($User, $Record, $Message);

      // Allow external hooks
      $this->EventArguments['Message'] = $Message;
      $this->EventArguments['User'] = $User;
      $this->EventArguments['Record'] = $Record;
      $this->EventArguments['Player'] = $Player;
      $this->FireEvent('FugitiveCatch');

      MinionPlugin::Instance()->monitor($User, array(
         'Hunted' => NULL
      ));
   }

   /**
    * The fugitive escaped
    *
    * @param array $User
    */
   public function FugitiveEscape($User, $Record, $Player) {

      // Badges
      $BadgeModel = new BadgeModel();
      $UserBadgeModel = new UserBadgeModel();

      // Award the Escapee badge
      $EscapeeBadge = $BadgeModel->GetID('escapee');
      $UserBadgeModel->Give($User['UserID'], $EscapeeBadge['BadgeID']);

      // Award the Accessory badge
      $AccessoryBadge = $BadgeModel->GetID('accessory');
      $UserBadgeModel->Give($Player['UserID'], $AccessoryBadge['BadgeID']);

      // Rage
      $MessagesCount = sizeof($this->Messages['Escape']);
      if ($MessagesCount) {
         $MessageID = mt_rand(0, $MessagesCount-1);
         $Message = GetValue($MessageID, $this->Messages['Escape']);
      } else
         $Message = T("Unable to Rage, please supply \$Messages['Escape'].");

      $Message = FormatString($Message, array(
         'Fugitive'  => $User,
         'Player'    => $Player
      ));
      MinionPlugin::Instance()->message($User, $Record, $Message);

      // Allow external hooks
      $this->EventArguments['Message'] = $Message;
      $this->EventArguments['User'] = $User;
      $this->EventArguments['Record'] = $Record;
      $this->EventArguments['Player'] = $Player;
      $this->FireEvent('FugitiveEscape');

      MinionPlugin::Instance()->monitor($User, array(
         'Hunted' => NULL
      ));
   }

   /*
    * MINION INTERFACE
    */

   /**
    * Parse a token from the current state
    *
    * @param MinionPlugin $Sender
    */
   public function MinionPlugin_Token_Handler($Sender) {
      $State = &$Sender->EventArguments['State'];

      if (!$State['Method'] && in_array($State['CompareToken'], array('hunt')))
         $Sender->consume($State, 'Method', 'hunt');

      if ($State['Method'] == 'hunt' && in_array($State['CompareToken'], array('down')))
         $Sender->consume($State, 'Toggle', 'on');

   }

   /**
    * Parse custom minion commands
    *
    * @param MinionPlugin $Sender
    */
   public function MinionPlugin_Command_Handler($Sender) {
      $Actions = &$Sender->EventArguments['Actions'];
      $State = &$Sender->EventArguments['State'];

      switch ($State['Method']) {
         case 'hunt':

            // If we don't know the originating user, try to detect by a quote
            if (!array_key_exists('User', $State['Targets']))
               $Sender->MatchQuoted($State);

            if (!array_key_exists('User', $State['Targets']))
               return;

            $Actions[] = array('hunt', 'Vanilla.Comments.Edit', $State);
            break;
      }

   }

   /**
    * Perform custom minion actions
    *
    * @param MinionPlugin $Sender
    */
   public function MinionPlugin_Action_Handler($Sender) {
      $Action = $Sender->EventArguments['Action'];
      $State = $Sender->EventArguments['State'];

      switch ($Action) {

         case 'hunt':

            if (!array_key_exists('User', $State['Targets']))
               return;

            $User = $State['Targets']['User'];
            $Hunted = $Sender->monitoring($User, 'Hunted', FALSE);

            // Trying to call off a hunt
            if ($State['Toggle'] == 'off') {
               if (!$Hunted) return;

               // Call off the hunt
               $Sender->monitor($User, array(
                  'Hunted'    => NULL
               ));

               $Sender->acknowledge($State['Sources']['Discussion'], FormatString(T("No longer hunting for @\"{User.Name}\"."), array(
                  'User'         => $User
               )));

            // Trying to hunt someone
            } else {
               if ($Hunted) return;

               // Start the hunt
               $Sender->monitor($User, array(
                  'Hunted'    => array(
                     'Started'   => time(),
                     'Points'    => C('Plugins.Hunter.StartPoints', 15),
                     'Target'    => C('Plugins.Hunter.EndPoints', 30)
                  )
               ));

               $Sender->acknowledge($State['Sources']['Discussion'], FormatString(T("Hunting for @\"{User.Name}\"."), array(
                  'User'         => $User
               )));
            }

            break;
      }
   }

   /**
    * Attach hunting tag to this user.
    *
    * @param MinionPlugin $Sender
    */
   public function MinionPlugin_Monitor_Handler($Sender) {
      $User = $Sender->EventArguments['User'];
      $Hunted = $Sender->monitoring($User, 'Hunted', FALSE);
      if (!$Hunted) return;

      if (array_key_exists('Comment', $Sender->EventArguments))
         $Object = &$Sender->EventArguments['Comment'];
      else
         $Object = &$Sender->EventArguments['Discussion'];

      $Sender->monitor($Object, array(
         'Hunted'    => TRUE
      ));
   }

   /*
    * INTERCEPT REACTIONS
    */

   /**
    * Register a
    * @param ReactionsPlugin $Sender
    */
   public function ReactionsPlugin_Reaction_Handler($Sender) {
      $Values = array(
         'alertauthorities'   => 1,
         'hidecriminal'       => -1
      );
      $BaseValue = GetValue($Sender->EventArguments['ReactionUrlCode'], $Values, NULL);
      if (is_null($BaseValue)) return;

      $Object = $Sender->EventArguments['Record'];
      $IsHunted = MinionPlugin::Instance()->monitoring($Object, 'Hunted', FALSE);
      if (!$IsHunted) return;

      $UserID = GetValue('InsertUserID', $Object);
      $User = Gdn::UserModel()->GetID($UserID, DATASET_TYPE_ARRAY);
      $Hunted = MinionPlugin::Instance()->monitoring($User, 'Hunted', FALSE);
      if (!$Hunted) return;

      $Mode = $Sender->EventArguments['Insert'] ? 'set' : 'unset';
      $Change = $Mode == 'set' ? $BaseValue : (0 - $BaseValue);

      // Effect change
      $Hunted['Points'] += $Change;
      MinionPlugin::Instance()->monitor($User, array('Hunted' => $Hunted));

      $Player = (array)Gdn::Session()->User;
      if ($Hunted['Points'] == $Hunted['Target'])
         return $this->FugitiveCatch($User, $Object, $Player);

      if ($Hunted['Points'] == 0)
         return $this->FugitiveEscape($User, $Object, $Player);

      // Else nothing
   }

   /**
    * COMMENT/DISCUSSION DECORATION
    */

   /**
    * Add Hunter reactions to the row.
    */
   public function Base_AfterReactions_Handler($Sender) {
      // Only those who can react
      if (!Gdn::Session()->IsValid()) return;

      if (array_key_exists('Comment', $Sender->EventArguments))
         $Object = (array)$Sender->EventArguments['Comment'];
      else if (array_key_exists('Discussion', $Sender->EventArguments))
         $Object = (array)$Sender->EventArguments['Discussion'];
      else
         return;

      // Is the object hunted?
      $IsHunted = MinionPlugin::Instance()->monitoring($Object, 'Hunted', FALSE);
      if (!$IsHunted) return;

      $User = (array)$Sender->EventArguments['Author'];
      // Don't show it for myself
      if ($User['UserID'] == Gdn::Session()->UserID) return;

      $Hunted = MinionPlugin::Instance()->monitoring($User, 'Hunted', FALSE);
      if (!$Hunted) return;

      echo Gdn_Theme::BulletItem('Hunted');
      echo '<span class="Hunter ReactMenu">';
         echo '<span class="ReactButtons">';
            echo ReactionButton($Object, 'AlertAuthorities');
            echo ReactionButton($Object, 'HideCriminal');
         echo '</span>';
      echo '</span>';
   }

   /**
    * Add Hunter CSS to the row.
    */
   public function Base_BeforeCommentDisplay_Handler($Sender) {
      $Comment = (array)$Sender->EventArguments['Comment'];
      $Attributes = GetValue('Attributes', $Comment);
      if (!is_array($Attributes))
         $Attributes = @unserialize($Attributes);
      $Comment['Attributes'] = $Attributes;

      $this->AddHunterCSS($Sender, $Comment);
   }

   /**
    * Add Hunter CSS to the row.
    */
   public function Base_BeforeDiscussionDisplay_Handler($Sender) {
      $Discussion = (array)$Sender->EventArguments['Discussion'];
      $Attributes = GetValue('Attributes', $Comment);
      if (!is_array($Attributes))
         $Attributes = @unserialize($Attributes);
      $Discussion['Attributes'] = $Attributes;

      $this->AddHunterCSS($Sender, $Discussion);
   }

   /**
    * Add Hunter CSS to the row
    *
    * @param array $Object
    */
   protected function AddHunterCSS($Sender, $Object) {
      // Is the object hunted?
      $IsHunted = MinionPlugin::Instance()->monitoring($Object, 'Hunted', FALSE);
      if (!$IsHunted)
         return;

      $User = (array)$Sender->EventArguments['Author'];
      $Hunted = MinionPlugin::Instance()->monitoring($User, 'Hunted', FALSE);
      if (!$Hunted) return;

      $HuntStarted = $Hunted['Started'];
      $DateInsertedTime = strtotime($Object['DateInserted']);
      if ($DateInsertedTime < $HuntStarted) {
         MinionPlugin::Instance()->monitor($Object, array(
            'Hunted' => NULL
         ));
         return;
      }

      $Sender->EventArguments['CssClass'] .= ' Fugitive';
   }

   /*
    * USER FEAR POPUP
    */

   /**
    * Stalk hunted users
    *
    * @param Gdn_Controller $Sender
    */
   public function Base_Render_Before($Sender) {
      // Only full pages
      if ($Sender->DeliveryType() != DELIVERY_TYPE_ALL) return;

      $User = (array)Gdn::Session()->User;
      $Hunted = MinionPlugin::Instance()->monitoring($User, 'Hunted', FALSE);
      if (!$Hunted) return;

      // User is hunted!
      $Points = $Hunted['Points'];

      $MessagesCount = sizeof($this->Messages['Stalker']);
      if ($MessagesCount) {
         $MessageID = mt_rand(0, $MessagesCount-1);
         $Message = GetValue($MessageID, $this->Messages['Stalker']);
      } else
         $Message = T("Unable to Stalk, please supply \$Messages['Stalker'].");

      $Message = FormatString($Message, array(
         'Fugitive'  => $User,
         'Minion'    => MinionPlugin::Instance()->minion()
      ));
      $Sender->InformMessage($Message);
   }

   /*
    * SETUP
    */

   public function Setup() {
      $this->Structure();
   }

   public function Structure() {

      // Define 'Alert Authorities' and 'Hide Criminal' reactions
      $Rm = new ReactionModel();

      if (Gdn::Structure()->Table('ReactionType')->ColumnExists('Hidden')) {

         // AlertAuthorities
         $Rm->DefineReactionType(array(
            'UrlCode' => 'AlertAuthorities',
            'Name' => 'Alert Authorities',
            'Sort' => 0,
            'Class' => 'Good',
            'Hidden' => 1,
            'Description' => "Use this to 'Alert' Minion to the presence of a criminal currently being pursued."
         ));

         // HideCriminal
         $Rm->DefineReactionType(array(
            'UrlCode' => 'HideCriminal',
            'Name' => 'Hide Criminal',
            'Sort' => 0,
            'Class' => 'Good',
            'Hidden' => 1,
            'Description' => "Use this to 'Hide' a criminal being pursued by Minion."
         ));

      }
      Gdn::Structure()->Reset();

      // Define 'Hunter' badges
      $BadgeModel = new BadgeModel();

      // Criminal
      $BadgeModel->Define(array(
          'Name' => 'Criminal',
          'Slug' => 'criminal',
          'Type' => 'Manual',
          'Body' => "The deep emotional scars you received during your capture will haunt you for the rest of your life.",
          'Photo' => 'http://badges.vni.la/100/criminal.png',
          'Points' => 10,
          'Class' => 'Hunter',
          'Level' => 1,
          'CanDelete' => 0
      ));

      // Escapee
      $BadgeModel->Define(array(
          'Name' => 'Escapee',
          'Slug' => 'escapee',
          'Type' => 'Manual',
          'Body' => "Having evaded capture, you now live on the run. Begging and stealing to survive.",
          'Photo' => 'http://badges.vni.la/100/escapee.png',
          'Points' => 20,
          'Class' => 'Hunter',
          'Level' => 1,
          'CanDelete' => 0
      ));

      // Snitch
      $BadgeModel->Define(array(
          'Name' => 'Snitch',
          'Slug' => 'snitch',
          'Type' => 'Manual',
          'Body' => "There's no nice way to say this. You're a dirty snitch.",
          'Photo' => 'http://badges.vni.la/100/snitch.png',
          'Points' => 1,
          'Class' => 'Hunter',
          'Level' => 1,
          'CanDelete' => 0
      ));

      // Accessory
      $BadgeModel->Define(array(
          'Name' => 'Accessory',
          'Slug' => 'accessory',
          'Type' => 'Manual',
          'Body' => "You were an accessory after the fact, and the criminal escaped. For shame.",
          'Photo' => 'http://badges.vni.la/100/accessory.png',
          'Points' => 1,
          'Class' => 'Hunter',
          'Level' => 1,
          'CanDelete' => 0
      ));
   }
}