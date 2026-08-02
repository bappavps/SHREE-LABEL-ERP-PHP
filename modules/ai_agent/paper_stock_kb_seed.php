<?php
require 'config/db.php';

$db = getDB();

$intents = [
    [
        'category' => 'Intent',
        'question' => 'Paper Stock Summary Intent',
        'keywords' => 'paper stock, summary, total paper, koto paper, paper ache, total roll, total meter, mot koto paper, sob paper, overall paper, running meter total, inventory, kagoj koto ache, kagaj, kagoj',
        'answer' => "Intent: Paper Stock Summary\nExample Questions: paper stock koto ache?, total paper dekhaw\nExpected Tool: erp_query_tool\nModule: paper_stock\nFilters to Extract: none\nAction: Fetches overall paper stock summary."
    ],
    [
        'category' => 'Intent',
        'question' => 'Paper Stock Company Intent',
        'keywords' => 'navkar, nitin, avery, krishna, austin, nrgi, company, vendor, brand, er paper, kagoj, kagaj, company paper',
        'answer' => "Intent: Company Paper Stock\nExample Questions: Navkar er paper koto ache?, Nitin paper dekhaw, Avery total roll\nExpected Tool: erp_query_tool\nModule: paper_stock\nFilters to Extract: company"
    ],
    [
        'category' => 'Intent',
        'question' => 'Paper Stock Type Intent',
        'keywords' => 'chromo, thermal, maplitho, pp white, pp clear, paper type, white pp, dhar, clear, film, synthetic, semi gloss, vellam',
        'answer' => "Intent: Paper Type Stock\nExample Questions: Chromo paper koto ache?, PP White er stock ki, maplitho dekhaw\nExpected Tool: erp_query_tool\nModule: paper_stock\nFilters to Extract: paper_type"
    ],
    [
        'category' => 'Intent',
        'question' => 'Paper Stock Width and Length Intent',
        'keywords' => 'width, length, mm, mtr, meter, chaura, lamba, size, 1000mm, jumbo, slitting, 320mm, 330mm, 1010mm',
        'answer' => "Intent: Paper Size Stock\nExample Questions: 1000mm width paper, 320mm roll, jumbo roll koto ache\nExpected Tool: erp_query_tool\nModule: paper_stock\nFilters to Extract: width_mm, length_mtr"
    ],
    [
        'category' => 'Intent',
        'question' => 'Paper Stock GSM Intent',
        'keywords' => 'gsm, 128 gsm, 80 gsm, 60 gsm, 150 gsm, thickness, mota',
        'answer' => "Intent: Paper GSM Stock\nExample Questions: 128 gsm paper ache?, 80gsm chromo\nExpected Tool: erp_query_tool\nModule: paper_stock\nFilters to Extract: gsm"
    ],
    [
        'category' => 'Intent',
        'question' => 'Paper Stock Specific Roll Intent',
        'keywords' => 'roll no, roll number, slc/, slc, specific roll, barcode, roll details, company roll no, child roll, parent roll',
        'answer' => "Intent: Specific Roll Details\nExample Questions: SLC/2026/1688 details, roll no 1234\nExpected Tool: erp_query_tool\nModule: paper_stock\nFilters to Extract: roll_no, parent_roll_no, company_roll_no"
    ],
    [
        'category' => 'Intent',
        'question' => 'Paper Stock Lot and Batch Intent',
        'keywords' => 'lot, batch, lot no, batch no, lot number, consignment',
        'answer' => "Intent: Lot and Batch Search\nExample Questions: Lot 5542 koto ache, Batch B123 paper\nExpected Tool: erp_query_tool\nModule: paper_stock\nFilters to Extract: lot_no, batch_no"
    ],
    [
        'category' => 'Intent',
        'question' => 'Paper Stock Remarks Intent',
        'keywords' => 'remarks, note, comment, damaged, defect, rejected, hold, fault',
        'answer' => "Intent: Paper Remarks Search\nExample Questions: damaged paper ache?, hold roll dekhao, remarks\nExpected Tool: erp_query_tool\nModule: paper_stock\nFilters to Extract: remarks"
    ],
    [
        'category' => 'Intent',
        'question' => 'Paper Stock Rate and Weight Intent',
        'keywords' => 'purchase rate, rate, price, dam, khoroch, cost, weight, kg, ojon, sqm, square meter',
        'answer' => "Intent: Paper Rate and Weight Search\nExample Questions: highest rate paper, 50kg roll ache?, sqm koto\nExpected Tool: erp_query_tool\nModule: paper_stock\nFilters to Extract: purchase_rate, weight_kg, sqm"
    ],
    [
        'category' => 'Intent',
        'question' => 'Paper Stock Status Intent',
        'keywords' => 'status, main, stock, slitting, job assign, in production, consumed, available',
        'answer' => "Intent: Paper Status Search\nExample Questions: Job assign paper, consumed roll, slitting e ki ache\nExpected Tool: erp_query_tool\nModule: paper_stock\nFilters to Extract: status"
    ]
];

