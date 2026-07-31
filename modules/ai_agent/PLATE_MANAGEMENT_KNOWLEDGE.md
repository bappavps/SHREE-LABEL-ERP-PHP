# AI Agent Knowledge Base: Plate Management (Design Plate Data)

This document outlines the types of natural language queries, data points, and mathematical calculations users can ask the ERP AI Agent regarding the **Plate Management** (`master_plate_data`) module.

---

### 4. Search and History
- **Query:** `/plate "Raneswar 1000" ai job ta ki amra print korechi?` (Have we printed the job "Raneswar 1000"?)
- **Action:** If no plate matches the exact job name, the agent replies: "No sir, we haven't found any plate or job record under 'Raneswar 1000'. It hasn't been printed before, or it's saved under a different name."
- **Query:** `Alexe 300 ar last job kobe hoyeche?` (When was the last job done for Alexe 300?)
- **Action:** Agent searches for plates matching 'Alexe 300' and displays its specifications, including the `Date Received / Last Job` which shows when it was recorded.

---

## 📌 1. Search by Plate Number (প্লেট নম্বর দিয়ে খোঁজ)
Users frequently search for specific plates using their ID or Plate Number.
- "প্লেট নম্বর P-1234 এর ডিটেলস দেখাও" (Show details of plate P-1234)
- "P-1234 প্লেটের সাইজ কত?" (What is the size of plate P-1234?)
- "P-1234 প্লেটের জন্য কোন সিলিন্ডার লাগে?" (Which cylinder is required for P-1234?)
- "P-101 প্লেটটি কবে রিসিভ হয়েছে?" (When was P-101 received?)

## 📌 2. Search by Design/Job Name (জব বা ডিজাইনের নাম দিয়ে খোঁজ)
Searching for plates associated with a specific customer product or job.
- "কোকা-কোলা লেবেলের প্লেট কোনটা?" (Which plate is for the Coca-Cola label?)
- "Mango Juice জবের জন্য প্লেট ডিটেলস দাও" (Give plate details for the Mango Juice job)
- "Amul-এর প্লেটগুলো কী কী?" (What are the plates for Amul?)

## 📌 3. Search by Cylinder Size (সিলিন্ডার সাইজ দিয়ে খোঁজ)
Finding plates compatible with specific magnetic cylinders.
- "104 T সিলিন্ডারের কয়টি প্লেট আছে?" (How many plates are there for the 104 cylinder?)
- "120 সিলিন্ডারের সব প্লেটের লিস্ট দাও" (List all plates for the 120 cylinder)
- "কোন কোন জবে 80 সিলিন্ডার ব্যবহার হয়?" (Which jobs use the 80 cylinder?)

## 📌 4. Search by Paper Type & Size (পেপার টাইপ ও সাইজ)
- "Chromo পেপারের জন্য কোন কোন প্লেট তৈরি আছে?" (Which plates are made for Chromo paper?)
- "PP Clear-এর প্লেটগুলো দেখাও" (Show plates for PP Clear)
- "পেপার সাইজ 200mm এর প্লেটগুলো দেখাও" (Show plates with a paper size of 200mm)

## 📌 5. Color Information Queries (কালার সম্পর্কিত প্রশ্ন)
The table tracks CMYK and up to 5 Special Colors.
- "P-101 প্লেটে মোট কয়টি কালার ব্যবহার হয়েছে?" (How many total colors are used in P-101?)
- "P-123 প্লেটে স্পেশাল কালার (Special Color) কী কী আছে?" (What special colors are in P-123?)
- "কোন প্লেটগুলোতে ৪টির বেশি কালার (CMYK + Special) আছে?" (Which plates have more than 4 colors?)

## 📌 6. Gap & Repeat Dimensions (গ্যাপ এবং রিপিট)
- "P-101 প্লেটের Horizontal Gap (Gap H) এবং Vertical Gap (Gap V) কত?" (What is the Gap H and Gap V of P-101?)
- "P-123 প্লেটের রিপিট (Repeat) ভ্যালু কত?" (What is the repeat value of P-123?)
- "150mm রিপিটের প্লেটগুলো দেখাও" (Show plates with a 150mm repeat)

