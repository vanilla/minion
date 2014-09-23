<?php if (!defined('APPLICATION')) exit();

/**
 * Miniong Gaming Inventory
 * 
 * @author Tim Gunter <tim@vanillaforums.com>
 * @copyright 2003 Vanilla Forums, Inc
 * @license Proprietary
 * @package misc
 */

class Inventory {
   
   protected static $inventories;
   
   protected $id;
   protected $userid;
   protected $type;
   protected $context;
   protected $contextid;
   
   protected $inventory;
   protected $items;
   
   const INVENTORY_ID = 'inventory.{userid}.{context}';
   
   public function __construct($id = null, $userid = null, $type = null, $context = null, $contextid = null) {
      $this->id = $id;
      $this->userid = $userid;
      $this->type = $type;
      $this->context = $context;
      $this->contextid = $contextid;
   }
   
   /**
    * Get an inventory
    * 
    * @param type $user
    * @param type $context
    * @param type $contextid
    */
   public static function get($user, $context = null, $contextid = null) {
      $userid = GetValue('UserID', $user, $user);
      $type = $context ? 'contextual' : 'global';
      $id = Inventory::mkid($userid, $type, $context, $contextid);
      
      $inventory = new Inventory($id, $userid, $type, $context, $contextid);
   }
   
   /**
    * Link to the central inventory hash
    */
   public function link() {
      $id = $this->id;
      if (!$id) return false;
      
      $this->inventory = &Inventory::inventories($id);
      $this->items = &$this->inventory['items'];
      return true;
   }
   
   /**
    * Get inventories
    * 
    * @param string $id
    */
   public static function inventories($id) {
      if (!is_array(Inventory::$inventories))
         Inventory::$inventories = array();
      
      // Get existing inventory
      if (!array_key_exists($id, Inventory::$inventories)) {
         
         $inventory = false;
         // Load from cache
         if (!$inventory) {
            $inventory = Gdn::Cache()->Get($id);
         }

         // Load from db
         if (!$inventory) {
            $inventoryMeta = Gdn::UserMetaModel()->GetUserMeta($this->userid, $id);
            if ($inventoryMeta)
               $inventory = GetValue($id, $inventoryMeta, false);

            if ($inventory) {
               $inventory = json_decode($inventory);
               Gdn::Cache()->Store($id, $inventory);
            }
         }

         // New inventory, prepare it for items
         if (!$inventory || !is_array($inventory)) {
            $inventory = array(
               'id'        => $id,
               'mode'      => 'new',
               'items'     => array()
            );
         }
         
         Inventory::$inventories[$id] = $inventory;
      }
      
      // Return inventory from hash
      return Inventory::$inventories[$id];
   }

   /**
    * Get inventory id
    * 
    * @return type
    */
   public function id() {
      if (is_null($this->id))
         $this->id = Inventory::mkid ($this->userid, $this->type, $this->context, $this->contextid);
      return $this->id;
   }
   
   /**
    * 
    * @param type $userid
    */
   public function userid($userid = null) {
      return $this->userid;
   }
   
   /**
    * 
    * @return type
    */
   public function type() {
      return $this->type;
   }
   
   /**
    * 
    * @return type
    */
   public function context() {
      return $this->context;
   }
   
   /**
    * 
    * @return type
    */
   public function contextid() {
      return $this->contextid;
   }
   
   /**
    * Update inventory info
    * 
    * @param array $info
    */
   public function info($info = null) {
      if (!is_null($info))
         $this->inventory = array_merge($this->inventory, $info);
      
      return array_diff_key($this->inventory, array(
         'items'  => null
      ));
   }
   
   /**
    * Lazy load items
    * 
    * @param string $item optional. get this item
    */
   public function items($item = null) {
      if (is_null($this->items))
         $this->link();
      
      return array_key_exists($item, $this->items) ? $this->items[$item] : null;
   }
   
   /**
    * Check if inventory contains an item
    * 
    * Can also pass the second parameter to check if the inventory contains at
    * least $amount of the item.
    * 
    * @param string $item item code
    * @param integer $amount
    * @return type
    */
   public function hasItem($item, $amount = null) {
      if (!array_key_exists($item, $this->items())) return false;
      $itemData = $this->items($item);
      $amount = GetValue('amount', $itemData, 1);
      return (bool)$amount > 0;
   }
   