$aliases = [
    ['alias' => 'nabkar', 'canonical' => 'navkar'],
    ['alias' => 'nabacar', 'canonical' => 'navkar'],
    ['alias' => 'khromo', 'canonical' => 'chromo'],
    ['alias' => 'chrome', 'canonical' => 'chromo'],
    ['alias' => 'white pp', 'canonical' => 'pp white'],
    ['alias' => 'thermal paper', 'canonical' => 'thermal'],
    ['alias' => 'map lito', 'canonical' => 'maplitho'],
    ['alias' => 'mapletho', 'canonical' => 'maplitho'],
    ['alias' => 'siliting', 'canonical' => 'slitting']
];

echo "Starting DB Migration for Paper Stock Intents...\n";

// Create Aliases Table if not exists
$db->query("CREATE TABLE IF NOT EXISTS `ai_knowledge_aliases` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `alias` VARCHAR(100) NOT NULL,
  `canonical_word` VARCHAR(100) NOT NULL,
  UNIQUE KEY `idx_alias` (`alias`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Upsert Intents
$stmtCheck = $db->prepare("SELECT id FROM ai_agent_knowledge WHERE question = ?");
$stmtInsert = $db->prepare("INSERT INTO ai_agent_knowledge (category, question, keywords, answer, is_active, sort_order) VALUES (?, ?, ?, ?, 1, 0)");
$stmtUpdate = $db->prepare("UPDATE ai_agent_knowledge SET category = ?, keywords = ?, answer = ? WHERE id = ?");

$inserted = 0;
$updated = 0;

foreach ($intents as $intent) {
    $stmtCheck->bind_param("s", $intent['question']);
    $stmtCheck->execute();
    $res = $stmtCheck->get_result();
    
    if ($row = $res->fetch_assoc()) {
        $stmtUpdate->bind_param("sssi", $intent['category'], $intent['keywords'], $intent['answer'], $row['id']);
        $stmtUpdate->execute();
        $updated++;
    } else {
        $stmtInsert->bind_param("ssss", $intent['category'], $intent['question'], $intent['keywords'], $intent['answer']);
        $stmtInsert->execute();
        $inserted++;
    }
}
echo "Intents UPSERT complete. Inserted: $inserted, Updated: $updated\n";

// Upsert Aliases
$stmtAliasCheck = $db->prepare("SELECT id FROM ai_knowledge_aliases WHERE alias = ? AND kb_id = ?");
$stmtAliasInsert = $db->prepare("INSERT INTO ai_knowledge_aliases (kb_id, alias) VALUES (?, ?)");

$aliasInserted = 0;

$aliasMappings = [
    'Paper Stock Company Intent' => ['nabkar', 'nabacar', 'nrj', 'abery', 'krish'],
    'Paper Stock Type Intent' => ['khromo', 'chrome', 'white pp', 'thermal paper', 'map lito', 'mapletho'],
    'Paper Stock Width and Length Intent' => ['siliting', 'choura', 'chawra']
];

foreach ($aliasMappings as $intentQuestion => $aliasList) {
    // Find KB ID
    $res = $db->query("SELECT id FROM ai_agent_knowledge WHERE question = '" . $db->real_escape_string($intentQuestion) . "'");
    if ($row = $res->fetch_assoc()) {
        $kbId = $row['id'];
        foreach ($aliasList as $alias) {
            $stmtAliasCheck->bind_param("si", $alias, $kbId);
            $stmtAliasCheck->execute();
            $chkRes = $stmtAliasCheck->get_result();
            if (!$chkRes->fetch_assoc()) {
                $stmtAliasInsert->bind_param("is", $kbId, $alias);
                $stmtAliasInsert->execute();
                $aliasInserted++;
            }
        }
    }
}
echo "Aliases UPSERT complete. Inserted: $aliasInserted\n";

$db->close();
echo "Migration finished successfully.\n";
