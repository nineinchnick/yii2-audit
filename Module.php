<?php

namespace nineinchnick\audit;

class Module extends \yii\base\Module
{

    public $auditColumns = ['operation', 'operation_date', 'audit_id'];
    public $tables = [];

    public function init()
    {
        parent::init();
    }

}