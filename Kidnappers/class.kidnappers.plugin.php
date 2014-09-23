<?php if (!defined('APPLICATION')) exit();

/**
 * Kidnappers Plugin
 *
 * This plugin uses Minion, Reactions, and Badges to create a kidnapping game.
 *
 * THE GAME
 *
 *
 *
 *
 * Changes:
 *  1.0     Release
 *  1.1     Add reaction icons
 *  1.2     Add kidnapper mini tutorial
 *  1.3     Change wording to hint at forumer
 *  1.4     Added hints and new CSS for mobile
 *  1.5     Show informants differently
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @copyright 2003 Vanilla Forums, Inc
 * @license Proprietary
 * @package misc
 */

$PluginInfo['Kidnappers'] = array(
   'Name' => 'Minion: Kidnappers',
   'Description' => "Kidnappers game and badges.",
   'Version' => '1.5',
   'RequiredApplications' => array(
      'Vanilla' => '2.1a',
      'Reputation' => '1.0'
    ),
   'RequiredPlugins' => array(
      'Minion' => '1.12',
      'Reactions' => '1.2.1'
   ),
   'MobileFriendly' => TRUE,
   'Author' => "Tim Gunter",
   'AuthorEmail' => 'tim@vanillaforums.com',
   'AuthorUrl' => 'http://vanillaforums.com'
);

class KidnappersPlugin extends Gdn_Plugin {

   /**
    * Is the Kidnapper game enabled for this request?
    * @var boolean
    */
   protected $Enabled;

   /**
    * List of kidnappers and their cooldowns
    * @var array
    */
   protected $Kidnappers;

   /**
    * List of kidnapped people and their expiries
    * @var array
    */
   protected $Kidnapped;

   /**
    * List of hints
    * @var array
    */
   protected $Hints;

   /**
    * Seconds a kidnapper must wait between kidnappings
    * @var integer
    */
   protected $KidnapCooldown;

   /**
    * Seconds a kidnapee has to be rescued before getting Stockholm Syndrome
    * @var integer
    */
   protected $KidnapExpiry;

   /**
    * Reaction model
    * @var ReactionModel
    */
   protected $ReactionModel;

   /**
    * Badge model
    * @var BadgeModel
    */
   protected $BadgeModel;

   /**
    * UserBadge model
    * @var UserBadgeModel
    */
   protected $UserBadgeModel;

   /**
    * Activity model
    * @var ActivityModel
    */
   protected $ActivityModel;

   /**
    * Minion Plugin reference
    * @var MinionPlugin
    */
   protected $Minion;
   protected $MinionUser;

   const KIDNAPPER_KEY = "Kidnapper";
   const KIDNAPPED_KEY = "Kidnapped";
   const STOCKHOLM_KEY = "Stockholm";
   const HINTS_CACHE = "Plugin.Kidnappers.Hints";
   const EXPIRY_CHECK_CACHE = "Plugin.Kidnappers.ExpiryCheck";

   /**
    * Startup configuration
    *
    */
   public function __construct() {
      parent::__construct();
      $this->Enabled = true;

      $this->ReactionModel = new ReactionModel();
      $this->BadgeModel = new BadgeModel();
      $this->UserBadgeModel = new UserBadgeModel();
      $this->ActivityModel = new ActivityModel();

      $this->KidnapExpiry = C('Plugins.Kidnappers.Expiry', 900);
      $this->KidnapCooldown = C('Plugins.Kidnappers.KidnapCooldown', 600);
      $this->MinionAnnounce = C('Plugins.Kidnappers.Announce', false);
      $this->InformantChance = C('Plugins.Kidnappers.InformantChance', 10);

      $this->Kidnappers = array();
      $this->Kidnapped = array();

      $HintsKey = self::HINTS_CACHE;
      $this->Hints = Gdn::Cache()->Get($HintsKey);
      if (!$this->Hints) {
         $this->Hints = array();
         $Hints = trim(file_get_contents($this->GetResource('hints')));
         $Hints = explode("\n\n", $Hints);
         foreach ($Hints as $Hint) {
            $Hint = trim($Hint);
            $Hint = explode("\n", $Hint);
            $HintID = array_shift($Hint);
            $Answer = array_pop($Hint);
            $Clue = implode("\n", $Hint);

            $this->Hints["hint-{$HintID}"] = array('Clue' => $Clue, 'Answer' => $Answer, 'ID' => $HintID);
         }
         Gdn::Cache()->Store($HintsKey, $this->Hints);
      }
   }

   /**
    * Hook into minion startup
    *
    * @param MinionPlugin $Sender
    */
   public function MinionPlugin_Start_Handler($Sender) {

      // Register persona
      $Sender->persona('Kidnapper', array(
         'Name'      => 'Donbot',
         'Photo'     => 'http://cdn.vanillaforums.com/minion/donbot.png',
         'Title'     => 'Doin crimes',
         'Location'  => "Fronty's Meat Market"
      ));

      // Change persona
      if ($this->Enabled)
         $Sender->persona('Kidnapper');
   }

