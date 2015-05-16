<?php
/**
 * @copyright Copyright (c) 2015 Jan Waś <janek.jan@gmail.com>
 * @license BSD
 */

namespace nineinchnic\audit\console;

use yii\console\Controller;

/**
 * Allows you to manager audit database objects like tables and triggers.
 *
 * Usage:
 *
 * 1. Attach the Trackable behavior to models.
 * 2. Run the 'install' action:
 *
 *    yii audit --modelClass=app\models\SomeModel
 *
 * @author Jan Waś <janek.jan@gmail.com>
 */
class AuditController extends Controller
{
    /**
     * @var string controller default action ID.
     */
    public $defaultAction = 'verify';
    /**
     * @var string the directory storing the model classes. This can be either
     * a path alias or a directory.
     */
    public $modelPath = '@app/models';
    /**
     * @var string the fully qualified model class name.
     */
    public $modelClass;
    /**
     * @var Connection|array|string the DB connection object, object configuration array
     * or the application component ID of the DB connection to use
     * when managing audits.
     */
    public $db = 'db';

    /**
     * @inheritdoc
     */
    public function options($actionID)
    {
        return array_merge(
            parent::options($actionID),
            ['modelPath', 'modelClass'], // global for all actions
        );
    }

    /**
     * Verifies audit database objects for specified models.
     *
     * @return integer the status of the action execution. 0 means normal, other values mean abnormal.
     */
    public function actionVerify()
    {
        $models = $this->getModels();
        if (empty($models)) {
            $this->stdout("No traceable models found. Attach the Traceable behavior to a model.\n", Console::FG_GREEN);

            return self::EXIT_CODE_NORMAL;
        }

        $this->stdout("Differences found. Run the 'install' action to update your database schema.\n", Console::FG_RED);

        return self::EXIT_CODE_ERROR;
    }
}
