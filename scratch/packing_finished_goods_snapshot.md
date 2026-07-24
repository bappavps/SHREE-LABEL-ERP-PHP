# Current System Snapshot: Packing, Finished Goods, Dispatch & Jobs
**Timestamp**: 2026-07-24 20:17:15

## 1. Planning Records
| ID | Job No | Job Name | Department | Status | Updated At |
|---|---|---|---|---|---|
| 1 | PLN/2026/0001 | Mewa laya | label-printing |  | 2026-07-24 23:38:27 |
| 2 | PLN-BAR/2026/0001 | Barcode | 100mm X 150mm | 2 Ups | barcode |  | 2026-07-24 23:39:01 |
| 3 | PLN-POS/2026/0001 | POS-7950 | paperroll |  | 2026-07-24 23:35:18 |
| 4 | PLN-1PL/2026/0001 | 75mm - 1Ply | paperroll |  | 2026-07-24 23:36:03 |
| 5 | PLN-2PL/2026/0001 | 210mm-2Ply | paperroll |  | 2026-07-24 23:36:44 |

## 2. Jobs Table (Operator Cards Sent & Status)
| ID | Plan ID | Job No | Department | Job Type | Status | Prev ID | Roll No | Updated At |
|---|---|---|---|---|---|---|---|---|
| 1 | 1 | JMB/2026/0001 | jumbo_slitting | Slitting | Closed | NULL | SLC/2026/0232 | 2026-07-24 23:18:06 |
| 2 | 1 | FLX/2026/0001 | flexo_printing | Printing | Completed | 1 | SLC/2026/0232 | 2026-07-24 23:33:58 |
| 3 | 1 | DCT/2026/0001 | flatbed | Finishing | Completed | 2 | SLC/2026/0232 | 2026-07-24 23:34:45 |
| 4 | 1 | LSL/2026/0001 | label_slitting | Finishing | Completed | 3 | SLC/2026/0232 | 2026-07-24 23:38:56 |
| 6 | 2 | SLT/2026/0001 | jumbo_slitting | Slitting | Closed | NULL | SLC/2026/0233 | 2026-07-24 23:18:36 |
| 7 | 2 | BRC-BAR/2026/0001 | barcode | Finishing | Completed | 6 | SLC/2026/0233 | 2026-07-24 23:37:40 |
| 8 | 2 | LSL/2026/0002 | label_slitting | Finishing | Completed | NULL | SLC/2026/0233 | 2026-07-24 23:39:11 |
| 10 | 3 | SLT/2026/0002 | jumbo_slitting | Slitting | Closed | NULL | SLC/2026/0231 | 2026-07-24 23:18:58 |
| 11 | 3 | PRL-POS/2026/0001 | paperroll | Finishing | Completed | 10 | SLC/2026/0231 | 2026-07-24 23:35:40 |
| 12 | 3 | POS-POS/2026/0001 | pos | Finishing | Completed | 10 | SLC/2026/0231 | 2026-07-24 23:35:40 |
| 14 | 4 | SLT/2026/0003 | jumbo_slitting | Slitting | Closed | NULL | SLC/2026/0196 | 2026-07-24 23:19:20 |
| 15 | 4 | PRL-1PL/2026/0001 | paperroll | Finishing | Completed | 14 | SLC/2026/0196 | 2026-07-24 23:36:15 |
| 16 | 4 | OPL-1PL/2026/0001 | oneply | Finishing | Completed | 14 | SLC/2026/0196 | 2026-07-24 23:36:15 |
| 18 | 5 | SLT/2026/0004 | jumbo_slitting | Slitting | Closed | NULL | SLC/2026/0226 | 2026-07-24 23:19:53 |
| 19 | 5 | PRL-2PL/2026/0001 | paperroll | Finishing | Completed | 18 | SLC/2026/0226 | 2026-07-24 23:36:55 |
| 20 | 5 | TPL-2PL/2026/0001 | twoply | Finishing | Completed | 18 | SLC/2026/0226 | 2026-07-24 23:36:55 |

## 3. Packing Operator Entries (modules/operators/packing)
_No entries found in `packing_operator_entries` table._

## 4. Finished Goods Stock (modules/inventory/finished)
_No records found in `finished_goods_stock` table._

## 5. Finished Goods Dispatch Log
_No dispatch log records found._
