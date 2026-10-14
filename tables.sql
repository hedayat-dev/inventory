--
-- Table structure for table `vp_stock`
--

CREATE TABLE `vp_stock` (
  `id` int(11) NOT NULL,
  `sku` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `brand` varchar(50) DEFAULT NULL,
  `size` varchar(100) DEFAULT NULL,
  `color` varchar(100) DEFAULT NULL,
  `warehouse_id` int(11) NOT NULL,
  `current_stock` int(11) NOT NULL,
  `last_trans_id` int(11) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vp_stock_movements`
--

CREATE TABLE `vp_stock_movements` (
  `id` int(11) NOT NULL,
  `vp_stock_id` int(11) NOT NULL,
  `sku` varchar(255) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `size` varchar(100) DEFAULT NULL,
  `color` varchar(100) DEFAULT NULL,
  `warehouse_id` int(11) NOT NULL,
  `location` varchar(255) NOT NULL,
  `movement_type` enum('IN','OUT','TRANSFER_IN','TRANSFER_OUT','OPENING_STOCK') NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `running_stock` int(11) NOT NULL DEFAULT 0,
  `ref_type` varchar(100) DEFAULT NULL,
  `ref_id` varchar(255) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `update_by_user` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
COMMIT;

ALTER TABLE `vp_stock` CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT, add PRIMARY KEY (`id`);
COMMIT;

ALTER TABLE `vp_stock_movements` CHANGE `id` `id` INT(11) NOT NULL AUTO_INCREMENT, add PRIMARY KEY (`id`);
COMMIT;
--
-- Table structure for table `vp_products`
--

CREATE TABLE `vp_products` (
  `id` int(11) NOT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `size` varchar(100) DEFAULT NULL,
  `color` varchar(100) DEFAULT NULL,
  `material` varchar(100) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `gst` int(11) DEFAULT NULL,
  `hsn` varchar(50) DEFAULT NULL,
  `dimensions` varchar(100) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `itemtype` varchar(100) DEFAULT NULL,
  `snippet_description` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `vp_products`
--
ALTER TABLE `vp_products`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `vp_products`
--
ALTER TABLE `vp_products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

--
-- Rename `price` to `price_1` and add `price_2`
--
ALTER TABLE `vp_products`
  CHANGE `price` `price_1` decimal(10,2) DEFAULT NULL,
  ADD COLUMN `price_2` decimal(10,2) DEFAULT NULL AFTER `price_1`;
COMMIT;

-- --------------------------------------------------------

--
-- Table structure for table `vp_categories`
--

CREATE TABLE `vp_categories` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `vp_categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parent_id` (`parent_id`);

ALTER TABLE `vp_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `vp_categories`
  ADD CONSTRAINT `vp_categories_parent_fk` FOREIGN KEY (`parent_id`) REFERENCES `vp_categories` (`id`) ON DELETE CASCADE;
COMMIT;

--
-- Data for table `vp_categories`
--

INSERT INTO `vp_categories` (`name`, `parent_id`) VALUES
  ('Music system', NULL),
  ('Seat', NULL),
  ('Wheal', NULL),
  ('Roof', NULL),
  ('Bulb', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vp_brands`
--

CREATE TABLE `vp_brands` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `vp_brands`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

ALTER TABLE `vp_brands`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

--
-- Data for table `vp_brands`
--

INSERT INTO `vp_brands` (`name`) VALUES
  ('Maruti');

-- --------------------------------------------------------

--
-- Table structure for table `vp_models`
--

CREATE TABLE `vp_models` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `brand_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `vp_models`
  ADD PRIMARY KEY (`id`),
  ADD KEY `brand_id` (`brand_id`);

ALTER TABLE `vp_models`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `vp_models`
  ADD CONSTRAINT `vp_models_brand_fk` FOREIGN KEY (`brand_id`) REFERENCES `vp_brands` (`id`) ON DELETE CASCADE;
COMMIT;

--
-- Data for table `vp_models`
--

INSERT INTO `vp_models` (`name`, `brand_id`) VALUES
  ('Alto 800', (SELECT id FROM (SELECT id FROM vp_brands WHERE name = 'Maruti') AS t)),
  ('Alto K10', (SELECT id FROM (SELECT id FROM vp_brands WHERE name = 'Maruti') AS t));

-- --------------------------------------------------------

--
-- Link `vp_products` to the category, subcategory, brand and model master tables
--

ALTER TABLE `vp_products`
  ADD COLUMN `category_id` int(11) DEFAULT NULL AFTER `category`,
  ADD COLUMN `subcategory_id` int(11) DEFAULT NULL AFTER `category_id`,
  ADD COLUMN `brand_id` int(11) DEFAULT NULL AFTER `brand`,
  ADD COLUMN `model_id` int(11) DEFAULT NULL AFTER `brand_id`;

ALTER TABLE `vp_products`
  ADD KEY `category_id` (`category_id`),
  ADD KEY `subcategory_id` (`subcategory_id`),
  ADD KEY `brand_id` (`brand_id`),
  ADD KEY `model_id` (`model_id`);

-- Login accounts use password_hash() hashes generated by PHP, not plaintext passwords.
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_username_unique` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
