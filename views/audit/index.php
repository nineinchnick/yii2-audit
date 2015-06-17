<?php

/**
 * Displays content durning status change
 * @author Patryk Radziszewski <pradziszewski@netis.pl>
 */
use yii\helpers\Html;
use yii\widgets\ActiveForm;
use yii\widgets\ListView;
?>
<?php $form = ActiveForm::begin(['method' => 'GET']); ?>
<?= \nineinchnick\audit\models\AuditForm::renderRow($this, $model, $form, [$fields], Yii::$app->request->getIsAjax() ? 12 : 6); ?>

<?= Html::submitButton(Yii::t('app', 'Search'), ['class' => 'btn btn-success']); ?>

<?php if ($dataProvider): ?>
    <?=

    ListView::widget([
        'dataProvider' => $dataProvider,
        'itemView'     => '_list',
        'viewParams'   => [
            'arrayDiff'           => $arrayDiff,
            'notDisplayedColumns' => $notDisplayedColumns,
            'table'               => $model->table,
        ],
    ]);
    ?>
<?php endif; ?>