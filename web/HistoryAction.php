<?php
/**
 * @copyright Copyright (c) 2015 Jan Waś <janek.jan@gmail.com>
 * @license BSD
 */

namespace nineinchnick\audit\web;

use yii\data\ActiveDataProvider;

class HistoryAction extends \yii\rest\Action
{
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
    public $view;

    /**
     * @return ActiveDataProvider
     */
    public function run($id = null, $version = null)
    {
        $model = $id === null ? null : $this->findModel($id);
        if ($this->checkAccess) {
            call_user_func($this->checkAccess, $this->id, $model);
        }

        //! @todo read entries from logged_actions table, optionally grouped by changesets

        if ($model !== null) {
            return $this->controller->render($this->view, ['model' => $model]);
        }

        return $this->controller->render($this->view, ['dataProvider' => $this->prepareDataProvider()]);
    }

    /**
     * Prepares the data provider that should return the requested collection of the models.
     * @return ActiveDataProvider
     */
    protected function prepareDataProvider()
    {
        if ($this->prepareDataProvider !== null) {
            return call_user_func($this->prepareDataProvider, $this);
        }

        /* @var $modelClass \yii\db\BaseActiveRecord */
        $modelClass = $this->modelClass;

        return new ActiveDataProvider([
            'query' => $modelClass::find(),
        ]);
    }
}
