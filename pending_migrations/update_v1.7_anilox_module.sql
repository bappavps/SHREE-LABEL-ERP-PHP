-- ============================================================
-- Migration: v1.7 — Anilox Stock Module
-- Date: 2025-07-25
-- Description: Creates master_anilox_data table and inserts
--              initial 16 anilox stock rows.
-- ============================================================

CREATE TABLE IF NOT EXISTS `master_anilox_data` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sl_no` VARCHAR(50) DEFAULT NULL,
  `anilox_lpi` VARCHAR(80) DEFAULT NULL,
  `anilox_bmc` VARCHAR(80) DEFAULT NULL,
  `stock_qty` VARCHAR(80) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Initial anilox stock data
INSERT INTO `master_anilox_data` (`sl_no`, `anilox_lpi`, `anilox_bmc`, `stock_qty`) VALUES
('1',  '250',  '5.9', '1'),
('2',  '300',  '4.7', '2'),
('3',  '360',  '3.9', '2'),
('4',  '400',  '',    '1'),
('5',  '440',  '3.0', '1'),
('6',  '500',  '',    '1'),
('7',  '550',  '2.7', '2'),
('8',  '600',  '2.5', '2'),
('9',  '700',  '2.0', '2'),
('10', '800',  '2.2', '2'),
('11', '900',  '2.2', '1'),
('12', '950',  '2.0', '1'),
('13', '1000', '2.0', '2'),
('14', '1050', '2.0', '1'),
('15', '1100', '2.0', '1'),
('16', '1100', '2.0', '1');
