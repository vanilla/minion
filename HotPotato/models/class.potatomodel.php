<?php

/**
 * @copyright 2003 Vanilla Forums, Inc
 * @license Proprietary
 */

/**
 * Potato model
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @since 1.0.0
 * @package minion
 * @subpackage hotpotato
 */
class PotatoModel extends Gdn_Model {

    public function __construct() {
        parent::__construct('Potato');
    }

    /**
     * Get a potato by hash
     *
     * @param string $hash
     * @param boolean $active optional. defaults to only retrieve active potatos.
     * @return type
     */
    public function getHash($hash, $active = true) {
        $query = [
            'Hash' => $hash
        ];
        if ($active) {
            $query['Status'] = 'active';
        }

        $potato = $this->getWhere($query)->firstRow(DATASET_TYPE_ARRAY);

        return $potato ? $potato : false;
    }

}
