<?php
/**
 * @copyright Copyright (c) 2015 Jan Waś <janek.jan@gmail.com>
 * @license BSD
 */

namespace nineinchnick\audit\components;

use yii\base\Object;

class AuditManager extends Object
{
    public function __construct($config = [])
    {
        // ... initialization before configuration is applied

        parent::__construct($config);
    }

    public function init()
    {
        parent::init();

        // ... initialization after configuration is applied
    }
    /*
     *
        $schemaTemplate = $this->auditManager->schemaTemplate('audits');
        if (!$schemaTemplate['exists'])
            echo 'Missing db schema: audits\n';
        $operationTemplate = $this->auditManager->operationTemplate('audits');
        if (!$operationTemplate['exists'])
            echo 'Missing db operator: operator\n';
        // check schema and operator
     */
}