   /**
    * Hook early and perform game actions
    *
    * @param Gdn_Dispatcher $Sender
    * @return type
    */
   public function Gdn_Dispatcher_AppStartup_Handler($Sender) {
      $this->Minion = MinionPlugin::Instance();
      $this->MinionUser = $this->Minion->minion();
   }

   /**
    * Add CSS files
    *
    * @param AssetModel $Sender
    * @param type $Args
    */
   public function AssetModel_StyleCss_Handler($Sender, $Args) {
      $Sender->AddCssFile('kidnappers.css', 'plugins/Kidnappers');
   }

   /**
    * Check if this person is a kidnapper
    *
    * @param integer $UserID
    * @return boolean
    */
   public function IsKidnapper($UserID) {
      $Kidnapper = GetValue($UserID, $this->Kidnappers, null);

      // Known non kidnapper
      if ($Kidnapper === FALSE) {
         return false;

      // Unknown, query
      } elseif (!$Kidnapper) {
         $IsKidnapper = $this->GetUserMeta($UserID, self::KIDNAPPER_KEY, false, true);
         if ($IsKidnapper) {
            $IsKidnapper = @json_decode($IsKidnapper, true);
         }
         $this->Kidnappers[$UserID] = $IsKidnapper;
         return $this->Kidnappers[$UserID];
      }
      return $Kidnapper;
   }

   /**
    * Check if this person is currently kidnapped
    *
    * @param integer $UserID
    * @return boolean
    */
   public function IsKidnapped($UserID) {
      $Kidnapped = GetValue($UserID, $this->Kidnapped, null);

      // Known non kidnapper
      if ($Kidnapped === FALSE) {
         return false;

      // Unknown, query
      } elseif (!$Kidnapped) {
         $IsKidnapped = $this->GetUserMeta($UserID, self::KIDNAPPED_KEY, false, true);
         if ($IsKidnapped) {
            $IsKidnapped = @json_decode($IsKidnapped, true);
         }

         $this->Kidnapped[$UserID] = $IsKidnapped;
         return $this->Kidnapped[$UserID];
      }
      return $Kidnapped;
   }

   /**
    * Check if this person can kidnap people
    *
    * Checks both IsKidnapper and the resulting Cooldown time to see if this
    * person is both a kidnapper and is able to kidnap people at this time.
    *
    * @param integer $UserID
    * @return boolean
    */
   public function CanKidnap($UserID) {
      $Kidnapper = $this->IsKidnapper($UserID);
      if (!$Kidnapper) return false;

      $Cooldown = GetValue('Cooldown', $Kidnapper);
      if ($Cooldown > time()) return false;
      return true;
   }

   /**
    * Check if this person is and informant
    *
    * Checks IsKidnapper, then looks for the Informant flag. Informants can kidnap
    * and rescue at the same time.
    *
    * @param integer $UserID
    * @return boolean
    */
   public function IsInformant($UserID) {
      $Kidnapper = $this->IsKidnapper($UserID);
      if (!$Kidnapper) return false;

      return (bool)GetValue('Informant', $Kidnapper, false);
   }

   /*
    * METHODS
    */

   /**
    * Include KidnappersController for /kidnappers requests
    *
    * Manually detect and include kidnappers controller when a request comes in
    * that probably uses it.
    *
    * @param Gdn_Dispatcher $Sender
    */
   public function Gdn_Dispatcher_BeforeDispatch_Handler($Sender) {
      $Path = $Sender->EventArguments['Request']->Path();
      if (preg_match('`^/?kidnappers`i', $Path)) {
         require_once($this->GetResource('class.kidnapperscontroller.php'));
      }
   }

