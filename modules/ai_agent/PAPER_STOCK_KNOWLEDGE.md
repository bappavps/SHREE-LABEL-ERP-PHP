# Paper Stock Module — Knowledge Base Reference

## Module Page
`/modules/paper_stock/index.php`

## Purpose
Paper Stock is the ERP's paper roll inventory management system. All raw material (paper rolls) are stored here — from Jumbo Rolls (large master rolls) to Slitted Rolls (smaller cut rolls).

## Database Table: `paper_stock`

### Column Reference

| Column | Meaning | Example Data |
|---|---|---|
| `roll_no` | Unique roll identifier | `SLC/2025/0351`, `SLC/2025/0351-A` (parent-child) |
| `status` | Current roll status | Main, Stock, Job Assign, Slitting, In Production, Consumed, Available, Assigned |
| `company` | Paper supplier company | Krishna, Austin, Navkar, NRGI, Abhinav, Raj Paper, Mangalam, Paper N More, Narsing Das, Avery, Nitin, Flexo |
| `paper_type` | Type of paper | Chromo, PP White, PP Clear, Thermal Paper, Thermal Board, Maplitho, Metallic, Plastic |
| `width_mm` | Roll width in millimeters | 150, 250, 500, 1000, 1500 |
| `length_mtr` | Running meters of paper | 5000 |
| `sqm` | Square meters (auto-calculated) | `(width_mm/1000) × length_mtr` |
| `gsm` | Grams per square meter (thickness) | 80, 100, 120, 150, 200, 250, 300, 350, 400 |
| `weight_kg` | Roll weight in kg | 250 |
| `purchase_rate` | Purchase price per unit | 120 |
| `date_received` | Date roll was received | YYYY-MM-DD |
| `date_used` | Date roll was used/consumed | YYYY-MM-DD |
| `job_no` | Job number assigned to | JOB-2025-001 |
| `job_size` | Job size specification | 100mm x 150mm |
| `job_name` | Job/product name | Customer/Product name |
| `lot_batch_no` | Supplier's lot/batch number | LB-2025-001 |
| `company_roll_no` | Supplier's own roll number | CR-12345 |
| `remarks` | Additional notes | Special instructions |

### Status Values

| Status | Meaning |
|---|---|
| Main | Fresh, untouched master roll |
| Stock | Stored in warehouse |
| Job Assign | Allocated to a specific job |
| Slitting | Currently being cut/slit |
| In Production | Running on machine |
| Consumed | Fully used up |
| Available | Available for use |
| Assigned | Reserved for specific work |

### Jumbo vs Slitted Classification
- **Jumbo Roll**: `width_mm >= 1000` (large master rolls)
- **Slitted Roll**: `width_mm < 1000` (smaller cut rolls)

---

## Available Filters (as per page)

| Filter | Type | Description |
|---|---|---|
| Global Search | Text | Searches all columns |
| Lot/Batch No | Text | Filter by lot |
| Roll No | Text | Filter by roll number |
| Company | Dropdown | Filter by supplier |
| Paper Type | Dropdown | Filter by paper type |
| GSM | Dropdown | Filter by thickness |
| Status | Dropdown | Filter by status |
| Width Range | Range (min-max) | Filter by width in mm |
| Date From/To | Date picker | Filter by received date |

---

## User Questions — Category Wise

### 1. General Inventory Count
- "মোট কতগুলো পেপার রোল আছে?"
- "স্টকে মোট কত রোল আছে?"
- "সব মিলিয়ে কত রানিং মিটার?"
- "মোট SQM কত?"
- "বর্তমানে কয়টি জাম্বো রোল আছে?"
- "স্লিটিং রোল কতগুলো?"

### 2. By Company
- "Krishna কোম্পানির কত রোল আছে?"
- "Austin পেপারের কত রোল?"
- "Navkar কোম্পানি থেকে কত রোল এসেছে?"
- "NRGI-র মোট রানিং মিটার কত?"
- "Avery-র স্টক দেখাও"
- "Abhinav কোম্পানির সব রোল দেখাও"
- "Raj Paper-এর ইনভেন্টরি কেমন?"

### 3. By Paper Type
- "Chromo পেপারের কত রোল আছে?"
- "PP White স্টকে কত?"
- "PP Clear রোল কতগুলো?"
- "Thermal পেপারের ইনভেন্টরি দেখাও"
- "Maplitho পেপারের কত রোল?"
- "Metallic পেপার আছে কি?"
- "Plastic পেপার কত?"

### 4. By Width/Size
- "1500mm চওড়ার রোল দেখাও"
- "250mm width-এর কত রোল?"
- "1000mm এর উপরে কত রোল?"
- "ছোট রোল (< 500mm) কতগুলো?"
- "500mm থেকে 1000mm এর মধ্যে রোল কত?"

### 5. By Status
- "Job Assign স্ট্যাটাসে কত রোল?"
- "কোন রোল Slitting-এ আছে?"
- "In Production স্ট্যাটাসের রোল দেখাও"
- "Consumed রোল কত?"
- "স্টকে কী কী আছে?"
- "Available রোল দেখাও"
- "Main স্ট্যাটাসের রোল কত?"

### 6. By Date
- "এই মাসে কত রোল এসেছে?"
- "গত সপ্তাহে কয়টি রোল রিসিভ হয়েছে?"
- "আজকের রিসিভ দেখাও"
- "এই সপ্তাহে কত রোল এসেছে?"
- "এই বছরের মোট রিসিভ কত?"

### 7. Specific Roll or Lot
- "SLC/2025/0351 রোলটি দেখাও"
- "রোল নম্বর 0351-এর ডিটেলস কী?"
- "Lot Batch No LB-2025-001 এর রোলগুলো দেখাও"
- "Company Roll No CR-12345 দেখাও"

### 8. Financial
- "সবচেয়ে দামি রোল কোনটা?"
- "পেপার রোলের গড় purchase rate কত?"
- "Chromo পেপারের দাম কত?"
- "Krishna কোম্পানির রোলের purchase rate কী?"

### 9. Jumbo/Slitting Breakdown (Priority Mode)
- "জাম্বো রোল কত এবং স্লিটিং রোল কত?"
- "Jumbo এবং Slitted breakdown দাও"
- "বড় রোল কত, ছোট রোল কত?"

### 10. Combination Queries
- "Krishna কোম্পানির Chromo পেপারের কত রোল?"
- "Austin Thermal পেপারের মোট রানিং মিটার?"
- "Navkar-এর PP White রোল কত?"
- "NRGI-র Job Assign স্ট্যাটাসে কত রোল?"
- "Chromo পেপারের 1500mm width-এর রোল দেখাও"
- "Krishna কোম্পানির Main স্ট্যাটাসের রোল দেখাও"

### 11. Export/Report
- "Paper Stock PDF এক্সপোর্ট করো"
- "এক্সেল রিপোর্ট দাও"
- "স্টক রিপোর্ট প্রিন্ট করো"

---

## KB Training Tips

Add these common combinations as Quick Chip entries:
- `Krishna Chromo` → count of Krishna Chromo rolls
- `Austin Thermal` → Austin Thermal inventory
- `Navkar PP White` → Navkar PP White roll count
- `NRGI Job Assign` → NRGI rolls in Job Assign status
- `total jumbo rolls` → count + breakdown
- `Chromo 1500mm` → Chromo rolls with width 1500mm
