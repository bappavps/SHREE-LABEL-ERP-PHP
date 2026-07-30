-- ============================================================
-- Paper Stock Knowledge Base Seed Data
-- Multilingual: English + Bengali + Hindi keywords
-- Category: FAQ / Quick Chip
-- Usage: Run this AFTER ai_agent_migration.sql has created the table
-- ============================================================

-- 1. GENERAL INVENTORY COUNT
INSERT INTO `ai_agent_knowledge` (`category`, `keywords`, `question`, `answer`, `sort_order`) VALUES
('FAQ', 'total paper rolls, how many rolls, total stock, inventory count, মোট পেপার রোল, মোট রোল, স্টক কত, কতগুলো রোল, कुल पेपर रोल, कितने रोल, स्टॉक कितना, कुल स्टॉक', 'মোট কতগুলো পেপার রোল আছে?', 'পেপার স্টক মডিউলে বর্তমানে সকল সক্রিয় রোলের তথ্য রয়েছে। বিস্তারিত জানতে /paperstock টাইপ করুন।', 1),
('FAQ', 'total running meters, total length, মোট রানিং মিটার, মোট দৈর্ঘ্য, कुल रनिंग मीटर, कुल लंबाई', 'সব মিলিয়ে কত রানিং মিটার?', 'পেপার স্টকে উপলব্ধ সকল রোলের মোট রানিং মিটার দেখতে /paperstock টাইপ করুন।', 2),
('FAQ', 'total sqm, total square meter, মোট sqm, মোট স্কয়ার মিটার, कुल वर्ग मीटर', 'মোট SQM কত?', 'স্টকের মোট স্কয়ার মিটার পরিমাণ জানতে /paperstock টাইপ করুন।', 3),
('FAQ', 'jumbo rolls count, big rolls, large rolls, জাম্বো রোল, বড় রোল, बड़े रोल, जंबो रोल', 'বর্তমানে কয়টি জাম্বো রোল আছে?', 'জাম্বো রোল হচ্ছে যার প্রস্থ ১০০০ মিমি বা তার বেশি। বিস্তারিত /paperstock টাইপ করে দেখুন।', 4),
('FAQ', 'slitted rolls count, small rolls, slitting rolls, স্লিটিং রোল, ছোট রোল, स्लिटिंग रोल, छोटे रोल', 'স্লিটিং রোল কতগুলো?', 'স্লিটিং রোল হচ্ছে যার প্রস্থ ১০০০ মিমি-এর কম। বিস্তারিত /paperstock টাইপ করুন।', 5);

-- 2. BY COMPANY
INSERT INTO `ai_agent_knowledge` (`category`, `keywords`, `question`, `answer`, `sort_order`) VALUES
('FAQ', 'krishna company rolls, krishna paper stock, krishna inventory, krishna stock, কৃষ্ণা কোম্পানি, কৃষ্ণা রোল, कृष्णा कंपनी, कृष्णा पेपर', 'Krishna কোম্পানির কত রোল আছে?', 'Krishna কোম্পানির পেপার রোলের তথ্য পেতে /paperstock Krishna টাইপ করুন।', 10),
('FAQ', 'austin paper rolls, austin stock, অস্টিন পেপার, অস্টিন রোল, ऑस्टिन पेपर, ऑस्टिन रोल', 'Austin পেপারের কত রোল?', 'Austin কোম্পানির পেপার স্টক দেখতে /paperstock Austin টাইপ করুন।', 11),
('FAQ', 'navkar rolls, navkar stock, নাভকার রোল, নাভকার কোম্পানি, नवकार रोल, नवकार स्टॉक', 'Navkar কোম্পানি থেকে কত রোল এসেছে?', 'Navkar কোম্পানির রোল তথ্যের জন্য /paperstock Navkar টাইপ করুন।', 12),
('FAQ', 'nrgi rolls, nrgi stock, nrgi running meters, NRGI রোল, NRGI স্টক, एनआरजीआई रोल, एनआरजीआई स्टॉक', 'NRGI-র মোট রানিং মিটার কত?', 'NRGI কোম্পানির বিস্তারিত তথ্যের জন্য /paperstock NRGI টাইপ করুন।', 13),
('FAQ', 'avery rolls, avery stock, এভারি রোল, এভারি কোম্পানি, एवरी रोल, एवरी स्टॉक', 'Avery-র স্টক দেখাও', 'Avery কোম্পানির পেপার স্টক দেখতে /paperstock Avery টাইপ করুন।', 14),
('FAQ', 'abhinav rolls, abhinav stock, অভিনব রোল, অভিনব কোম্পানি, अभिनव रोल, अभिनव स्टॉक', 'Abhinav কোম্পানির সব রোল দেখাও', 'Abhinav কোম্পানির সকল রোলের তথ্যের জন্য /paperstock Abhinav টাইপ করুন।', 15),
('FAQ', 'raj paper rolls, raj paper stock, রাজ পেপার, রাজ পেপার রোল, राज पेपर, राज पेपर रोल', 'Raj Paper-এর ইনভেন্টরি কেমন?', 'Raj Paper-এর স্টক দেখতে /paperstock Raj Paper টাইপ করুন।', 16);

