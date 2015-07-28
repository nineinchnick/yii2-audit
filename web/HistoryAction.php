<?php
/**
 * @copyright Copyright (c) 2015 Jan Waś <janek.jan@gmail.com>
 * @license BSD
 */

namespace nineinchnick\audit\web;

use nineinchnick\audit\models\Action;
use nineinchnick\audit\models\ActionSearch;
use yii\base\Exception;
use yii\base\Module;
use yii\data\ActiveDataProvider;
use yii\data\DataProviderInterface;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use hanneskod\classtools\Iterator\ClassIterator;
use Symfony\Component\Finder\Finder;


class HistoryAction extends \yii\rest\Action
{
    const CACHE_KEY = 'history.tablesMap';

    /**
     * @var callable a PHP callable that will be called to prepare a data provider that
     * should return a collection of the models. If not set, [[prepareDataProvider()]] will be used instead.
     * The signature of the callable should be:
     *
     * ```php
     * function ($action) {
     *     // $action is the action object currently running
     * }
     * ```
     *
     * The callable should return an instance of [[ActiveDataProvider]].
     */
    public $prepareDataProvider;
    /**
     * @var string view name to display model change history
     */
    public $viewName = 'history';
    /**
     * @var int how long the tables map will be cached; set to null to disable
     */
    public $cacheDuration = 3600;

    /**
     * @return ActiveDataProvider
     */
    public function run($id = null)
    {
        $model = $id === null ? null : $this->findModel($id);
        if ($this->checkAccess) {
            call_user_func($this->checkAccess, $this->id, $model);
        }
        $searchModel = new ActionSearch();
        if ($searchModel->load(\Yii::$app->request->getQueryParams())) {
            $searchModel->validate();
        }

        $dataProvider = $this->prepareDataProvider($model, $searchModel);

        if (\Yii::$app->response->format === \yii\web\Response::FORMAT_HTML) {
            return $this->controller->render($this->viewName, [
                'model' => $model,
                'searchModel' => $searchModel,
                'dataProvider' => $dataProvider,
            ]);
        }
        return $dataProvider;
    }

    private function normalizeTablesMap($tablesMap)
    {
        $result = [];
        // filter table names through sql to properly remove unnecessary quoting
        $stringTablesMap = implode(', ', array_map(function ($t) {
            return "'$t'";
        }, array_keys($tablesMap)));
        $query = "SELECT t::regclass AS raw, t AS original FROM unnest(ARRAY[$stringTablesMap]) AS t";
        foreach (\Yii::$app->db->createCommand($query)->queryAll() as $row) {
            $result[$row['raw']] = $tablesMap[$row['original']];
        }
        return $result;
    }

    private function getTablesMap()
    {
        /** @var $modelClass \yii\db\ActiveRecord */
        $modelClass = $this->modelClass;
        /** @var \yii\db\ActiveRecord $staticModel */
        $staticModel = new $modelClass;

        if ($staticModel instanceof \netis\utils\crud\ActiveRecord) {
            $relationNames = $staticModel->relations();
        } else {
            $relationNames = $this->getRelationNames($modelClass);
        }
        $tablesMap = [
            $modelClass::getDb()->quoteSql($modelClass::tableName()) => $modelClass,
        ];
        foreach ($relationNames as $relationName) {
            /** @var \yii\db\ActiveQuery $relation */
            $relation = $staticModel->getRelation($relationName);
            /** @var \yii\db\ActiveRecord $relationClass */
            $relationClass = $relation->modelClass;
            $tablesMap[$modelClass::getDb()->quoteSql($relationClass::tableName())] = $relationClass;
        }

        return [
            'related' => $this->normalizeTablesMap($tablesMap),
            'all' => $this->normalizeTablesMap($this->getModulesTablesMap()),
        ];
    }

