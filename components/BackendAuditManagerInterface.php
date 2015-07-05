<?php
/**
 * @copyright Copyright (c) 2015 Jan Waś <janek.jan@gmail.com>
 * @license BSD
 */

namespace nineinchnick\audit\components;

use yii\db\ActiveRecord;

interface BackendAuditManagerInterface
{
    /**
     * Checks if common database objects exist and are valid.
     * @return string[] error messages, if any
     */
    public function checkGeneral();
    /**
     * Checks if audit objects for specified model exist and are valid.
     * @param ActiveRecord $model
     * @return array contains boolean keys: 'enabled' and 'valid'
     */
    public function checkModel(ActiveRecord $model);
    /**
     * Returns an array of commands required to either install or fix audit database objects.
     * @param ActiveRecord $model
     * @param string $direction
     * @return array
     */
    public function getDbCommands($model = null, $direction = 'up');
}