## 📌 7. Combination Queries (মিশ্রিত প্রশ্ন)
- "104 সিলিন্ডারের Chromo পেপারের প্লেট দেখাও" (Show Chromo paper plates for 104 cylinder)
- "Make By 'Navkar'-এর 120 সিলিন্ডারের প্লেটগুলো কী কী?" (What are the 120 cylinder plates made by Navkar?)

---

## 🧮 Mathematical & Calculation Queries (গাণিতিক প্রশ্ন)

The AI Agent should be able to perform these industry-standard calculations based on Plate Data:

### 1. Total Label Production Calculation (মোট লেবেল প্রোডাকশন)
When a user asks how many labels will be printed from a given roll using a specific plate or job name:
- **Queries (Multi-lingual & Job Name Support):** 
  - 🇧🇩 **Bengali:** "P-101 প্লেট দিয়ে 2000 মিটার রোলে কয়টি লেবেল প্রিন্ট হবে?" / "Mango Juice জবের 2000 মিটারে কত লেবেল পাবো?"
  - 🇮🇳 **Hindi:** "Coca-Cola label job me 2000 meter roll se kitne label print honge?" / "P-101 se 2000 meter me kitne label niklenge?"
  - 🇬🇧 **English:** "How many labels will print from a 2000 meter roll for the Amul job?"
- **Formula:** 
  `Total Labels = (Roll Length in Meters * 1000) / Repeat Value * UPS`
  *(e.g., If Repeat = 150mm, UPS = 4, Roll = 2000m -> (2000 * 1000) / 150 * 4 = 53,333 Labels)*

### 2. Required Running Meters Calculation (প্রয়োজনীয় রানিং মিটার)
When a user wants to print a specific number of labels, how much paper do they need?
- **Queries (Multi-lingual & Job Name Support):** 
  - 🇧🇩 **Bengali:** "Amul লেবেলের ১ লাখ পিস প্রিন্ট করতে কত মিটার পেপার লাগবে?"
  - 🇮🇳 **Hindi:** "Mango Juice ke 1 lakh label print karne ke liye kitne meter paper chahiye?"
  - 🇬🇧 **English:** "How many running meters of paper do I need to print 100,000 labels for P-123?"
- **Formula:** 
  `Required Meters = (Target Labels / UPS) * Repeat Value / 1000`
  *(e.g., Target = 100,000, UPS = 5, Repeat = 200mm -> (100,000 / 5) * 200 / 1000 = 4,000 Meters)*

### 3. Cylinder Teeth / Gear Calculation
If the repeat value is given in MM, calculating the required gear/teeth (assuming 1/8 CP or standard gearing).
- **Query:** "P-101 এর রিপিট 152.4mm, এর জন্য কত দাঁতের (Teeth) সিলিন্ডার লাগবে?"
- **Formula:** (Depends on the press pitch, commonly 3.175mm per tooth for 1/8 CP)
  `Teeth = Repeat Value / 3.175`
  *(e.g., 152.4 / 3.175 = 48 Teeth Cylinder)*

### 4. Total Color Count Calculation
- **Query:** "Blue500-এ মোট কত কালার স্টেশনের প্লেট লাগবে?"
- **Logic:** Count non-empty fields among `c`, `m`, `y`, `k`, `special_1`, `special_2`, `special_3`, `special_4`, `special_5`.

---

## 🤖 System Triggers & Keywords (For Developer)

**Module Activation Keywords:**
`plate`, `প্লেট`, `प्लेट`, `cylinder`, `সিলিন্ডার`, `ups`, `repeat`, `cmyk`, `die`

**Data Points Used from `master_plate_data`:**
- `plate` (Plate No)
- `name` (Job Name)
- `cylinder`
- `paper_type`
- `ups`, `repeat_value` (Core to calculations)
- `gap_h`, `gap_v`
- CMYK + Special 1-5