-- 3. BY PAPER TYPE
INSERT INTO `ai_agent_knowledge` (`category`, `keywords`, `question`, `answer`, `sort_order`) VALUES
('FAQ', 'chromo paper rolls, chromo stock, ক্রোমো পেপার, ক্রোমো রোল, क्रोमो पेपर, क्रोमो रोल', 'Chromo পেপারের কত রোল আছে?', 'Chromo পেপারের স্টক জানতে /paperstock Chromo টাইপ করুন।', 20),
('FAQ', 'pp white paper, pp white rolls, পিপি হোয়াইট, পিপি হোয়াইট রোল, पीपी व्हाइट, पीपी व्हाइट रोल', 'PP White স্টকে কত?', 'PP White পেপারের তথ্যের জন্য /paperstock PP White টাইপ করুন।', 21),
('FAQ', 'pp clear rolls, pp clear stock, পিপি ক্লিয়ার, পিপি ক্লিয়ার রোল, पीपी क्लियर, पीपी क्लियर रोल', 'PP Clear রোল কতগুলো?', 'PP Clear পেপারের বিস্তারিত জানতে /paperstock PP Clear টাইপ করুন।', 22),
('FAQ', 'thermal paper rolls, thermal stock, থার্মাল পেপার, থার্মাল রোল, थर्मल पेपर, थर्मल रोल', 'Thermal পেপারের ইনভেন্টরি দেখাও', 'Thermal পেপারের স্টক দেখতে /paperstock Thermal টাইপ করুন।', 23),
('FAQ', 'maplitho paper rolls, maplitho stock, ম্যাপলিথো, ম্যাপলিথো পেপার, मैपलिथो, मैपलिथो पेपर', 'Maplitho পেপারের কত রোল?', 'Maplitho পেপারের তথ্যের জন্য /paperstock Maplitho টাইপ করুন।', 24),
('FAQ', 'metallic paper, metallic rolls, মেটালিক পেপার, মেটালিক রোল, मेटलिक पेपर, मेटलिक रोल', 'Metallic পেপার আছে কি?', 'Metallic পেপারের স্টক জানতে /paperstock Metallic টাইপ করুন।', 25),
('FAQ', 'plastic paper rolls, plastic stock, প্লাস্টিক পেপার, প্লাস্টিক রোল, प्लास्टिक पेपर, प्लास्टिक रोल', 'Plastic পেপার কত?', 'Plastic পেপারের রোল তথ্যের জন্য /paperstock Plastic টাইপ করুন।', 26);

-- 4. BY WIDTH/SIZE
INSERT INTO `ai_agent_knowledge` (`category`, `keywords`, `question`, `answer`, `sort_order`) VALUES
('FAQ', '1500mm rolls, 1500 mm width, ১৫০০ মিমি রোল, ১৫০০ মিমি প্রশস্ত, 1500 मिमी रोल, 1500 मिमी चौड़ा', '1500mm চওড়ার রোল দেখাও', '১৫০০ মিমি প্রস্থের রোল দেখতে /paperstock 1500mm টাইপ করুন।', 30),
('FAQ', '250mm width rolls, 250 mm, ২৫০ মিমি, ২৫০ মিমি প্রশস্ত, 250 मिमी, 250 मिमी चौड़ा', '250mm width-এর কত রোল?', '২৫০ মিমি প্রস্থের রোল তথ্যের জন্য /paperstock 250mm টাইপ করুন।', 31),
('FAQ', 'width above 1000mm, large width rolls, ১০০০ মিমি উপরে, বড় প্রস্থ, 1000 मिमी से ऊपर, बड़ी चौड़ाई', '1000mm এর উপরে কত রোল?', '১০০০ মিমি-এর উপরের রোল দেখতে /paperstock Jumbo টাইপ করুন।', 32),
('FAQ', 'small rolls below 500mm, narrow width rolls, ৫০০ মিমি নিচে, ছোট প্রস্থ, 500 मिमी से कम, छोटी चौड़ाई', 'ছোট রোল (< 500mm) কতগুলো?', '৫০০ মিমি-এর কম প্রস্থের রোলের তথ্যের জন্য /paperstock টাইপ করুন।', 33),
('FAQ', 'width range 500 to 1000, medium width rolls, ৫০০-১০০০ মিমি, মাঝারি প্রস্থ, 500-1000 मिमी, मध्यम चौड़ाई', '500mm থেকে 1000mm এর মধ্যে রোল কত?', '৫০০-১০০০ মিমি প্রস্থের রোলের তথ্যের জন্য /paperstock টাইপ করুন।', 34);