   /**
    * Handle game actions
    *
    * Possible sub items are:
    *  - kidnap
    *  - rescue
    *  - kidnapper
    *
    * @param PluginController $Sender
    * @param string $Action
    * @param string $Type
    * @param integer $ID
    */
   public function KidnappersController_Action_Create($Sender, $Action, $Type, $ID) {
      // Only those who can react
      if (!Gdn::Session()->IsValid()) return;
      if (!$this->Enabled) return;

      $ObjectModelName = ucfirst($Type).'Model';
      $ObjectModel = new $ObjectModelName();
      $Object = (array)$ObjectModel->GetID($ID, DATASET_TYPE_ARRAY);
      $DiscussionID = GetValue('DiscussionID', $Object);

      // Don't show custom reactions for user viewing his own post
      $User = (array)Gdn::Session()->User;
      $UserID = $User['UserID'];

      $AuthorID = $Object['InsertUserID'];
      $Author = Gdn::UserModel()->GetID($AuthorID, DATASET_TYPE_ARRAY);
      if ($AuthorID == Gdn::Session()->UserID) return;

      // Determine state

      // ...for viewing user
      $UserKidnapper = $this->IsKidnapper($UserID);
      $UserInformant = $this->IsInformant($UserID);
      $UserCanKidnap = ($UserKidnapper) ? $this->CanKidnap($UserID) : false;
      $UserKidnapped = (!$UserKidnapper) ? $this->IsKidnapped($UserID) : false;
      // Victims cannot do anything special
      if ($UserKidnapped) return;

      // ...for author
      $AuthorKidnapper = $this->IsKidnapper($AuthorID);
      $AuthorCanKidnap = ($AuthorKidnapper) ? $this->CanKidnap($AuthorID) : false;
      $AuthorKidnapped = (!$AuthorKidnapper) ? $this->IsKidnapped($AuthorID) : false;
      // Kidnappers are immune to specials
      if ($AuthorKidnapper) return;

      switch ($Action) {
         case 'kidnapper':
            $Sender->Permission('Garden.Moderation.Manage');
            $this->Kidnapper($AuthorID, $UserID);
            $Sender->InformMessage(sprintf(T('<b>%s</b> is now a kidnapper!'), $Author['Name']));
            $Sender->Render('blank', 'utility', 'dashboard');
            break;

         case 'nonkidnapper':
            $Sender->Permission('Garden.Moderation.Manage');
            $this->Normalize($AuthorID);
            $Sender->InformMessage(sprintf(T('<b>%s</b> is no longer a kidnapper!'), $Author['Name']));
            $Sender->Render('blank', 'utility', 'dashboard');
            break;

         case 'kidnap':
            if ($UserCanKidnap && !$AuthorKidnapped && !$Author['Admin']) {
               $this->Kidnap($AuthorID, $UserID, $DiscussionID);
               $Sender->InformMessage(sprintf(T('<b>%s</b> has been kidnapped!'), $Author['Name']));
            }
            $Sender->Render('blank', 'utility', 'dashboard');
            break;

         // Rescue attempt
         case 'rescue':
            $Sender->Title("Attempt a daring rescue");
            $Sender->SetData('Victim', $Author);

            $HintID = GetValue('HintID', $AuthorKidnapped);
            $Hint = GetValue("hint-{$HintID}", $this->Hints);
            $Sender->SetData('Hint', $Hint);

            // Check answer
            if ($Sender->Form->AuthenticatedPostback() && ($AuthorKidnapped && (!$UserKidnapper || $UserInformant))) {
               $Guess = strtolower($Sender->Form->GetValue('Guess'));
               $Answer = strtolower(GetValue('Answer', $Hint));
               if ($Guess == $Answer) {
                  $this->Rescue($AuthorID, $UserID, $DiscussionID);
                  $Sender->InformMessage(sprintf(T('Rescued %s!'), $Author['Name']));
               } else {
                  $Sender->InformMessage(T('Nope!'));
               }
            }

            $Sender->Render('rescue', '', 'plugins/Kidnappers');
            break;
      }
   }

   /*
    *
    * ACTIONS
    *
    */

   /**
    * Kidnap this person
    *
    * @param integer $VictimID
    * @param integer $UserID
    */
   public function Kidnap($VictimID, $UserID, $DiscussionID = null) {

      $Victim = Gdn::UserModel()->GetID($VictimID, DATASET_TYPE_ARRAY);
      $User = Gdn::UserModel()->GetID($UserID, DATASET_TYPE_ARRAY);

      // Remove special statuses from user
      $this->Normalize($VictimID);

      // Modify kidnapper
      $Kidnapper = $this->IsKidnapper($UserID);
      TouchValue('Victims', $Kidnapper, 0);
      $Kidnapper['Victims']++;

      $IsInformant = GetValue('Informant', $Kidnapper, false);
      $CooldownTime = $IsInformant ? $this->KidnapCooldown * 2 : $this->KidnapCooldown;
      $Kidnapper['Cooldown'] = time() + $CooldownTime;
      $this->SetUserMeta($UserID, self::KIDNAPPER_KEY, json_encode($Kidnapper));

      // Create victim
      $HintKeys = array_keys($this->Hints);
      $Hints = sizeof($HintKeys);
      $HintNumber = mt_rand(0,$Hints - 1);
      $HintKey = $HintKeys[$HintNumber];
      $Hint = $this->Hints[$HintKey];
      $HintID = GetValue('ID', $Hint);

      $StockholmExpiry = time() + $this->KidnapExpiry;
      $VictimData = array(
         'Kidnapper' => $UserID,
         'Expiry'    => $StockholmExpiry,
         'HintID'    => $HintID,
         'Answer'    => $Hint['Answer']
      );
      $this->SetUserMeta($VictimID, self::KIDNAPPED_KEY, json_encode($VictimData));

      // Create stockholm timer
      $this->SetUserMeta($VictimID, self::STOCKHOLM_KEY, $StockholmExpiry);

      // Award kidnapped badge
      $BadgeName = "kidnapped";
      $Kidnapped = $this->BadgeModel->GetID($BadgeName);
      if (!$Kidnapped) {
         $this->Structure();
         $Kidnapped = $this->BadgeModel->GetID($BadgeName);
         if (!$Kidnapped) return;
      }
      $this->UserBadgeModel->Give($VictimID, $Kidnapped['BadgeID']);

      // Broadcast the kidnapping
      if (!is_null($DiscussionID) && $this->MinionAnnounce) {
         $KidnapMessage = <<<KIDNAP
{Victim.UserID,user} has been kidnapped by {User.UserID,user} and is being held for ransom! Solve the riddle to set em' free, or I'll hand em' over to Clamps!

<div class="KidnappersHint">{Hint.Clue}<br/><b>Which forumer am I?</b></div>
KIDNAP;
         $KidnapMessage = FormatString($KidnapMessage, array(
            'Victim'    => $Victim,
            'User'      => $User,
            'Hint'      => $Hint
         ));
         $this->Minion->message($Victim, $DiscussionID, $KidnapMessage);
      }

      // Notify
      $Activity = array(
         'ActivityUserID' => $UserID,
         'NotifyUserID' => $VictimID,
         'HeadlineFormat' => T("You've been kidnapped by {ActivityUserID,user}!"),
         'Data' => array(
            'Minion'       => $this->MinionUser
         )
      );
      $this->Activity($Activity);

   }

