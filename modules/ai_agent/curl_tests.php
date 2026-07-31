<?php
$queries = [
    '/plate "Raneswar 1000" ai job ta ki amra print korechi?',
    'Alexe 300 ar last job kobe hoyeche?',
    'প্লেট নম্বর P-1234 এর ডিটেলস দেখাও',
    'P-1234 প্লেটের সাইজ কত?',
    'P-1234 প্লেটের জন্য কোন সিলিন্ডার লাগে?',
    'P-101 প্লেটটি কবে রিসিভ হয়েছে?',
    'কোকা-কোলা লেবেলের প্লেট কোনটা?',
    'Mango Juice জবের জন্য প্লেট ডিটেলস দাও',
    'Amul-এর প্লেটগুলো কী কী?',
    '104 T সিলিন্ডারের কয়টি প্লেট আছে?',
    '120 সিলিন্ডারের সব প্লেটের লিস্ট দাও',
    'কোন কোন জবে 80 সিলিন্ডার ব্যবহার হয়?',
    'Chromo পেপারের জন্য কোন কোন প্লেট তৈরি আছে?',
    'PP Clear-এর প্লেটগুলো দেখাও',
    'পেপার সাইজ 200mm এর প্লেটগুলো দেখাও',
    'P-101 প্লেটে মোট কয়টি কালার ব্যবহার হয়েছে?',
    'P-123 প্লেটে স্পেশাল কালার (Special Color) কী কী আছে?',
    'কোন প্লেটগুলোতে ৪টির বেশি কালার (CMYK + Special) আছে?',
    'P-101 প্লেটের Horizontal Gap (Gap H) এবং Vertical Gap (Gap V) কত?',
    'P-123 প্লেটের রিপিট (Repeat) ভ্যালু কত?',
    '150mm রিপিটের প্লেটগুলো দেখাও',
    '104 সিলিন্ডারের Chromo পেপারের প্লেট দেখাও',
    'Make By \'Navkar\'-এর 120 সিলিন্ডারের প্লেটগুলো কী কী?',
    'P-101 প্লেট দিয়ে 2000 মিটার রোলে কয়টি লেবেল প্রিন্ট হবে?',
    'Amul লেবেলের ১ লাখ পিস প্রিন্ট করতে কত মিটার পেপার লাগবে?',
    'P-101 এর রিপিট 152.4mm, এর জন্য কত দাঁতের (Teeth) সিলিন্ডার লাগবে?',
    'Blue500-এ মোট কত কালার স্টেশনের প্লেট লাগবে?'
];

foreach ($queries as $i => $q) {
    echo "TEST " . ($i+1) . " / " . count($queries) . "\n";
    echo "QUERY: " . $q . "\n";
    
    $sid = "test-session-id-" . $i;
    $cookie = "PHPSESSID=" . $sid;
    
    // Auth
    stream_context_create(["http" => ["method" => "GET", "header" => "Cookie: " . $cookie . "\r\n"]]);
    @file_get_contents("http://localhost/calipot-erp/shree-label-php/modules/ai_agent/mock_auth.php?sid=" . $sid);
    
    $opts = [
        "http" => [
            "method" => "POST",
            "header" => "Content-type: application/x-www-form-urlencoded\r\nCookie: " . $cookie . "\r\n",
            "content" => http_build_query([
                'action' => 'query',
                'prompt' => $q,
                'user_lang' => 'Bengali'
            ])
        ]
    ];
    $context = stream_context_create($opts);
    $url = 'http://localhost/calipot-erp/shree-label-php/modules/ai_agent/api.php';
    
    $res = @file_get_contents($url, false, $context);
    if ($res) {
        $json = json_decode($res, true);
        if ($json && isset($json['answer'])) {
            echo "ANSWER: \n" . strip_tags(str_replace('<br>', "\n", $json['answer'])) . "\n";
        } else {
            echo "RAW OUTPUT: " . $res . "\n";
        }
    } else {
        echo "REQUEST FAILED\n";
    }
    echo str_repeat('-', 50) . "\n\n";
}