-- 5. BY STATUS
INSERT INTO `ai_agent_knowledge` (`category`, `keywords`, `question`, `answer`, `sort_order`) VALUES
('FAQ', 'job assign status rolls, assigned rolls, job assign stock, জব অ্যাসাইন, জব অ্যাসাইন স্ট্যাটাস, काम असाइन, असाइन किया गया', 'Job Assign স্ট্যাটাসে কত রোল?', 'Job Assign স্ট্যাটাসের রোল দেখতে /paperstock Job Assign টাইপ করুন।', 40),
('FAQ', 'slitting status rolls, slitting process, slitting stock, স্লিটিং, স্লিটিং স্ট্যাটাস, स्लिटिंग, स्लिटिंग स्थिति', 'কোন রোল Slitting-এ আছে?', 'Slitting প্রক্রিয়াধীন রোল দেখতে /paperstock Slitting টাইপ করুন।', 41),
('FAQ', 'in production rolls, running rolls, production stock, প্রোডাকশনে, উৎপাদনে, उत्पादन में, प्रोडक्शन में', 'In Production স্ট্যাটাসের রোল দেখাও', 'In Production রোলের তথ্যের জন্য /paperstock In Production টাইপ করুন।', 42),
('FAQ', 'consumed rolls, used rolls, consumed stock, খরচ হয়েছে, ব্যবহৃত, उपयोग हो चुका, खपत हो चुका', 'Consumed রোল কত?', 'Consumed রোলের তথ্যের জন্য /paperstock Consumed টাইপ করুন।', 43),
('FAQ', 'available rolls, available stock, উপলব্ধ রোল, প্রস্তুত রোল, उपलब्ध रोल, तैयार रोल', 'Available রোল দেখাও', 'Available স্ট্যাটাসের রোল দেখতে /paperstock Available টাইপ করুন।', 44),
('FAQ', 'main status rolls, fresh rolls, main stock, মেইন রোল, মেইন স্ট্যাটাস, मेन रोल, मेन स्थिति', 'Main স্ট্যাটাসের রোল কত?', 'Main স্ট্যাটাসের রোল দেখতে /paperstock Main টাইপ করুন।', 45),
('FAQ', 'stock status rolls, warehouse stock, stock room, স্টকে কী, স্টক রোল, स्टॉक रोल, गोदाम स्टॉक', 'স্টকে কী কী আছে?', 'Stock স্ট্যাটাসের সকল রোল দেখতে /paperstock Stock টাইপ করুন।', 46);

-- 6. BY DATE
INSERT INTO `ai_agent_knowledge` (`category`, `keywords`, `question`, `answer`, `sort_order`) VALUES
('FAQ', 'this month received, monthly received, this month rolls, এই মাসে রিসিভ, এই মাসে এসেছে, इस महीने प्राप्त, इस महीने आया', 'এই মাসে কত রোল এসেছে?', 'এই মাসের রিসিভ রোলের তথ্যের জন্য /paperstock টাইপ করে মাস উল্লেখ করুন।', 50),
('FAQ', 'last week received, weekly received, গত সপ্তাহে রিসিভ, গত সপ্তাহে এসেছে, पिछले हफ्ते प्राप्त, पिछले हफ्ते आया', 'গত সপ্তাহে কয়টি রোল রিসিভ হয়েছে?', 'গত সপ্তাহের রিসিভ রোল দেখতে /paperstock Last Week টাইপ করুন।', 51),
('FAQ', 'today received rolls, today stock, আজকের রিসিভ, আজ এসেছে, आज प्राप्त, आज आया', 'আজকের রিসিভ দেখাও', 'আজকের রিসিভ রোলের তথ্যের জন্য /paperstock Today টাইপ করুন।', 52),
('FAQ', 'this week received, weekly summary, এই সপ্তাহে রিসিভ, এই সপ্তাহে এসেছে, इस सप्ताह प्राप्त, इस हफ्ते आया', 'এই সপ্তাহে কত রোল এসেছে?', 'এই সপ্তাহের রিসিভ রোলের তথ্যের জন্য /paperstock This Week টাইপ করুন।', 53),
('FAQ', 'this year received, yearly received, এই বছরে রিসিভ, এই বছর এসেছে, इस वर्ष प्राप्त, इस साल आया', 'এই বছরের মোট রিসিভ কত?', 'এই বছরের রিসিভ রোলের তথ্যের জন্য /paperstock This Year টাইপ করুন।', 54);

