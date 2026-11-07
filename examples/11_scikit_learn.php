<?php

declare(strict_types=1);

// Machine learning with scikit-learn: train on PHP data, predict, save the model.
// Needs scikit-learn:
//   python -m pip install scikit-learn      (or: vendor/bin/pyhp setup)
// Run with: php examples/11_scikit_learn.php

require __DIR__.'/bootstrap.php';

use PyHP\Py;

$py = Py::session();
$sklearn = $py->import('sklearn');
$py->import('sklearn.model_selection');
$py->import('sklearn.ensemble');
$py->import('sklearn.metrics');
$joblib = $py->import('joblib');

// 1. Training data as plain PHP arrays, e.g. rows from your database:
//    [days since last order, orders per month, support tickets] => churned?
mt_srand(7);
$features = [];
$churned = [];
foreach (range(1, 400) as $i) {
    $daysSinceOrder = mt_rand(0, 120);
    $ordersPerMonth = mt_rand(0, 12);
    $tickets = mt_rand(0, 6);
    $risk = $daysSinceOrder / 120 - $ordersPerMonth / 12 + $tickets / 10 + mt_rand(-20, 20) / 100;
    $features[] = [$daysSinceOrder, $ordersPerMonth, $tickets];
    $churned[] = $risk > 0.25 ? 1 : 0;
}

// 2. Split, train and measure. train_test_split returns four lists; PHP destructures them.
[$xTrain, $xTest, $yTrain, $yTest] = $sklearn->model_selection->train_test_split(
    $features, $churned, test_size: 0.25, random_state: 42,
);
$model = $sklearn->ensemble->RandomForestClassifier(n_estimators: 100, random_state: 42);
$model->fit($xTrain, $yTrain);
$accuracy = $sklearn->metrics->accuracy_score($yTest, $model->predict($xTest));
printf("Accuracy on %d unseen customers: %.1f%%\n", count($yTest), $accuracy * 100);

// 3. Predict for new customers. Results are NumPy arrays: tolist() copies them into PHP.
$customers = ['Lina' => [3, 9, 0], 'Omar' => [95, 1, 4], 'Maya' => [40, 4, 2]];
$probabilities = $model->predict_proba(array_values($customers))->tolist();
foreach (array_keys($customers) as $i => $name) {
    printf("  %-5s churn risk %3d%%\n", $name, round($probabilities[$i][1] * 100));
}

// 4. Which inputs matter most?
$importance = array_combine(['days since order', 'orders per month', 'tickets'], $model->feature_importances_->tolist());
arsort($importance);
echo 'Most important input: '.array_key_first($importance)."\n";

// 5. Save the model once, then load it in later requests instead of retraining.
$file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-churn-model.joblib';
$joblib->dump($model, $file);
$py->close();

$later = Py::session();
$loaded = $later->import('joblib')->load($file);
echo 'Loaded model predicts Omar: '.($loaded->predict([$customers['Omar']])->tolist()[0] ? 'will churn' : 'will stay')."\n";
$later->close();
