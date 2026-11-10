ALTER TABLE `vp_stock_movements`
  ADD COLUMN `expiry_date` date DEFAULT NULL,
  ADD COLUMN `batch_no` varchar(100) DEFAULT NULL,
  ADD COLUMN `cost_price` decimal(10,2) DEFAULT NULL;
