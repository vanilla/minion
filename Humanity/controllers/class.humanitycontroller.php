<?php if (!defined('APPLICATION')) exit();
 
/**
 * Cards Against Humanity controller
 * 
 * @since 1.0.0
 * @package misc
 */
class HumanityController extends VanillaController {
   
   public function Initialize() {
      parent::Initialize();
      $this->Form = new Gdn_Form;
      $this->Application = 'vanilla';
   }
   
}