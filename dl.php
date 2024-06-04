<?php

if(!defined('DOKU_INC')) define('DOKU_INC', realpath(__DIR__.'/../../../').'/');
require_once DOKU_INC.'inc/init.php';

# FIXME check permissions

/** @var helper_plugin_questionaire $helper */
$helper = plugin_load('helper', 'questionaire');
$ID = getID();
$quest = $helper->getQuestionaire($ID);

if(!$quest) {
    http_status(404);
    echo 'No questionaire found';
    exit;
}

$db = $helper->getDB();
if(!$db) {
    http_status(500);
    echo 'Database not available';
    exit;
}

$items = array_merge(['answered_on', 'answered_by'], $helper->getQuestionIDs($ID));
$lastuser = '';

// first row is the header
$data = array_combine($items, $items);
$data['answered_on'] = 'Timestamp';
$data['answered_by'] = 'User';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$ID.'.csv"');
$resp = $db->query('SELECT * FROM answers WHERE page = ? ORDER BY answered_by', $ID);
$fp = fopen('php://output', 'w');
while($row = $resp->fetch(PDO::FETCH_ASSOC)) {
    // if this row is for a new user, output the last user's data
    $user = $row['answered_by'];
    if($user != $lastuser) {
        fputcsv($fp, array_values($data));

        // prepare new data array
        $lastuser = $user;
        $data = array_fill_keys($items, '');
        $data['answered_on'] = date('Y-m-d H:i:s', $row['answered_on']);
        $data['answered_by'] = $user;
    }

    // store answer data
    if($data[$row['question']] !== '') $data[$row['question']] .= ', ';
    $data[$row['question']] .= $row['answer'];
}
fputcsv($fp, array_values($data)); // last entry
fclose($fp);