-- 7. SPECIFIC ROLL OR LOT
INSERT INTO `ai_agent_knowledge` (`category`, `keywords`, `question`, `answer`, `sort_order`) VALUES
('FAQ', 'roll search, roll number lookup, specific roll, roll details, slc roll, রোল নম্বর, নির্দিষ্ট রোল, रोल नंबर, विशिष्ट रोल', 'SLC/2025/0351 রোলটি দেখাও', 'নির্দিষ্ট রোল নম্বরের তথ্যের জন্য /paperstock এবং রোল নম্বর টাইপ করুন।', 60),
('FAQ', 'roll details, roll info, roll data, রোল ডিটেলস, রোল তথ্য, रोल जानकारी, रोल विवरण', 'রোল নম্বর 0351-এর ডিটেলস কী?', 'রোল নম্বর দিয়ে সার্চ করতে /paperstock এরপর রোল নম্বর টাইপ করুন।', 61),
('FAQ', 'lot batch search, lot number, batch lookup, লট ব্যাচ, লট নাম্বার, लॉट बैच, लॉट नंबर', 'Lot Batch No LB-2025-001 এর রোলগুলো দেখাও', 'লট ব্যাচ নম্বর দিয়ে সার্চ করতে /paperstock টাইপ করুন।', 62),
('FAQ', 'company roll number, supplier roll, vendor roll, কোম্পানি রোল নম্বর, সাপ্লায়ার রোল, कंपनी रोल नंबर, आपूर्तिकर्ता रोल', 'Company Roll No CR-12345 দেখাও', 'কোম্পানির রোল নম্বর দিয়ে সার্চ করতে /paperstock টাইপ করুন।', 63);

-- 8. FINANCIAL
INSERT INTO `ai_agent_knowledge` (`category`, `keywords`, `question`, `answer`, `sort_order`) VALUES
('FAQ', 'most expensive roll, highest rate roll, max price roll, costly roll, সবচেয়ে দামি রোল, সর্বোচ্চ দাম, महंगा रोल, अधिकतम दर', 'সবচেয়ে দামি রোল কোনটা?', 'সর্বোচ্চ দামের রোলের তথ্যের জন্য /paperstock Rate টাইপ করুন।', 70),
('FAQ', 'average purchase rate, avg rate, average price, গড় purchase rate, গড় মূল্য, औसत खरीद दर, औसत मूल्य', 'পেপার রোলের গড় purchase rate কত?', 'গড় purchase rate জানতে /paperstock Avg Rate টাইপ করুন।', 71),
('FAQ', 'chromo paper rate, chromo price, chromo cost, ক্রোমো পেপারের দাম, ক্রোমো রেট, क्रोमो पेपर रेट, क्रोमो की कीमत', 'Chromo পেপারের দাম কত?', 'Chromo পেপারের রেট জানতে /paperstock Chromo Rate টাইপ করুন।', 72),
('FAQ', 'krishna company rate, krishna purchase rate, krishna price, কৃষ্ণা কোম্পানির রেট, কৃষ্ণা purchase rate, कृष्णा कंपनी रेट, कृष्णा की कीमत', 'Krishna কোম্পানির রোলের purchase rate কী?', 'Krishna কোম্পানির purchase rate জানতে /paperstock Krishna Rate টাইপ করুন।', 73);

-- 9. JUMBO/SLITTING BREAKDOWN
INSERT INTO `ai_agent_knowledge` (`category`, `keywords`, `question`, `answer`, `sort_order`) VALUES
('FAQ', 'jumbo slitting breakdown, jumbo vs slitted, roll breakdown, জাম্বো স্লিটিং ব্রেকডাউন, জাম্বো বনাম স্লিটিং, जंबो स्लिटिंग ब्रेकडाउन, जंबो बनाम स्लिटेड', 'জাম্বো রোল কত এবং স্লিটিং রোল কত?', 'জাম্বো ও স্লিটিং রোলের ব্রেকডাউনের জন্য /paperstock টাইপ করুন।', 80),
('FAQ', 'jumbo slitted comparison, both summary, উভয় ধরণের রোল, উভয় বিবরণ, दोनों प्रकार के रोल, दोनों का विवरण', 'Jumbo এবং Slitted breakdown দাও', '/paperstock কমান্ডটি সম্পূর্ণ ব্রেকডাউন দেখায়। বিস্তারিত জানতে টাইপ করুন।', 81),
('FAQ', 'big rolls small rolls, large small comparison, বড় রোল ছোট রোল, বড় বনাম ছোট, बड़े रोल छोटे रोल, बड़े बनाम छोटे', 'বড় রোল কত, ছোট রোল কত?', 'বড় (জাম্বো) ও ছোট (স্লিটেড) রোলের সংখ্যা জানতে /paperstock টাইপ করুন।', 82);

