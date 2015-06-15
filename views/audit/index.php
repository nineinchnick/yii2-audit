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
<?= $form->field($model, 'table')->dropDownList(array_merge(['0' => Yii::t('app', 'Choose')], $model->getAuditTables())) ?>

<?= Html::submitButton(Yii::t('app', 'Search'), ['class' => 'btn btn-success']); ?>

<?php if ($dataProvider): ?>
    <?= ListView::widget([
        'dataProvider' => $dataProvider,
        'itemView'     => '_list',
        'viewParams'   => [
            'arrayDiff'           => $arrayDiff,
            'notDisplayedColumns' => $notDisplayedColumns,
            'table' => $model->table,
        ],
    ]);
    ?>
<?php endif; ?>