   /**
    * Turn this person into a kidnapper
    *
    * @param integer $VictimID
    * @param integer $UserID
    */
   public function Kidnapper($VictimID, $UserID = null) {

      // Remove special statuses from user
      $this->Normalize($VictimID);

      // Create kidnapper
      $KidnapperData = array(
         'Cooldown'  => 0,
         'Victims'   => 0
      );

      // Remember who 'made' this kidnapper
      if (!is_null($UserID))
         $KidnapperData['Initiator'] = $UserID;

      $this->SetUserMeta($VictimID, self::KIDNAPPER_KEY, json_encode($KidnapperData));

      $UserID = !is_null($UserID) ? $UserID : $this->MinionUser['UserID'];
      $KidnapCooldownMinutes = $this->KidnapCooldown / 60;
      $Activity = array(
         'ActivityUserID' => $UserID,
         'NotifyUserID' => $VictimID,
         'HeadlineFormat' => T("You've become a kidnapper, working for the infamous {Data.Minion.UserID,user}. Click 'Kidnap' on someone's post to kidnap them, but remember: you'll have to wait {Data.Cooldown} minutes to kidnap again!"),
         'Data' => array(
            'Minion'       => $this->MinionUser,
            'Cooldown'     => $KidnapCooldownMinutes
         )
      );
      $this->Activity($Activity);

   }

   /**
    * Turn this person into an informant
    *
    * @param integer $VictimID
    * @param integer $UserID
    */
   public function Informant($VictimID, $UserID = null) {

      $Kidnapper = $this->IsKidnapper($VictimID);
      if (!$Kidnapper) {
         // Remove special statuses from user
         $this->Normalize($VictimID);

         // Make this person a kidnapper first
         $this->Kidnapper($VictimID, $UserID);
         $Kidnapper = $this->IsKidnapper($VictimID);
      }

      // Create kidnapper
      $KidnapperData = array_merge($Kidnapper, array(
         'Informant' => true
      ));

      $this->SetUserMeta($VictimID, self::KIDNAPPER_KEY, json_encode($KidnapperData));

      // Award informant badge
      $BadgeName = "informant";
      $Informant = $this->BadgeModel->GetID($BadgeName);
      if (!$Informant) {
         $this->Structure();
         $Informant = $this->BadgeModel->GetID($BadgeName);
         if (!$Informant) return;
      }
      $this->UserBadgeModel->Give($VictimID, $Informant['BadgeID']);

      // Notify
      $UserID = !is_null($UserID) ? $UserID : $this->MinionUser['UserID'];
      $KidnapCooldownMinutes = ($this->KidnapCooldown * 2) / 60;
      $Activity = array(
         'ActivityUserID' => $UserID,
         'NotifyUserID' => $VictimID,
         'HeadlineFormat' => T("You've become an informant, working for the Robot Police to spy on the infamous {Data.Minion.UserID,user}. You can't kidnap as often, but you can now rescue."),
         'Data' => array(
            'Minion'       => $this->MinionUser,
            'Cooldown'     => $KidnapCooldownMinutes
         )
      );
      $this->Activity($Activity);

   }