-- 10. COMBINATION QUERIES
INSERT INTO `ai_agent_knowledge` (`category`, `keywords`, `question`, `answer`, `sort_order`) VALUES
('FAQ', 'krishna chromo rolls, krishna chromo stock, কৃষ্ণা ক্রোমো রোল, কৃষ্ণা ক্রোমো স্টক, कृष्णा क्रोमो रोल, कृष्णा क्रोमो स्टॉक', 'Krishna কোম্পানির Chromo পেপারের কত রোল?', 'Krishna কোম্পানির Chromo পেপারের স্টক জানতে /paperstock Krishna Chromo টাইপ করুন।', 90),
('FAQ', 'austin thermal rolls, austin thermal stock, অস্টিন থার্মাল, অস্টিন থার্মাল রোল, ऑस्टिन थर्मल, ऑस्टिन थर्मल रोल', 'Austin Thermal পেপারের মোট রানিং মিটার?', 'Austin Thermal পেপারের তথ্যের জন্য /paperstock Austin Thermal টাইপ করুন।', 91),
('FAQ', 'navkar pp white, navkar pp white stock, নাভকার পিপি হোয়াইট, नवकार पीपी व्हाइट', 'Navkar-এর PP White রোল কত?', 'Navkar-এর PP White স্টক জানতে /paperstock Navkar PP White টাইপ করুন।', 92),
('FAQ', 'nrgi job assign, nrgi assigned rolls, NRGI জব অ্যাসাইন, एनआरजीआई असाइन', 'NRGI-র Job Assign স্ট্যাটাসে কত রোল?', 'NRGI-র Job Assign স্ট্যাটাসের রোল দেখতে /paperstock NRGI Job Assign টাইপ করুন।', 93),
('FAQ', 'chromo 1500mm, chromo wide rolls, ক্রোমো ১৫০০ মিমি, chromo 1500, क्रोमो 1500 मिमी', 'Chromo পেপারের 1500mm width-এর রোল দেখাও', 'Chromo পেপারের ১৫০০ মিমি প্রস্থের রোল জানতে /paperstock Chromo 1500mm টাইপ করুন।', 94),
('FAQ', 'krishna main status, krishna fresh rolls, কৃষ্ণা মেইন স্ট্যাটাস, कृष्णा मेन स्थिति', 'Krishna কোম্পানির Main স্ট্যাটাসের রোল দেখাও', 'Krishna কোম্পানির Main স্ট্যাটাসের রোল দেখতে /paperstock Krishna Main টাইপ করুন।', 95);

-- 11. EXPORT / REPORT
INSERT INTO `ai_agent_knowledge` (`category`, `keywords`, `question`, `answer`, `sort_order`) VALUES
('FAQ', 'paper stock pdf, pdf export, stock pdf report, পেপার স্টক PDF, PDF এক্সপোর্ট, पेपर स्टॉक PDF, PDF रिपोर्ट', 'Paper Stock PDF এক্সপোর্ট করো', 'PDF এক্সপোর্ট ফিচার শীঘ্রই আসছে। আপাতত /paperstock টাইপ করে পূর্ণ বিবরণ দেখুন।', 100),
('FAQ', 'paper stock excel, excel export, excel report, এক্সেল রিপোর্ট, এক্সেল এক্সপোর্ট, एक्सेल रिपोर्ट, एक्सेल एक्सपोर्ट', 'এক্সেল রিপোর্ট দাও', 'Excel এক্সপোর্ট ফিচার শীঘ্রই আসছে। আপাতত /paperstock টাইপ করুন।', 101),
('FAQ', 'paper stock print, print report, stock print, প্রিন্ট রিপোর্ট, স্টক প্রিন্ট, प्रिंट रिपोर्ट, प्रिंट करें', 'স্টক রিপোর্ট প্রিন্ট করো', 'প্রিন্ট ফিচার শীঘ্রই আসছে। আপাতত /paperstock টাইপ করে ডাটা দেখুন।', 102);
