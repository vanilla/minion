<?php

/**
 * @copyright 2003 Vanilla Forums, Inc
 * @license Proprietary
 */

$PluginInfo['Kidnappers'] = array(
   'Name'            => 'Minion: Zombies',
   'Description'     => 'Zombies game and badges.',
   'Version'         => '1.0',
   'SettingsUrl'     => '',
   'MobileFriendly'  => true,
   'Author'          => 'Tim Gunter',
   'AuthorEmail'     => 'tim@vanillaforums.com',
   'AuthorUrl'       => 'http://vanillaforums.com',
   'RequiredApplications' => array(
      'Vanilla' => '2.1a',
      'Reputation' => '1.0'
    ),
   'RequiredPlugins' => array(
      'Minion' => '1.12',
      'Reactions' => '1.2.1'
   )
);

/**
 * Zombies Plugin
 *
 * This plugin uses Minion, Reactions, and Badges to create a zombies game.
 *
 * THE GAME
 *
 *
 *
 *
 * Changes:
 *  1.0     Release
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package minion
 */
class ZombiesPlugin extends Gdn_Plugin {

   /* MODELS / OBJECT REFERENCES */

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

   /* GAME RULES PROPERTIES */

   protected $ChaseTime;
   protected $ChaseRange;
   protected $ChaseCooldown;
   protected $ProtectTime;
   protected $ProtectCooldown;

   /* KEYS */

   const ZOMBIE_KEY = "Zombie";

   /**
    * Startup configuration
    *
    */
   public function __construct() {
      parent::__construct();

      $this->ReactionModel = new ReactionModel();
      $this->BadgeModel = new BadgeModel();
      $this->UserBadgeModel = new UserBadgeModel();
      $this->ActivityModel = new ActivityModel();

      $this->ChaseTime = C('Plugins.Zombies.ChaseTime', 600);  // 10 minutes
      $this->ChaseRangeTime = C('Plugins.Zombies.ChaseRange', 10);  // 10 comments
      $this->ChaseCooldown = C('Plugins.Zombies.ChaseCooldown', 20);  // 20 minutes
      $this->ProtectTime = C('Plugins.Zombies.ProtectTime', 600);  // 10 minutes

      $this->Zombies = array();
   }

   /**
    * Hook into minion startup
    *
    * @param MinionPlugin $Sender
    */
   public function MinionPlugin_Start_Handler($Sender) {

      // Register persona
      $Sender->persona('Zombies', array(
         'Name'      => 'Red Queen',
         'Photo'     => 'https://images.v-cdn.net/minion/redqueen.png',
         'Title'     => 'Malevolent AI',
         'Location'  => 'The Hive'
      ));

      // Change persona
      $Sender->persona('Zombies');
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
      $Sender->AddCssFile('zombies.css', 'plugins/Zombies');
   }

   /**
    * Check if this person is a zombie
    *
    * @param integer $UserID
    * @return boolean
    */
   public function IsZombie($UserID) {
      $Zombie = GetValue($UserID, $this->Zombies, null);

      // Known non zombie
      if ($Zombie === FALSE) {
         return false;

      // Unknown, query
      } elseif (!$Zombie) {
         $IsZombie = $this->GetUserMeta($UserID, self::ZOMBIE_KEY, false, true);
         if ($IsZombie) {
            $IsZombie = @json_decode($IsZombie, true);
         }
         $this->Zombies[$UserID] = $IsZombie;
         return $this->Zombies[$UserID];
      }
      return $Zombie;
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
   public function CanChase($UserID, $ObjectID, $ObjectType) {
      $Zombie = $this->IsZombie($UserID);
      if (!$Zombie) return false;

      $Cooldown = GetValue('Cooldown', $Zombie);
      if ($Cooldown > time()) return false;
      return true;
   }

}