   /**
    * Get information about an item
    * 
    * @param string $item
    * @return array|bool item or false if not found
    */
   public function getItem($item) {
      if (!array_key_exists($item, $this->items())) return false;
      $itemData = $this->items($item);
      
      return array(
         'item'         => $item,
         'name'         => T("Item.{$item}.Name"),
         'description'  => T("Item.{$item}.Description"),
         'amount'       => GetValue('amount', $itemData, 1)
      );
   }
   
   /**
    * Add an item to the inventory
    * 
    * @param string $item item code
    * @param integer $amount optional. how many? default 1
    * @param string $type optional. item type
    * @param array $options optional. option meta data
    * @param array
    */
   public function addItem($item, $amount = 1, $type = null, $options = null) {
      $itemData = $this->items($item);
      if (!$itemData) {
         $options = (array)$options;
         $itemData = array(
            'item'         => $item,
            'type'         => $type,
            'name'         => GetValue('name', $options, T("Item.{$item}.Name")),
            'description'  => GetValue('description', $options, T("Item.{$item}.Description")),
            'amount'       => $amount,
            'options'      => $options
         );
      } else {
         $itemData = array_merge($itemData, array(
            'amount'       => ($itemData['amount'] + $amount)
         ));
         $itemData['amount'] += $amount;
         $itemData['options'] = array_merge($itemData['options'], $options);
      }
      
      // Add to/update in inventory
      $this->items[$item] = $itemData;
      $this->inventory['mode'] = 'modified';
      return $this->items[$item];
   }
   
   /**
    * Modify an item in the inventory
    * 
    * Directly set how much of a 
    * 
    * @param string $item item code
    * @param type $amount
    */
   public function modItem($item, $amount) {
      $itemData = $this->items($item);
      if (!$itemData) return false;
      
      $itemData['amount'] = $amount;
      $this->items[$item] = $itemData;
      $this->inventory['mode'] = 'modified';
   }
   
   /**
    * Remove an item from the inventory
    * 
    * @param string $item item code
    * @param integer $amount
    */
   public function removeItem($item, $amount = null) {
      $itemData = $this->items($item);
      if (!$itemData) return false;
      
      // Remove some amount of this item
      if ($amount) {
         
         $newAmount = $itemData['amount'] - $amount;
         if ($newAmount > 0) {
            $itemData['amount'] = $newAmount;
            $this->items[$item] = $itemData;
         } else
            unset($this->items[$item]);
         
      // Remove whole item
      } else {
         unset($this->items[$item]);
      }
      $this->inventory['mode'] = 'modified';
   }
   
   /**
    * Delete this inventory
    * 
    */
   public function delete() {
      $id = $this->id();
      if (!$id) return;
      
      Inventory::deleteInventory($this->userid, $id);
   }
   
   /**
    * Delete an artbitrary inventory
    * 
    * @param type $userid optional. 
    * @param type $context optional. 
    * @param type $contextid optional. 
    */
   public static function deleteInventory($userid, $id) {
      // Delete from DB
      Gdn::UserMetaModel()->SetUserMeta($userid, $id, null);
      
      // Delete from cache
      Gdn::Cache()->Remove($id);
      
      // Delete from share
      unset(Inventory::$inventories[$id]);
   }
   
   /**
    * Make an inventory id
    * 
    * @param integer $userid     user id
    * @param string $type        type of inventory, global or contextual
    * @param string $context if contextual, context type
    * @param integer $contextid  
    */
   public static function mkid($userid, $type, $context = null, $contextid = null) {
      if (is_null($userid) || is_null($type)) return false;
      $combinedContext = $type == 'global' ? 'global' : "{$type}.{$context}.{$contextid}";
      return FormatString(self::INVENTORY_ID, array(
         'userid'       => $userid,
         'context'      => $combinedContext
      ));
   }
   
   /**
    * Save modifications
    * 
    * @return void
    */
   public function __destruct() {
      $id = $this->id();
      if (!$id) return;
      
      // Do we need to save?
      $mode = GetValue('mode', $this->inventory, false);
      if ($mode)
         unset($this->inventory['mode']);
      
      if (in_array($mode, array('new', 'modified'))) {
         
         // Add base data
         if ($mode == 'new') {
            $this->inventory = array_merge($this->inventory, array(
               'id'        => $id,
               'userid'    => $this->userid,
               'type'      => $this->type,
               'context'   => $this->context,
               'contextid' => $this->contextid
            ));
         }
         
         // Save
         Gdn::Cache()->Store($id, $this->inventory);
         $inventory = json_encode($this->inventory);
         Gdn::UserMetaModel()->SetUserMeta($this->userid, $id, $inventory);
         
      }
   }
   
}