    private function getModulesTablesMap()
    {
        if ($this->cacheDuration !== null && ($result = \Yii::$app->cache->get(self::CACHE_KEY)) !== false) {
            return $result;
        }
        $ds = DIRECTORY_SEPARATOR;
        $dirs = [
            \Yii::$app->getBasePath() . $ds . 'models',
        ];
        foreach (\Yii::$app->getModules() as $moduleId => $module) {
            /** @var Module $module */
            if (!$module instanceof Module) {
                $module = \Yii::$app->getModule($moduleId);
            }

            if (file_exists($dir = $module->getBasePath() . $ds . 'models')) {
                $dirs[] = $dir;
            }
        }
        $iterator = new ClassIterator(Finder::create()->files()->name('*.php')->in($dirs));
        $iterator->enableAutoloading();

        $result = [];
        /** @var \ReflectionClass $class */
        foreach ($iterator->type('yii\db\ActiveRecord') as $name => $class) {
            /** @var \yii\db\ActiveRecord $name */
            $name = $class->name;
            $result[$name::getDb()->quoteSql($name::tableName())] = $name;
        }
        if ($this->cacheDuration !== null) {
            \Yii::$app->cache->set(self::CACHE_KEY, $result, $this->cacheDuration);
        }
        return $result;
    }

    private function getRelationNames($modelClass)
    {
        $relationNames = [];
        $class = new \ReflectionClass($modelClass);
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (substr($method->name, 0, 3) !== 'get') {
                continue;
            }
            $phpDoc = $this->processPHPDoc($method);
            if (!isset($phpDoc['return'])) {
                continue;
            }
            $returnType = explode(' ', $phpDoc['return'], 2);
            $returnType = reset($returnType);
            if ($returnType === false) {
                continue;
            }
            try {
                /** @var ActiveQuery $returnType */
                $query = new $returnType($modelClass);
            } catch (Exception $e) {
                continue;
            }
            if (!$query instanceof ActiveQuery) {
                continue;
            }
            $relationNames[] = lcfirst(substr($method->name, 3));
        }
        return $relationNames;
    }

    /**
     * @param \ReflectionMethod $reflect
     * @return array two keys: params (array) and return (string)
     */
    private function processPHPDoc(\ReflectionMethod $reflect)
    {
        $phpDoc = ['params' => [], 'return' => null];
        $docComment = $reflect->getDocComment();
        if (trim($docComment) == '') {
            return null;
        }
        $docComment = preg_replace('#[ \t]*(?:\/\*\*|\*\/|\*)?[ ]{0,1}(.*)?#', '$1', $docComment);
        $docComment = ltrim($docComment, "\r\n");
        while (($newlinePos = strpos($docComment, "\n")) !== false) {
            $line = substr($docComment, 0, $newlinePos);

            $matches = [];
            if (strpos($line, '@') !== 0
                || !preg_match('#^(@\w+.*?)(\n)(?:@|\r?\n|$)#s', $docComment, $matches)
            ) {
                continue;
            }
            $tagDocblockLine = $matches[1];
            $matches = [];

            if (!preg_match('#^@(\w+)(\s|$)#', $tagDocblockLine, $matches)) {
                break;
            }
            $matches = [];
            if (!preg_match('#^@(\w+)\s+([\w|\\\]+)(?:\s+(\$\S+))?(?:\s+(.*))?#s', $tagDocblockLine, $matches)) {
                break;
            }
            if ($matches[1] != 'param') {
                if (strtolower($matches[1]) == 'return') {
                    $phpDoc['return'] = ['type' => $matches[2]];
                }
            } else {
                $phpDoc['params'][] = ['name' => $matches[3], 'type' => $matches[2]];
            }

            $docComment = str_replace($matches[1] . $matches[2], '', $docComment);
        }

        return $phpDoc;
    }

    /**
     * Prepares the data provider that should return the requested collection of the models.
     * @param ActiveRecord $model
     * @param \nineinchnick\audit\models\ActionSearch $searchModel
     * @return DataProviderInterface
     */
    protected function prepareDataProvider($model, $searchModel)
    {
        if ($this->prepareDataProvider !== null) {
            return call_user_func($this->prepareDataProvider, $this, $model, $searchModel);
        }

        return Action::getDataProvider($this->modelClass, $this->getTablesMap(), $model, $searchModel);
    }
}
