<?php

declare(strict_types=1);

use PyHP\Py;
use PyHP\PyObject;

/*
 * Optional: runs only when the interpreter under test has spaCy and, for most
 * tests, its small English model:
 *   python -m pip install spacy
 *   python -m spacy download en_core_web_sm
 */

beforeEach(function (): void {
    $this->py = testSession();
    if (pythonHasModule('spacy')) {
        $this->spacy = $this->py->import('spacy');
    }
});

afterEach(function (): void {
    $this->py->close();
});

$needsModel = fn (): bool => ! pythonHasModule('spacy') || ! pythonHasModule('en_core_web_sm');

test('a blank pipeline tokenizes without any model', function (): void {
    $nlp = $this->spacy->blank('en');
    $doc = $nlp("PyHP lets PHP use spaCy, doesn't it?");
    $tokens = [];
    foreach ($doc as $token) {
        $tokens[] = $token->text;
    }

    expect($tokens)->toBe(['PyHP', 'lets', 'PHP', 'use', 'spaCy', ',', 'does', "n't", 'it', '?']);
})->skip(fn () => ! pythonHasModule('spacy'), 'spaCy is not installed');

test('named entities and sentences', function (): void {
    $nlp = $this->spacy->load('en_core_web_sm');
    $doc = $nlp('Tim Cook visited Ramallah on Monday. Apple paid $1 billion.');

    $entities = [];
    foreach ($doc->ents as $entity) {
        $entities[$entity->text] = $entity->label_;
    }
    $sentences = [];
    foreach ($doc->sents as $sentence) {
        $sentences[] = (string) $sentence;
    }

    expect($entities)->toMatchArray(['Tim Cook' => 'PERSON', 'Ramallah' => 'GPE', 'Apple' => 'ORG'])
        ->and($sentences)->toHaveCount(2);
})->skip($needsModel, 'en_core_web_sm is not installed');

test('to_json returns every token in one round trip', function (): void {
    $nlp = $this->spacy->load('en_core_web_sm');
    $json = $nlp('The cats were sleeping.')->to_json();
    $lemmas = array_column($json['tokens'], 'lemma');
    $pos = array_column($json['tokens'], 'pos');

    expect($lemmas)->toBe(['the', 'cat', 'be', 'sleep', '.'])
        ->and($pos[1])->toBe('NOUN');
})->skip($needsModel, 'en_core_web_sm is not installed');

test('the Matcher finds patterns written as PHP arrays', function (): void {
    $nlp = $this->spacy->load('en_core_web_sm');
    $this->py->import('Matcher', from: 'spacy.matcher');
    $matcher = $this->py->classes->Matcher($nlp->vocab);
    $matcher->add('MONEY_PHRASE', [[['LIKE_NUM' => true], ['LOWER' => 'billion']]]);
    $doc = $nlp('They raised 2 billion, then 3 billion more.');

    // Match ids are 64-bit unsigned hashes (proxies in PHP); as_spans gives labels directly.
    $raw = $matcher($doc);
    $spans = $matcher($doc, as_spans: true);

    expect($raw[0][0])->toBeInstanceOf(PyObject::class)
        ->and(array_map(fn (PyObject $s) => [(string) $s, $s->label_], $spans))
        ->toBe([['2 billion', 'MONEY_PHRASE'], ['3 billion', 'MONEY_PHRASE']]);
})->skip($needsModel, 'en_core_web_sm is not installed');

test('displacy renders entity HTML for a web page', function (): void {
    $nlp = $this->spacy->load('en_core_web_sm');
    $html = $this->spacy->displacy->render($nlp('Apple is based in Cupertino.'), style: 'ent');

    expect($html)->toBeString()
        ->and($html)->toContain('Cupertino');
})->skip($needsModel, 'en_core_web_sm is not installed');

test('nlp.pipe processes many texts lazily', function (): void {
    $nlp = $this->spacy->blank('en');
    $lengths = [];
    foreach ($nlp->pipe(['One.', 'Two words.', 'And three here.']) as $doc) {
        $lengths[] = Py::len($doc);
    }

    expect($lengths)->toBe([2, 3, 4]);
})->skip(fn () => ! pythonHasModule('spacy'), 'spaCy is not installed');

test('a pipeline component can be a PHP closure', function (): void {
    $nlp = $this->spacy->blank('en');
    $seen = [];
    // Plain dicts like doc.user_data come back to PHP as copies, so write
    // through a custom extension attribute ($doc->_ is a proxy) instead.
    $this->py->exec("from spacy.tokens import Doc\nDoc.set_extension('checked_by', default=None, force=True)");
    $this->py->vars->php_component = function (PyObject $doc) use (&$seen): PyObject {
        $seen[] = Py::len($doc);
        $doc->_->checked_by = 'PHP';

        return $doc;
    };
    $this->py->exec("from spacy.language import Language\nLanguage.component('php_checker', func=php_component)");
    $nlp->add_pipe('php_checker');

    $doc = $nlp('Checked by a PHP closure.');

    expect($seen)->toBe([6])
        ->and($doc->_->checked_by)->toBe('PHP');
})->skip(fn () => ! pythonHasModule('spacy'), 'spaCy is not installed');

test('doc.user_data can be written from PHP through Py::ref', function (): void {
    $doc = $this->spacy->blank('en')('Checked by PHP.');

    Py::ref($doc, 'user_data')['checked_by'] = 'PHP';

    expect($doc->user_data)->toBe(['checked_by' => 'PHP']);
})->skip(fn () => ! pythonHasModule('spacy'), 'spaCy is not installed');
