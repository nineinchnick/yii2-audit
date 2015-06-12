<?php
/**
 * Active form to manage display audits
 * @author <pradziszewski@netis.pl>
 */
namespace nineinchnick\audit\models;

use yii\base\Model;
use Yii;

class AuditForm extends Model
{

    public $table;

    public function rules()
    {
        return array_merge(parent::rules(), [
            ['table', 'required'],
            ['table', 'checkTableExists']
        ]);
    }

    /**
     * Selects all table from 'audits' schema
     * 
     * @return array $map
     */
    public function getAuditTables()
    {
        $connection = Yii::$app->db;
        $schema     = $connection->schema;
        $tables     = $schema->getTableNames('audits');
        $map        = [];
        foreach ($tables as $table) {
            $map[$table] = $table;
        }
        return $map;
    }

    /**
     * Check if given table exists in audits schema
     * 
     * @return boolean
     */
    public function checkTableExists()
    {
        if (!in_array($this->table, $this->getAuditTables())) {
            $this->addError('table', Yii::t('app', 'You entered an invalid table name.'));
            return false;
        }
        return true;
    }

}
