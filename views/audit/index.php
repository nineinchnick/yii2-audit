<?php

/**
 * Displays content durning status change
 * @author Patryk Radziszewski <pradziszewski@netis.pl>
 */
use yii\helpers\Html;
use yii\widgets\ActiveForm;
use yii\widgets\ListView;
?>
<div class="page-header">
    <?php $form = ActiveForm::begin(['method' => 'GET']); ?>
    <?= \nineinchnick\audit\models\AuditForm::renderRow($this, $model, $form, [$fields], Yii::$app->request->getIsAjax() ? 12 : 6); ?>
    <?= Html::submitButton(Yii::t('app', 'Search'), ['class' => 'btn btn-success']); ?>
    <?php ActiveForm::end(); ?>
</div>
<?php
foreach (Yii::$app->session->getAllFlashes() as $key => $message) {
    echo '<div class="alert alert-' . $key . '">' . $message . '</div>';
}
?>
<?php if ($dataProvider): ?>
    <?=
    ListView::widget([
        'dataProvider' => $dataProvider,
        'itemView' => '_list',
        'viewParams' => [
            'arrayDiff' => $arrayDiff,
            'hiddenColumns' => $hiddenColumns,
            'table' => $model->table,
        ],
    ]);
    ?>
<?php endif; ?>