   /**
    * Rescue this victim
    *
    * @param integer $VictimID
    * @param integer $UserID
    */
   public function Rescue($VictimID, $UserID, $DiscussionID = null) {

      $Victim = Gdn::UserModel()->GetID($VictimID, DATASET_TYPE_ARRAY);
      $User = Gdn::UserModel()->GetID($UserID, DATASET_TYPE_ARRAY);

      // Remove special statuses from user
      $this->Normalize($VictimID);

      // Award rescuer badge
      $BadgeName = "rescuer";
      $Rescuer = $this->BadgeModel->GetID($BadgeName);
      if (!$Rescuer) {
         $this->Structure();
         $Rescuer = $this->BadgeModel->GetID($BadgeName);
         if (!$Rescuer) return;
      }
      $this->UserBadgeModel->Give($UserID, $Rescuer['BadgeID']);

      // Broadcast the rescue
      if (!is_null($DiscussionID) && $this->MinionAnnounce) {
         $RescueMessage = <<<KIDNAP
{Victim.UserID,user} has been rescued by {User.UserID,user}.
KIDNAP;
         $RescueMessage = FormatString($RescueMessage, array(
            'Victim'    => $Victim,
            'User'      => $User
         ));
         $this->Minion->message($Victim, $DiscussionID, $RescueMessage);
      }

      // Notify
      $Activity = array(
         'ActivityUserID' => $UserID,
         'NotifyUserID' => $VictimID,
         'HeadlineFormat' => T("{ActivityUserID,user} mounted a daring rescue, saving you in the nick of time from the evil clutches of {Data.Minion.UserID,user}!"),
         'Data' => array(
            'Minion'         => $this->MinionUser
         )
      );
      $this->Activity($Activity);
   }

   /**
    *
    * @param integer $VictimID
    */
   public function Stockholm($VictimID) {

      // Award stockholm syndrome badge
      $BadgeName = "stockholm";
      $Stockholm = $this->BadgeModel->GetID($BadgeName);
      if (!$Stockholm) {
         $this->Structure();
         $Stockholm = $this->BadgeModel->GetID($BadgeName);
         if (!$Stockholm) return;
      }
      $this->UserBadgeModel->Give($VictimID, $Stockholm['BadgeID']);

      $Activity = array(
         'ActivityUserID' => $this->MinionUser['UserID'],
         'NotifyUserID' => $VictimID,
         'HeadlineFormat' => T("You weren't rescued fast enough and spent too long with {Data.Minion.UserID,user}. Developing an acute case of Stockholm Syndrome, you're now a kidnapper too!"),
         'Data' => array(
            'Minion'         => $this->MinionUser
         )
      );
      $this->Activity($Activity);

      // Create kidnapper
      $this->Kidnapper($VictimID);
   }

   /**
    * Remove all special statuses from user
    *
    * @param integer $VictimID
    */
   public function Normalize($VictimID) {
      // Remove kidnapper status
      $this->SetUserMeta($VictimID, self::KIDNAPPER_KEY);

      // Remove kidnapped status
      $this->SetUserMeta($VictimID, self::KIDNAPPED_KEY);

      // Remove stockholm syndrome timer
      $this->SetUserMeta($VictimID, self::STOCKHOLM_KEY);
   }

   /*
    *
    * REACTIONS
    *
    */

   /**
    * Add game reactions
    *
    * @param Controller $Sender
    */
   public function Base_AfterReactions_Handler($Sender) {

      // Only those who can react
      if (!Gdn::Session()->IsValid()) return;
      if (!$this->Enabled) return;

      $Object = FALSE;

      if (array_key_exists('Discussion', $Sender->EventArguments)) {
         $Object = (array)$Sender->EventArguments['Discussion'];
         $ObjectType = 'Discussion';
         $Discussion = $Object;
      }

      if (array_key_exists('Comment', $Sender->EventArguments)) {
         $Object = (array)$Sender->EventArguments['Comment'];
         $ObjectType = 'Comment';
         $DiscussionID = GetValue('DiscussionID', $Object);
         $DiscussionModel = new DiscussionModel();
         $Discussion = (array)$DiscussionModel->GetID($DiscussionID);
      }

      if ($Discussion['Closed']) return;
      if (!$Object) return;

      // Don't show custom reactions for user viewing his own post
      $User = (array)Gdn::Session()->User;
      $UserID = $User['UserID'];

      $Author = (array)$Sender->EventArguments['Author'];
      $AuthorID = $Author['UserID'];
      if ($AuthorID == Gdn::Session()->UserID) return;

      // Determine state

      // ...for viewing user
      $UserKidnapper = $this->IsKidnapper($UserID);
      $UserInformant = $this->IsInformant($UserID);
      $UserCanKidnap = ($UserKidnapper) ? $this->CanKidnap($UserID) : false;
      $UserKidnapped = (!$UserKidnapper) ? $this->IsKidnapped($UserID) : false;
      // Victims cannot do anything special
      if ($UserKidnapped) return;

      // ...for author
      $AuthorKidnapper = $this->IsKidnapper($AuthorID);
      $AuthorCanKidnap = ($AuthorKidnapper) ? $this->CanKidnap($AuthorID) : false;
      $AuthorKidnapped = (!$AuthorKidnapper) ? $this->IsKidnapped($AuthorID) : false;
      // Kidnappers are immune to specials
      if ($AuthorKidnapper) return;

      // Add buttons
      $Buttons = array();

      // Allow kidnapping innocents
      if ($UserCanKidnap && !$AuthorKidnapped && !$Author['Admin'])
         $Buttons[] = 'Kidnap';

      // Allow rescuing kidnappees
      if ($AuthorKidnapped && (!$UserKidnapper || $UserInformant))
         $Buttons[] = 'Rescue';

      // Moderators can create new kidnappers
      if (Gdn::Session()->CheckPermission('Garden.Moderation.Manage')) {
         if (!$AuthorKidnapped && !$Author['Admin']) {
            if ($AuthorKidnapper)
               $Buttons[] = 'NonKidnapper';
            else
               $Buttons[] = 'Kidnapper';
         }
      }

      if ($Buttons) $this->AddButtons($Buttons, $Object);
   }

