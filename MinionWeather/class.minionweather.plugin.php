<?php

/**
 * @copyright 2003 Vanilla Forums, Inc
 * @license Proprietary
 */

$PluginInfo['MinionWeather'] = array(
   'Name' => 'Minion: Weather',
   'Description' => "Add minion weather hooks and commands.",
   'Version' => '1.0',
   'RequiredApplications' => array(
      'Vanilla' => '2.1a'
    ),
   'RequiredPlugins' => array(
      'Minion' => '1.14'
   ),
   'MobileFriendly' => TRUE,
   'Author' => "Tim Gunter",
   'AuthorEmail' => 'tim@vanillaforums.com',
   'AuthorUrl' => 'http://vanillaforums.com'
);

/**
 * Minion Weather Plugin
 *
 * Weather command hooks.
 *
 * Changes:
 *  1.0     Release
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package minion
 */
class MinionWeatherPlugin extends Gdn_Plugin {



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

      if (!$state['Method'] && in_array($state['CompareToken'], array('weather')))
         $sender->consume($state, 'Method', 'weather');

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
         case 'weather':

            $actions[] = array('weather', 'Garden.SignIn.Allow', $state);
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

         case 'hunt':

            if (!array_key_exists('User', $state['Targets']))
               return;

            $discussion = $state['Targets']['Discussion'];


            break;
      }
   }

}