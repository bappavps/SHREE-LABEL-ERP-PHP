<?php
$c = file_get_contents('plate_handler_body.php');
$c = str_replace(
    '$jobSearchRaw = $jobSearchExplicit ?: implode(\' \', $pTerms);',
    'if (!$jobSearchExplicit) {
        $newPTerms = [];
        foreach ($pTerms as $term) {
            if (preg_match(\'/[a-zA-Z]\d|\d[a-zA-Z]/\', $term)) {
                $splitTerm = preg_replace(\'/([a-zA-Z])(\d)/\', \'$1 $2\', $term);
                $splitTerm = preg_replace(\'/(\d)([a-zA-Z])/\', \'$1 $2\', $splitTerm);
                $newPTerms = array_merge($newPTerms, explode(\' \', $splitTerm));
            } else {
                $newPTerms[] = $term;
            }
        }
        $pTerms = $newPTerms;
    }
    $jobSearchRaw = $jobSearchExplicit ?: implode(\' \', $pTerms);',
    $c
);
file_put_contents('plate_handler_body.php', $c);
echo "Updated plate_handler_body.php with implicit split\n";