   /**
    * Add game reaction buttons
    *
    * @param string $ButtonType
    * @param array $Object
    */
   public function AddButtons($ButtonTypes, $Object) {
      $ButtonTypes = (array)$ButtonTypes;
      echo Gdn_Theme::BulletItem('Kidnappers');
      echo '<span class="Kidnappers ReactMenu">';
         echo '<span class="ReactButtons">';

         foreach ($ButtonTypes as $ButtonType)
            echo $this->ActionButton($Object, $ButtonType);

         echo '</span>';
      echo '</span>';
   }

   /**
    * Prepare to render comments and discussions
    *
    * @param Controller $Sender
    */

   public function Base_BeforeCommentDisplay_Handler($Sender) {
      $this->RowStyle($Sender, $Sender->EventArguments['Author'], $Sender->EventArguments['Comment']);
   }

   public function Base_BeforeDiscussionDisplay_Handler($Sender) {
      $this->RowStyle($Sender, $Sender->EventArguments['Author'], $Sender->EventArguments['Discussion']);
   }

   /**
    * Perform checks on object to determine rendering
    *
    * Adds hint to comments and discussions when needed. Also scrambles
    * body text.
    *
    * @param Controller $Sender
    * @param array $Author
    * @param array $Object
    */
   public function RowStyle($Sender, &$Author, &$Object) {

      // Get user info
      $User = (array)Gdn::Session()->User;
      $UserID = $User['UserID'];
      $Author = $Author;
      $AuthorID = GetValue('UserID', $Author);

      // Determine state

      // ...for viewing user
      $UserKidnapper = $this->IsKidnapper($UserID);
      $UserKidnapped = (!$UserKidnapper) ? $this->IsKidnapped($UserID) : false;
      // Victims don't see scrambled text or hints
      if ($UserKidnapped) return;

      // ...for author
      $AuthorKidnapper = $this->IsKidnapper($AuthorID);
      $AuthorInformant = $this->IsInformant($AuthorID);
      $AuthorKidnapped = (!$AuthorKidnapper) ? $this->IsKidnapped($AuthorID) : false;

      // Author is a kidnapper
      if ($AuthorKidnapper && !$AuthorInformant) {
         $Sender->EventArguments['CssClass'] .= ' Kidnapper';
      }

      if ($AuthorKidnapper && $AuthorInformant) {
         $Sender->EventArguments['CssClass'] .= ' Informant';
      }

      // Author was kidnapped
      if ($AuthorKidnapped) {
         if ($AuthorID == Gdn::Session()->UserID) return;

         $Sender->EventArguments['CssClass'] .= ' Kidnapped';

         $HintID = $AuthorKidnapped['HintID'];
         $Hint = $this->Hints["hint-{$HintID}"];

         $Body = GetValue('Body', $Object);
         $Paragraphs = explode("\n", $Body);
         $NewBody = '';
         foreach ($Paragraphs as $Paragraph) {
            // Empty Lines
            if (!trim($Paragraph)) {
               $NewBody .= $Paragraph;
               continue;
            }

            $Sentences = explode('.', $Paragraph);
            foreach ($Sentences as $Sentence) {
               $NewSentence = array();
               $Words = explode(' ', $Sentence);
               foreach ($Words as $Word) {
                  $WordLength = strlen($Word);
                  if (!$WordLength) continue;

                  $NewWord = BetterRandomString($WordLength, 'a');
                  $NewSentence[] = $NewWord;
               }
               $NewSentence = implode(' ', $NewSentence);
               $NewBody .= "{$NewSentence}. ";
            }

            $NewBody .= "\n";
         }

         $NewBody = "<p><em>Faint muffled sounds reach your ears...</em></p><p class=\"KidnappersMuffled\">{$NewBody}</p>";
         $NewBody .= FormatString('<div>{Minion.UserID,user} says:</div><div class="KidnappersHint">{Hint.Clue}<br/><b>Which forumer am I?</b></div>Hurry, or {Author.UserID,user} might develop some kind of... Stockholm Syndrome!', array(
            'Minion' => $this->MinionUser,
            'Author' => $Author,
            'Hint'   => $Hint
         ));

         SetValue('Format', $Object, 'Markdown');
         SetValue('Body', $Object, $NewBody);
      }
   }

