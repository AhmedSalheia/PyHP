<?php

declare(strict_types=1);

// Natural language processing with spaCy. Needs spaCy and its small English model:
//   python -m pip install spacy
//   python -m spacy download en_core_web_sm
// Run with: php examples/07_spacy_nlp.php

require __DIR__.'/bootstrap.php';

use PyHP\Exception\PythonException;
use PyHP\Py;
use PyHP\PyObject;

$py = Py::session();
$spacy = $py->import('spacy');

// Load the model once per session; reuse $nlp for every text (loading is the slow part).
try {
    $nlp = $spacy->load('en_core_web_sm');
} catch (PythonException $e) {
    exit("The en_core_web_sm model is missing. Install it with:\n  python -m spacy download en_core_web_sm\n");
}

$text = 'Tim Cook visited Ramallah on Monday. Apple said it would invest $1 billion in local startups.';
$doc = $nlp($text);

// 1. Named entities and sentences.
echo "Entities:\n";
foreach ($doc->ents as $entity) {
    printf("  %-14s %s\n", $entity->text, $entity->label_);
}
echo "Sentences:\n";
foreach ($doc->sents as $sentence) {
    echo "  - {$sentence}\n";
}

// 2. Every token in ONE round trip: to_json() returns plain arrays. Iterating
//    $doc token by token works too, but each attribute read is a round trip.
$json = $nlp('The cats were sleeping on the warm roofs.')->to_json();
echo "\nToken  Lemma  POS\n";
foreach ($json['tokens'] as $token) {
    $word = substr($json['text'], $token['start'], $token['end'] - $token['start']);
    printf("  %-9s %-8s %s\n", $word, $token['lemma'], $token['pos']);
}

// 3. Rule-based matching with patterns written as PHP arrays.
$py->import('Matcher', from: 'spacy.matcher');
$matcher = $py->classes->Matcher($nlp->vocab);
$matcher->add('MONEY_PHRASE', [[['LIKE_NUM' => true], ['LOWER' => ['IN' => ['million', 'billion']]]]]);
// as_spans gives labels directly; raw match ids are 64-bit hashes (proxies in PHP).
foreach ($matcher($nlp('They raised 2 million, then 3 billion more.'), as_spans: true) as $span) {
    echo "\nMatched '{$span}' as {$span->label_}";
}
echo "\n";

// 4. Many texts at once: nlp.pipe batches internally and PHP iterates lazily.
$reviews = ['Great service in Nablus!', 'The delivery to Haifa was late.', 'Nothing special.'];
echo "\nPlaces per review:\n";
foreach ($nlp->pipe($reviews) as $i => $review) {
    $places = [];
    foreach ($review->ents as $entity) {
        if ($entity->label_ === 'GPE') {
            $places[] = $entity->text;
        }
    }
    echo "  {$reviews[$i]} => ".($places === [] ? '-' : implode(', ', $places))."\n";
}

// 5. A PHP closure as a pipeline component. Write results through a custom
//    extension ($doc->_ is a proxy); plain dicts like doc.user_data come back as copies.
$py->exec("from spacy.tokens import Doc\nDoc.set_extension('word_count', default=None, force=True)");
$py->vars->count_words = function (PyObject $doc): PyObject {
    $doc->_->word_count = count(array_filter(
        $doc->to_json()['tokens'],
        fn (array $t): bool => $t['pos'] !== 'PUNCT',
    ));

    return $doc;
};
$py->exec("from spacy.language import Language\nLanguage.component('php_word_count', func=count_words)");
$nlp->add_pipe('php_word_count', last: true);
echo "\nWords (counted by PHP): ".$nlp('Hello there, PHP and Python friends!')->_->word_count."\n";

// 6. displaCy renders HTML you can drop into a web page.
$html = $spacy->displacy->render($doc, style: 'ent', page: true);
$out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pyhp-entities.html';
file_put_contents($out, $html);
echo "Entity highlight page written to {$out}\n";

$py->close();
