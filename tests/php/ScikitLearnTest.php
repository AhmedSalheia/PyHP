<?php

declare(strict_types=1);

/*
 * Optional: runs only when the interpreter under test has scikit-learn.
 */

beforeEach(function (): void {
    $this->py = testSession();
});

afterEach(function (): void {
    $this->py->close();
});

test('a model trained on PHP arrays predicts PHP rows', function (): void {
    $this->py->import('sklearn.tree');
    $tree = $this->py->modules->sklearn->tree->DecisionTreeClassifier(random_state: 0);
    $tree->fit([[0, 0], [1, 1], [0, 1], [1, 0]], [0, 1, 0, 1]);

    expect($tree->predict([[1, 0], [0, 1]])->tolist())->toBe([1, 0])
        ->and($tree->predict_proba([[1, 1]])->tolist())->toBe([[0.0, 1.0]]);
})->skip(fn () => ! pythonHasModule('sklearn'), 'scikit-learn is not installed');

test('a model saved with joblib works in a new session', function (): void {
    $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-test-model-'.bin2hex(random_bytes(4)).'.joblib';
    $this->py->import('sklearn.linear_model');
    $model = $this->py->modules->sklearn->linear_model->LinearRegression();
    $model->fit([[1], [2], [3]], [2, 4, 6]);
    $this->py->import('joblib')->dump($model, $file);

    $other = testSession();
    try {
        $prediction = $other->import('joblib')->load($file)->predict([[10]])->tolist();
    } finally {
        $other->close();
        unlink($file);
    }

    expect($prediction[0])->toEqualWithDelta(20.0, 1e-9);
})->skip(fn () => ! pythonHasModule('sklearn'), 'scikit-learn is not installed');