   /**
    *
    *
    * @staticvar array $Types
    * @param type $Row
    * @param type $UrlCode
    * @param type $Options
    * @return string
    */
   public function ActionButton($Row, $UrlCode, $Options = array()) {
      $ReactionType = ReactionModel::ReactionTypes($UrlCode);

      $IsHeading = FALSE;
      if (!$ReactionType) {
         $ReactionType = array('UrlCode' => $UrlCode, 'Name' => $UrlCode);
         $IsHeading = TRUE;
      }

      if ($Permission = GetValue('Permission', $ReactionType)) {
         if (!Gdn::Session()->CheckPermission($Permission))
            return '';
      }

      $Name = $ReactionType['Name'];
      $Label = T($Name);
      $SpriteClass = GetValue('SpriteClass', $ReactionType, "React$UrlCode");

      if ($ID = GetValue('CommentID', $Row)) {
         $RecordType = 'comment';
      } elseif ($ID = GetValue('ActivityID', $Row)) {
         $RecordType = 'activity';
      } else {
         $RecordType = 'discussion';
         $ID = GetValue('DiscussionID', $Row);
      }

      if ($IsHeading) {
         static $Types = array();
         if (!isset($Types[$UrlCode]))
            $Types[$UrlCode] = ReactionModel::GetReactionTypes(array('Class' => $UrlCode, 'Active' => 1));

         $Count = ReactionCount($Row, $Types[$UrlCode]);
      } else {
         if ($RecordType == 'activity')
            $Count = GetValueR("Data.React.$UrlCode", $Row, 0);
         else
            $Count = GetValueR("Attributes.React.$UrlCode", $Row, 0);
      }
      $CountHtml = '';
      $LinkClass = "ReactButton-$UrlCode";
      if ($Count) {
         $CountHtml = ' <span class="Count">'.$Count.'</span>';
         $LinkClass .= ' HasCount';
      }
      $LinkClass = ConcatSep(' ', $LinkClass, GetValue('LinkClass', $Options));

      $UrlClassType = 'Hijack';
      $UrlCodeLower = strtolower($UrlCode);
      if ($IsHeading)
         $Url = '';
      else
         $Url = Url("/react/$RecordType/$UrlCodeLower?id=$ID");

      $CustomType = GetValue('CustomType', $ReactionType, false);
      switch ($CustomType) {
         case 'url':
            $Url = GetValue('Url', $ReactionType)."?type={$RecordType}&id={$ID}";
            $UrlClassType = GetValue('UrlType', $ReactionType, 'Hijack');
            break;
      }

      $Result = <<<EOT
   <a class="{$UrlClassType} ReactButton {$LinkClass}" href="{$Url}" title="{$Label}" rel="nofollow"><span class="ReactSprite {$SpriteClass}"></span> {$CountHtml}<span class="ReactLabel">{$Label}</span></a>
EOT;

      return $Result;
   }

   /**
    * Run time based actions
    *
    *  - Expiry check
    *  - Random arrow deployment
    *
    * @param Gdn_Statistics $Sender
    */
   public function Gdn_Statistics_AnalyticsTick_Handler($Sender) {
      if (!$this->Enabled) return;

      // Expiry check
      $NextCheckTime = Gdn::Cache()->Get(self::EXPIRY_CHECK_CACHE);
      if (!$NextCheckTime || $NextCheckTime < microtime(true)) {
         Gdn::Cache()->Store(self::EXPIRY_CHECK_CACHE, microtime(true)+60);

         // Run stockholm check
         $StockholmMetaKey = $this->MakeMetaKey(self::STOCKHOLM_KEY);
         $StockholmUsers = Gdn::SQL()
            ->Select('*')
            ->From('UserMeta')
            ->Where('Name', $StockholmMetaKey)
            ->Where('Value <', time())
            ->Get()->ResultArray();

         foreach ($StockholmUsers as $Victim) {
            $VictimID = $Victim['UserID'];
            $this->Stockholm($VictimID);
         }

         // Run informant conversion
         $FreeSomeone = false;
         $FreeSomeoneChance = mt_rand(0,100);
         $Chance = 100 - $this->InformantChance;
         if ($FreeSomeoneChance > $Chance)
            $FreeSomeone = true;

         if ($FreeSomeone) {
            $KidnapperMetaKey = $this->MakeMetaKey(self::KIDNAPPER_KEY);
            $NumKidnappers =  Gdn::SQL()
               ->Select('UserID')
               ->From('UserMeta')
               ->Where('Name', $KidnapperMetaKey)
               ->NotLike('Value', '%Informant%')
               ->NotLike('Value', '%Victims":0%')
               ->Get()->NumRows();

            $Items = 2;
            $Pages = floor($NumKidnappers / $Items);
            $Page = mt_rand(0,$Pages);
            $KidnapperUsers = Gdn::SQL()
               ->Select('*')
               ->From('UserMeta')
               ->Where('Name', $KidnapperMetaKey)
               ->NotLike('Value', '%Informant%')
               ->NotLike('Value', '%Victims":0%')
               ->Limit($Items)
               ->Offset($Page)
               ->Get()->ResultArray();

            // Make some informants!
            foreach ($KidnapperUsers as $KidnapperUser) {
               $KidnapperUserID = GetValue('UserID', $KidnapperUser);
               $Kidnapper = @json_decode(GetValue('Value', $KidnapperUser), true);
               if (!$Kidnapper) continue;
               $IsInformant = (bool)GetValue('Informant', $Kidnapper, false);
               if ($IsInformant) continue;

               $this->Informant($KidnapperUserID, $this->MinionUser['UserID']);
            }
         }
      }
   }

