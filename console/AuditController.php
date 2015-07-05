<?php
/**
 * @copyright Copyright (c) 2015 Jan Waś <janek.jan@gmail.com>
 * @license BSD
 */

namespace nineinchnick\audit\console;

use nineinchnick\audit\components\AuditManager;
use yii\base\Exception;
use yii\console\Controller;
use yii\db\Connection;
use yii\di\Instance;
use yii\helpers\Console;
use yii\helpers\FileHelper;

/**
 * Allows you to manager audit database objects like tables and triggers.
 *
 * Usage:
 *
 * 1. Attach the TrackableBehavior to models.
 * 2. Run the 'migration' action:
 *
 *    yii audit/migration --modelName=app\models\SomeModel
 *
 * You can later verify that the audit objects do not require updating by running the 'report' action.
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
     * @var string the directory storing the migration classes. This can be either
     * a path alias or a directory.
     */
    public $migrationPath = '@app/migrations';
    /**
     * @var string the template file for generating new migrations.
     * This can be either a path alias (e.g. "@app/migrations/template.php")
     * or a file path.
     */
    public $templateFile;
    /**
     * @var Connection|array|string the DB connection object, object configuration array
     * or the application component ID of the DB connection to use
     * when managing audits.
     */
    public $db = 'db';
    /**
     * @var AuditManager cached AuditManager component
     */
    protected $auditManager;

    protected function getAuditManager()
    {
        if ($this->auditManager === null) {
            $this->auditManager = \Yii::createObject([
                'class' => 'nineinchnick\audit\components\AuditManager',
                'db' => $this->db,
            ]);
        }
        return $this->auditManager;
    }

    /**
     * @inheritdoc
     */
    public function options($actionID)
    {
        return array_merge(
            parent::options($actionID),
            ['modelPath', 'modelClass', 'migrationPath'], // global for all actions
            ($actionID == 'install') ? ['templateFile'] : [] // action create
        );
    }

    /**
     * This method is invoked right before an action is to be executed (after all possible filters.)
     * It checks the existence of the [[migrationPath]].
     * @param \yii\base\Action $action the action to be executed.
     * @throws Exception if directory specified in migrationPath doesn't exist and action isn't "create".
     * @return boolean whether the action should continue to be executed.
     */
    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->db = Instance::ensure($this->db, Connection::className());
        if ($action->id !== 'migration') {
            return true;
        }
        $path = \Yii::getAlias($this->migrationPath);
        if (!is_dir($path)) {
            if ($action->id !== 'create') {
                throw new Exception('Migration failed. Directory specified in migrationPath doesn\'t exist.');
            }
            FileHelper::createDirectory($path);
        }
        $this->migrationPath = $path;
        return true;
    }

    /**
     * Displays a list of all models from every module and its audits status.
     * @return int
     */
    public function actionReport()
    {
        $hasErrors = false;
        foreach ($this->getAuditManager()->getReport() as $modelName => $result) {
            if (is_string($result) || !$result['enabled']) {
                $color = Console::FG_GREY;
            } elseif ($result['valid'] === false) {
                $hasErrors = false;
                $color = Console::FG_RED;
            } else {
                $color = Console::FG_GREEN;
            }
            $this->stdout('    '.str_pad(is_string($result) ? $result : $modelName.' ', 40, '.').' ', $color);
            if (is_array($result)) {
                $this->stdout($result['enabled'] ? '+' : '-', $color);
                $this->stdout($result['valid'] === false ? '!' : '', $color);
            }
            $this->stdout("\n");
        }
        return $hasErrors ? self::EXIT_CODE_ERROR : self::EXIT_CODE_NORMAL;
    }

    /**
     * @param string $modelName model class name
     * @param boolean $run      if false, prints SQL queries, if true, executes them instead
     * @return int
     */
    public function actionInstall($modelName, $run = false)
    {
        /** @var \yii\db\Command[] $commands */
        $commands = $this->getAuditManager()->getDbCommands($modelName, 'up');

        foreach ($commands as $command) {
            if (!$run) {
                $this->stdout($command->getSql().";\n");
            } else {
                $command->execute();
            }
        }
        return self::EXIT_CODE_NORMAL;
    }

    /**
     * @param string $modelName model class name
     * @param boolean $run      if false, prints SQL queries, if true, executes them instead
     * @return int
     */
    public function actionRemove($modelName, $run = false)
    {
        /** @var \yii\db\Command[] $commands */
        $commands = $this->getAuditManager()->getDbCommands($modelName, 'down');
        foreach ($commands as $command) {
            if (!$run) {
                $this->stdout($command->getSql().";\n");
            } else {
                $command->execute();
            }
        }
        return self::EXIT_CODE_NORMAL;
    }

    /**
     * Creates a migration file that install audit database objects.
     * @param $modelName string model class name
     * @return int
     * @throws Exception
     */
    public function actionMigration($modelName = null)
    {
        $queryTemplate = <<<EOD
        \$query = <<<SQL
{Query}
SQL;
        \$this->execute(\$query);
EOD;
        $queries = [
            'up' => [],
            'down' => [],
        ];
        /** @var \yii\db\Command $command */
        foreach ($this->getAuditManager()->getDbCommands($modelName, 'up') as $command) {
            $queries['up'][] = strtr($queryTemplate, ['{Query}' => $command->getSql()]);
        }
        foreach ($this->getAuditManager()->getDbCommands($modelName, 'down') as $command) {
            $queries['down'][] = strtr($queryTemplate, ['{Query}' => $command->getSql()]);
        }

        if (empty($queries['up']) && empty($queries['down'])) {
            $this->stdout('Warning: nothing to do, would create an empty migration.'."\n", Console::FG_YELLOW);
            return self::EXIT_CODE_ERROR;
        }


        $name = 'm'.gmdate('ymd_His').'_audit';
        if ($modelName !== null) {
            $parts = explode('\\', $modelName);
            $modelName = end($parts);
            $name .= '_' . $modelName;
        }
        $file = $this->migrationPath . DIRECTORY_SEPARATOR . $name . '.php';

        if ($this->confirm("Create new migration '$file'?")) {
            $content = $this->getTemplate($name, $queries);
            file_put_contents($file, $content);
            $this->stdout("New migration created successfully.\n", Console::FG_GREEN);
        }
        return self::EXIT_CODE_NORMAL;
    }

    /**
     * @param $name string migration class name
     * @param $queries array holding 'up' and 'down' keys with queries strings
     * @return string migration template
     */
    protected function getTemplate($name, $queries)
    {
        if ($this->templateFile !== null) {
            return $this->renderFile(\Yii::getAlias($this->templateFile), [
                'className'   => $name,
                'queriesUp'   => implode("\n", $queries['up']),
                'queriesDown' => implode("\n", $queries['down']),
            ]);
        }

        $template = <<<EOD
<?php

class {className} extends \yii\db\Migration
{
    public function safeUp()
    {
{queriesUp}
    }

    public function safeDown()
    {
{queriesDown}
    }
}
EOD;
        return strtr($template, [
            '{className}' => $name,
            '{queriesUp}' => implode("\n", $queries['up']),
            '{queriesDown}' => implode("\n", $queries['down']),
        ]);
    }
}