   /**
    * Create an activity with defaults
    *
    * @param array $Activity
    */
   protected function Activity($Activity) {
      $Activity = array_merge(array(
         'ActivityType'    => 'Kidnappers',
         'Force'           => TRUE,
         'Notified'        => ActivityModel::SENT_PENDING
      ), $Activity);
      $this->ActivityModel->Save($Activity);
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

         // Kidnapper
         $this->ReactionModel->DefineReactionType(array(
            'UrlCode' => 'Kidnapper',
            'Name' => 'Make Kidnapper',
            'Sort' => 0,
            'Class' => 'Good',
            'Hidden' => 1,
            'Description' => "Draft this person to the kidnappers squad.",
            'Custom' => 1,
            'CustomType' => 'url',
            'Url' => '/kidnappers/action/kidnapper'
         ));

         // Kidnapper
         $this->ReactionModel->DefineReactionType(array(
            'UrlCode' => 'NonKidnapper',
            'Name' => 'Non Kidnapper',
            'Sort' => 0,
            'Class' => 'Good',
            'Hidden' => 1,
            'Description' => "Remove this person from the kidnappers squad.",
            'Custom' => 1,
            'CustomType' => 'url',
            'Url' => '/kidnappers/action/nonkidnapper'
         ));

         // Kidnap
         $this->ReactionModel->DefineReactionType(array(
            'UrlCode' => 'Kidnap',
            'Name' => 'Kidnap',
            'Sort' => 0,
            'Class' => 'Good',
            'Hidden' => 1,
            'Description' => "Kidnap this person.",
            'Custom' => 1,
            'CustomType' => 'url',
            'Url' => '/kidnappers/action/kidnap'
         ));

         // Rescue
         $this->ReactionModel->DefineReactionType(array(
            'UrlCode' => 'Rescue',
            'Name' => 'Rescue',
            'Sort' => 0,
            'Class' => 'Good',
            'Hidden' => 1,
            'Description' => "Attempt to rescue this person.",
            'Custom' => 1,
            'CustomType' => 'url',
            'Url' => '/kidnappers/action/rescue',
            'UrlType' => 'Popup'
         ));

      }

      // Define Game badges
      Gdn::Structure()->Reset();

      // Kidnapped
      $this->BadgeModel->Define(array(
         'Name' => "Kidnapped",
         'Slug' => "kidnapped",
         'Type' => 'Manual',
         'Body' => "You've been kidnapped!",
         'Photo' => "http://badges.vni.la/100/kidnapped.png",
         'Points' => 10,
         'Class' => 'Kidnappers',
         'Level' => 1,
         'CanDelete' => 0
      ));

      // Rescuer
      $this->BadgeModel->Define(array(
         'Name' => 'Rescuer',
         'Slug' => 'rescuer',
         'Type' => 'Manual',
         'Body' => "You correctly solved the riddle and rescued a fellow forumer.",
         'Photo' => 'http://badges.vni.la/100/rescuer.png',
         'Points' => 50,
         'Class' => 'Kidnappers',
         'Level' => 1,
         'CanDelete' => 0
      ));

      // Stockholm Syndrome
      $this->BadgeModel->Define(array(
         'Name' => 'Stockholm Syndrome',
         'Slug' => 'stockholm',
         'Type' => 'Manual',
         'Body' => "You spent too much time with your captors, so now you're one of them!",
         'Photo' => 'http://badges.vni.la/100/stockholm.png',
         'Points' => 10,
         'Class' => 'Kidnappers',
         'Level' => 1,
         'CanDelete' => 0
      ));

      // Informant
      $this->BadgeModel->Define(array(
         'Name' => 'Informant',
         'Slug' => 'informant',
         'Type' => 'Manual',
         'Body' => "The Robot Police have turned you! You're now an infiltrator.",
         'Photo' => 'http://badges.vni.la/100/informant.png',
         'Points' => 10,
         'Class' => 'Kidnappers',
         'Level' => 1,
         'CanDelete' => 0
      ));

      $this->ActivityModel->DefineType('Kidnappers', array(
         'Notify'    => 1,
         'Public'    => 0
      ));

   }

}