<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/settings/routes.php';
require_once __DIR__ . '/auth.php';
require_login();

$connection = Database::getConnection();
$message = '';
$messageType = '';

//check database connection
if (!$connection) {
	die("Database connection failed.");
}

$categories = [];
$categoriesById = [];
try {
	$categoryResult = mysqli_query($connection, 'SELECT id, name, parent_id FROM vp_categories ORDER BY name');
	while ($row = mysqli_fetch_assoc($categoryResult)) {
		$categories[] = $row;
		$categoriesById[(int) $row['id']] = $row;
	}
} catch (mysqli_sql_exception $exception) {
	$categories = [];
}
$topCategories = array_values(array_filter($categories, fn($c) => $c['parent_id'] === null));
$subcategoriesByParent = [];
foreach ($categories as $categoryRow) {
	if ($categoryRow['parent_id'] !== null) {
		$subcategoriesByParent[(int) $categoryRow['parent_id']][] = ['id' => (int) $categoryRow['id'], 'name' => $categoryRow['name']];
	}
}

$brands = [];
$brandsById = [];
$models = [];
try {
	$brandResult = mysqli_query($connection, 'SELECT id, name FROM vp_brands ORDER BY name');
	while ($row = mysqli_fetch_assoc($brandResult)) {
		$brands[] = $row;
		$brandsById[(int) $row['id']] = $row;
	}
	$modelResult = mysqli_query($connection, 'SELECT id, name, brand_id FROM vp_models ORDER BY name');
	while ($row = mysqli_fetch_assoc($modelResult)) {
		$models[] = $row;
	}
} catch (mysqli_sql_exception $exception) {
	$brands = [];
	$models = [];
}
$modelsByBrand = [];
foreach ($models as $modelRow) {
	$modelsByBrand[(int) $modelRow['brand_id']][] = ['id' => (int) $modelRow['id'], 'name' => $modelRow['name']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$sku = trim($_POST['sku'] ?? '');
	$title = trim($_POST['title'] ?? '');
	$categoryId = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
	$subcategoryId = filter_input(INPUT_POST, 'subcategory_id', FILTER_VALIDATE_INT) ?: null;
	$brandId = filter_input(INPUT_POST, 'brand_id', FILTER_VALIDATE_INT);
	$modelId = filter_input(INPUT_POST, 'model_id', FILTER_VALIDATE_INT) ?: null;
	$price1 = trim($_POST['price_1'] ?? '');
	$price2 = trim($_POST['price_2'] ?? '');
	$quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
	$warehouseId = filter_input(INPUT_POST, 'warehouse_id', FILTER_VALIDATE_INT);
	$location = trim($_POST['location'] ?? '');
	$skuExists = false;
	if ($sku !== '' && strlen($sku) <= 100) {
		$skuCheck = mysqli_prepare($connection, 'SELECT EXISTS(SELECT 1 FROM vp_products WHERE sku = ?) OR EXISTS(SELECT 1 FROM vp_stock WHERE sku = ?) AS sku_exists');
		mysqli_stmt_bind_param($skuCheck, 'ss', $sku, $sku);
		mysqli_stmt_execute($skuCheck);
		$skuExists = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($skuCheck))['sku_exists'];
	}

	if ($sku === '' || strlen($sku) > 100 || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/', $sku) !== 1 || $skuExists || $title === '' || $categoryId === false || $categoryId < 1 || $brandId === false || $brandId < 1 || $quantity === false || $quantity < 0 || $warehouseId === false || $warehouseId < 1 || $location === '') {
		$message = $skuExists ? 'That SKU is already assigned to another product.' : 'Please complete the required fields with valid values.';
		$messageType = 'error';
	} else {
		$brand = $brandsById[$brandId]['name'] ?? '';
		$category = $categoriesById[$subcategoryId ?? 0]['name'] ?? ($categoriesById[$categoryId]['name'] ?? '');

		mysqli_begin_transaction($connection);

		try {
			$productStatement = mysqli_prepare(
				$connection,
				'INSERT INTO vp_products (sku, title, brand, category, price_1, price_2, category_id, subcategory_id, brand_id, model_id) VALUES (?, ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), ?, ?, ?, ?)'
			);
			mysqli_stmt_bind_param($productStatement, 'ssssssiiii', $sku, $title, $brand, $category, $price1, $price2, $categoryId, $subcategoryId, $brandId, $modelId);
			mysqli_stmt_execute($productStatement);

			$stockStatement = mysqli_prepare(
				$connection,
				'INSERT INTO vp_stock (sku, brand, warehouse_id, current_stock, last_trans_id) VALUES (?, ?, ?, ?, 0)'
			);
			mysqli_stmt_bind_param($stockStatement, 'ssii', $sku, $brand, $warehouseId, $quantity);
			mysqli_stmt_execute($stockStatement);
			$stockId = mysqli_insert_id($connection);

			$movementStatement = mysqli_prepare(
				$connection,
				'INSERT INTO vp_stock_movements (vp_stock_id, sku, brand, warehouse_id, location, movement_type, quantity, running_stock, reason) VALUES (?, ?, ?, ?, ?, \'OPENING_STOCK\', ?, ?, \'Initial product stock\')'
			);
			mysqli_stmt_bind_param($movementStatement, 'issisii', $stockId, $sku, $brand, $warehouseId, $location, $quantity, $quantity);
			mysqli_stmt_execute($movementStatement);
			$movementId = mysqli_insert_id($connection);

			$updateStockStatement = mysqli_prepare($connection, 'UPDATE vp_stock SET last_trans_id = ? WHERE id = ?');
			mysqli_stmt_bind_param($updateStockStatement, 'ii', $movementId, $stockId);
			mysqli_stmt_execute($updateStockStatement);

			mysqli_commit($connection);
			header('Location: index.php?added=1');
			exit;
		} catch (mysqli_sql_exception $exception) {
			mysqli_rollback($connection);
			$message = 'The product could not be saved. Check that the inventory tables are installed.';
			$message .= ' Error: ' . $exception->getMessage();
			$messageType = 'error';
		}
	}
}

if (isset($_GET['added'])) {
	$message = 'Product added to stock successfully.';
	$messageType = 'success';
}

$stockRows = [];
$totalUnits = 0;
$totalStockRecords = 0;
$lowStockItems = 0;
$pageSizeOptions = [10, 25, 50, 100, 200, 500];
$requestedPageSize = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT);
$itemsPerPage = in_array($requestedPageSize, $pageSizeOptions, true) ? $requestedPageSize : 10;
$productSort = ($_GET['sort'] ?? 'newest') === 'oldest' ? 'oldest' : 'newest';
$sortDirection = $productSort === 'oldest' ? 'ASC' : 'DESC';
$currentPage = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$currentPage = $currentPage !== false && $currentPage > 0 ? $currentPage : 1;
$totalPages = 1;
$stockError = '';

try {
	$summaryResult = mysqli_query(
		$connection,
		'SELECT COUNT(*) AS total_records, COALESCE(SUM(current_stock), 0) AS total_units, SUM(current_stock <= 5) AS low_stock_items FROM vp_stock'
	);
	$summary = mysqli_fetch_assoc($summaryResult);
	$totalStockRecords = (int) $summary['total_records'];
	$totalUnits = (int) $summary['total_units'];
	$lowStockItems = (int) $summary['low_stock_items'];
	$totalPages = max(1, (int) ceil($totalStockRecords / $itemsPerPage));
	$currentPage = min($currentPage, $totalPages);
	$offset = ($currentPage - 1) * $itemsPerPage;

	$stockStatement = mysqli_prepare(
		$connection,
		'SELECT s.id, s.sku, s.brand, s.warehouse_id, s.current_stock, s.updated_at, p.title, p.category, p.subcategory_id, p.price_1, p.price_2, m.name AS model, c.name AS subcategory
		 FROM vp_stock s
		 LEFT JOIN vp_products p ON p.sku = s.sku
		 LEFT JOIN vp_categories c ON c.id = p.subcategory_id
		 LEFT JOIN vp_models m ON m.id = p.model_id
		 ORDER BY COALESCE(p.created_at, s.updated_at) ' . $sortDirection . ', p.id ' . $sortDirection . ', s.id ' . $sortDirection . '
		 LIMIT ? OFFSET ?'
	);
	mysqli_stmt_bind_param($stockStatement, 'ii', $itemsPerPage, $offset);
	mysqli_stmt_execute($stockStatement);
	$stockResult = mysqli_stmt_get_result($stockStatement);
	while ($row = mysqli_fetch_assoc($stockResult)) {
		$stockRows[] = $row;
	}
} catch (mysqli_sql_exception $exception) {
	$stockError = 'No inventory data is available yet. Import tables.sql, then add your first product.';
}

function output(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'Stock | Inventory Management';
$heading = 'Stock Pilot';
$subtitle = 'Live inventory overview';
$bodyClass = 'page-index';

require __DIR__ . '/header.php';
?>
		<div class="grid" id="dashboard">
			<section>
				<div class="overview">
					<div class="stat stat-products"><span class="stat-icon"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i></span><span class="stat-label">Products in stock</span><span class="stat-value"><?= $totalStockRecords ?></span></div>
					<div class="stat stat-units"><span class="stat-icon"><i class="fa-solid fa-cart-shopping" aria-hidden="true"></i></span><span class="stat-label">Units on hand</span><span class="stat-value"><?= $totalUnits ?></span></div>
					<div class="stat stat-low"><span class="stat-icon"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span><span class="stat-label">Low stock items</span><span class="stat-value"><?= $lowStockItems ?></span></div>
				</div>
				<div class="flex flex-wrap">
					<div class="px-2 py-2">
					</div>
				</div>
				<section class="panel stock-panel" id="stock-list">
					<div class="panel-header">
						<div class="flex flex-wrap">
							
							<div class=" px-2 py-2">
								<h2>Available stock</h2>
								<span class="count" id="stock-count"><?= $totalStockRecords ?> records</span>
							</div>
							<div class=" px-2 py-2">
								<input type="text" id="stock-search" class="stock-search" placeholder="Search product by SKU or name" autocomplete="off">
							</div>
							<form id="product-sort-form" class="px-2 py-2 stock-sort-control" method="get" action="index.php">
								<div>
									<label for="product-sort">Sort products</label>
									<select id="product-sort" name="sort">
										<option value="oldest" <?= $productSort === 'oldest' ? 'selected' : '' ?>>Old to new</option>
										<option value="newest" <?= $productSort === 'newest' ? 'selected' : '' ?>>New to old</option>
									</select>
								</div>
								<div>
									<label for="products-per-page">Order per page</label>
									<select id="products-per-page" name="per_page">
										<?php foreach ($pageSizeOptions as $pageSizeOption): ?>
											<option value="<?= $pageSizeOption ?>" <?= $itemsPerPage === $pageSizeOption ? 'selected' : '' ?>><?= $pageSizeOption ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</form>
						</div>
					</div>
					<?php if ($stockError !== ''): ?>
						<div class="empty"><?= output($stockError) ?></div>
					<?php elseif (count($stockRows) === 0): ?>
						<div class="empty">Your register is ready. Add the first product using the form.</div>
					<?php else: ?>
						<div class="stock-results-grid ">
							<?php foreach ($stockRows as $stock): ?>
								<div class="stock-result bg-white rounded-lg shadow-md  border border-gray-300">
									<div class="stock-result-heading">
										<span class="product"><?= ucfirst($stock['title'] ?: 'Untitled product') ?></span>
										<div class="stock-actions">
											<button type="button" class="stock-menu-toggle" aria-label="Stock item options" aria-haspopup="menu" aria-expanded="false"><i class="fas fa-ellipsis-v" aria-hidden="true"></i></button>
											<div class="stock-menu" role="menu" hidden>
												<button type="button" class="stock-menu-item view-details" role="menuitem" data-stock-id="<?= (int) $stock['id'] ?>">View details</button>
												<button type="button" class="stock-menu-item edit-product" role="menuitem" data-stock-id="<?= (int) $stock['id'] ?>">Edit</button>
											</div>
										</div>
									</div>
									<div class="stock-result-details">
										<dl class="flex flex-wrap">
											<dt>SKU : </dt>
											<dd><?= output($stock['sku']) ?></dd>
										</dl>
										<dl class="flex flex-wrap">
											<dt>Category : </dt>
											<dd><?= output($stock['category'] ?: 'Uncategorised') ?></dd>
										</dl>
										<dl class="flex flex-wrap">
											<dt>SubCategory : </dt>
											<dd><?= output($stock['subcategory'] ?: 'Uncategorised') ?></dd>
										</dl>
										<dl class="flex flex-wrap">
											<dt>Brand : </dt>
											<dd><?= output($stock['brand'] ?: 'Unbranded') ?></dd>
										</dl>
										<dl class="flex flex-wrap">
											<dt>Model : </dt>
											<dd><?= output($stock['model'] ?: 'Uncategorised') ?></dd>
										</dl>										
										<dl>
											<dt>Warehouse : </dt>
											<dd><span class="warehouse">WH-<?= (int) $stock['warehouse_id'] ?></span></dd>
										</dl>
										<dl class="flex flex-wrap">
											<dt>Units : </dt>
											<dd><span class="quantity <?= (int) $stock['current_stock'] <= 5 ? 'low' : '' ?>"><?= (int) $stock['current_stock'] ?></span></dd>
										</dl>
										<dl class="flex flex-wrap">
											<dt>Price 1 : </dt>
											<dd><?= $stock['price_1'] !== null ? '₹' . number_format((float) $stock['price_1'], 2) : '-' ?></dd>
										</dl>
										<dl class="flex flex-wrap">
											<dt>Price 2 : </dt>
											<dd><?= $stock['price_2'] !== null ? '₹' . number_format((float) $stock['price_2'], 2) : '-' ?></dd>
										</dl>
										<dl class="flex flex-wrap">
											<dt>Updated : </dt>
											<dd><?= date('M j, Y', strtotime($stock['updated_at'])) ?></dd>
										</dl>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
						<?php if ($totalPages > 1): ?>
							<nav class="pagination" id="stock-pagination" aria-label="Stock pages">
								<span>Page <?= $currentPage ?> of <?= $totalPages ?></span>
								<div class="pagination-links">
									<?php if ($currentPage > 1): ?><a class="page-link" href="?sort=<?= output($productSort) ?>&amp;per_page=<?= $itemsPerPage ?>&amp;page=<?= $currentPage - 1 ?>">Previous</a><?php endif; ?>
									<?php for ($page = max(1, $currentPage - 2); $page <= min($totalPages, $currentPage + 2); $page++): ?>
										<a class="page-link <?= $page === $currentPage ? 'current' : '' ?>" href="?sort=<?= output($productSort) ?>&amp;per_page=<?= $itemsPerPage ?>&amp;page=<?= $page ?>"><?= $page ?></a>
									<?php endfor; ?>
									<?php if ($currentPage < $totalPages): ?><a class="page-link" href="?sort=<?= output($productSort) ?>&amp;per_page=<?= $itemsPerPage ?>&amp;page=<?= $currentPage + 1 ?>">Next</a><?php endif; ?>
								</div>
							</nav>
						<?php endif; ?>
					<?php endif; ?>
				</section>
			</section>

			<aside class="panel form-panel" id="stock-in">
				<h2>Add product</h2>
				<p>Create a product and record its opening quantity.</p>
				<button type="button" id="open-add-product-modal">Add product</button>
			</aside>
		</div>

		<div class="modal-overlay" id="add-product-modal" hidden>
			<div class="modal add-product-modal" role="dialog" aria-modal="true" aria-labelledby="add-product-modal-title">
				<div class="modal-header">
					<h2 id="add-product-modal-title">Add product</h2>
					<button type="button" class="modal-close" id="add-product-modal-close" aria-label="Close">&times;</button>
				</div>
				<div class="modal-body">
					<form method="post" id="add-product-form">
					<div class="field"><label for="title">Product name</label><input id="title" name="title" required maxlength="255" value="<?= output($_POST['title'] ?? '') ?>"></div>
					<div class="field"><label for="sku">SKU</label><input id="sku" name="sku" required maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,99}" title="Use letters, numbers, hyphens, or underscores." value="<?= output($_POST['sku'] ?? '') ?>"><small id="sku-feedback" class="sku-feedback" role="status" aria-live="polite"></small></div>
					<div class="field-row">
						<div class="field">
							<label for="brand_id">Brand</label>
							<select id="brand_id" name="brand_id" required>
								<option value="">Select brand</option>
								<?php foreach ($brands as $brandOption): ?>
									<option value="<?= (int) $brandOption['id'] ?>" <?= (isset($_POST['brand_id']) && (int) $_POST['brand_id'] === (int) $brandOption['id']) ? 'selected' : '' ?>><?= output($brandOption['name']) ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="field">
							<label for="model_id">Model</label>
							<select id="model_id" name="model_id">
								<option value="">Select model</option>
							</select>
						</div>
					</div>
					<div class="field-row">
						<div class="field">
							<label for="category_id">Category</label>
							<select id="category_id" name="category_id" required>
								<option value="">Select category</option>
								<?php foreach ($topCategories as $categoryOption): ?>
									<option value="<?= (int) $categoryOption['id'] ?>" <?= (isset($_POST['category_id']) && (int) $_POST['category_id'] === (int) $categoryOption['id']) ? 'selected' : '' ?>><?= output($categoryOption['name']) ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="field">
							<label for="subcategory_id">Subcategory</label>
							<select id="subcategory_id" name="subcategory_id">
								<option value="">Select subcategory</option>
							</select>
						</div>
					</div>
					<div class="field-row">
						<div class="field"><label for="quantity">Opening units</label><input id="quantity" name="quantity" type="number" min="0" required value="<?= output($_POST['quantity'] ?? '0') ?>"></div>
						<div class="field"><label for="warehouse_id">Warehouse ID</label><input id="warehouse_id" name="warehouse_id" type="number" min="1" required value="<?= output($_POST['warehouse_id'] ?? '1') ?>"></div>
					</div>
					<div class="field"><label for="location">Storage location</label><input id="location" name="location" required maxlength="255" placeholder="e.g. Aisle 2, Shelf 4" value="<?= output($_POST['location'] ?? '') ?>"></div>
					<div class="field-row">
						<div class="field"><label for="price_1">Price 1</label><input id="price_1" name="price_1" type="number" min="0" step="0.01" value="<?= output($_POST['price_1'] ?? '') ?>"></div>
						<div class="field"><label for="price_2">Price 2</label><input id="price_2" name="price_2" type="number" min="0" step="0.01" value="<?= output($_POST['price_2'] ?? '') ?>"></div>
					</div>
					<button type="submit">Add to stock</button>
					</form>
				</div>
			</div>
		</div>

		<div class="modal-overlay" id="product-modal" hidden>
			<div class="modal" role="dialog" aria-modal="true" aria-labelledby="product-modal-title">
				<div class="modal-header">
					<h2 id="product-modal-title">Product details</h2>
					<button type="button" class="modal-close" id="product-modal-close" aria-label="Close">&times;</button>
				</div>
				<div class="modal-body" id="product-modal-body">
					<div class="empty">Loading&hellip;</div>
				</div>
			</div>
		</div>
	</main>
	<script>
		const subcategoriesByCategory = <?= json_encode($subcategoriesByParent, JSON_HEX_TAG) ?>;
		const modelsByBrand = <?= json_encode($modelsByBrand, JSON_HEX_TAG) ?>;
		const topCategoriesForEdit = <?= json_encode($topCategories, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
		const brandsForEdit = <?= json_encode($brands, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

		function populateSelect(select, options, placeholder) {
			select.innerHTML = '<option value="">' + placeholder + '</option>';
			options.forEach((option) => {
				const el = document.createElement('option');
				el.value = option.id;
				el.textContent = option.name;
				select.appendChild(el);
			});
		}

		const categorySelect = document.getElementById('category_id');
		const subcategorySelect = document.getElementById('subcategory_id');
		const brandSelect = document.getElementById('brand_id');
		const modelSelect = document.getElementById('model_id');
		const submittedSubcategoryId = <?= json_encode((string) ($_POST['subcategory_id'] ?? '')) ?>;
		const submittedModelId = <?= json_encode((string) ($_POST['model_id'] ?? '')) ?>;

		if (categorySelect && subcategorySelect) {
			populateSelect(subcategorySelect, subcategoriesByCategory[categorySelect.value] || [], 'Select subcategory');
			subcategorySelect.value = submittedSubcategoryId;
			categorySelect.addEventListener('change', () => {
				populateSelect(subcategorySelect, subcategoriesByCategory[categorySelect.value] || [], 'Select subcategory');
			});
		}

		if (brandSelect && modelSelect) {
			populateSelect(modelSelect, modelsByBrand[brandSelect.value] || [], 'Select model');
			modelSelect.value = submittedModelId;
			brandSelect.addEventListener('change', () => {
				populateSelect(modelSelect, modelsByBrand[brandSelect.value] || [], 'Select model');
			});
		}

		const productNameInput = document.getElementById('title');
		const skuInput = document.getElementById('sku');
		const skuFeedback = document.getElementById('sku-feedback');
		let skuManuallyEdited = skuInput.value.trim() !== '';
		let skuRequestId = 0;
		let skuCheckTimer = null;

		function setSkuFeedback(message, state = '') {
			skuFeedback.textContent = message;
			skuFeedback.className = 'sku-feedback' + (state ? ' ' + state : '');
			skuInput.setCustomValidity(state === 'invalid' ? message : '');
		}

		function generateSkuFromName() {
			if (skuManuallyEdited) {
				return;
			}

			clearTimeout(skuCheckTimer);
			const base = productNameInput.value.normalize('NFKD')
				.replace(/[\u0300-\u036f]/g, '')
				.toUpperCase()
				.replace(/[^A-Z0-9]+/g, '-')
				.replace(/^-+|-+$/g, '')
				.slice(0, 100);
			const requestId = ++skuRequestId;
			if (!base) {
				skuInput.value = '';
				setSkuFeedback('');
				return;
			}

			skuInput.value = base;
			setSkuFeedback('Checking SKU availability...', 'checking');
			skuCheckTimer = setTimeout(() => {
				fetch('common_function.php?sku_suggest=' + encodeURIComponent(base))
					.then((response) => response.json())
					.then((data) => {
						if (requestId !== skuRequestId || skuManuallyEdited) {
							return;
						}
						if (!data.success) {
							setSkuFeedback(data.message || 'Could not suggest an SKU. Enter one manually.', 'invalid');
							return;
						}
						skuInput.value = data.sku;
						setSkuFeedback('Available', 'available');
					})
					.catch(() => {
						if (requestId === skuRequestId) {
							setSkuFeedback('Could not verify SKU availability.', 'invalid');
						}
					});
			}, 250);
		}

		function validateManualSku() {
			const sku = skuInput.value.trim();
			const requestId = ++skuRequestId;
			clearTimeout(skuCheckTimer);
			if (!sku) {
				skuManuallyEdited = false;
				generateSkuFromName();
				return;
			}

			const skuPattern = /^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/;
			if (!skuPattern.test(sku)) {
				setSkuFeedback('Use letters, numbers, hyphens, or underscores.', 'invalid');
				return;
			}

			setSkuFeedback('Checking SKU availability...', 'checking');
			skuCheckTimer = setTimeout(() => {
				fetch('common_function.php?sku_check=' + encodeURIComponent(sku))
					.then((response) => response.json())
					.then((data) => {
						if (requestId !== skuRequestId) {
							return;
						}
						setSkuFeedback(data.available ? 'Available' : 'This SKU is already in use.', data.available ? 'available' : 'invalid');
					})
					.catch(() => {
						if (requestId === skuRequestId) {
							setSkuFeedback('Could not verify SKU availability.', 'invalid');
						}
					});
			}, 250);
		}

		productNameInput.addEventListener('input', generateSkuFromName);
		skuInput.addEventListener('input', () => {
			skuManuallyEdited = skuInput.value.trim() !== '';
			validateManualSku();
		});
		if (skuManuallyEdited) {
			validateManualSku();
		} else if (productNameInput.value.trim() !== '') {
			generateSkuFromName();
		}

		const stockSearchInput = document.getElementById('stock-search');
		const stockResultsGrid = document.querySelector('.stock-results-grid');
		const stockCount = document.getElementById('stock-count');
		const stockPagination = document.getElementById('stock-pagination');
		const productSort = document.getElementById('product-sort');
		const productsPerPage = document.getElementById('products-per-page');
		const productSortForm = document.getElementById('product-sort-form');

		if (productSortForm) {
			[productSort, productsPerPage].forEach((control) => {
				if (control) {
					control.addEventListener('change', () => productSortForm.submit());
				}
			});
		}

		if (stockSearchInput && stockResultsGrid) {
			const originalRowsHtml = stockResultsGrid.innerHTML;
			const originalCountText = stockCount ? stockCount.textContent : '';
			let searchTimer = null;

			function formatCurrency(value) {
				return value === null ? '-' : '₹' + Number(value).toFixed(2);
			}

			function formatDate(value) {
				const date = new Date(value.replace(' ', 'T'));
				return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
			}

			function escapeHtml(value) {
				const div = document.createElement('div');
				div.textContent = value ?? '';
				return div.innerHTML;
			}

			function renderRows(rows) {
				if (rows.length === 0) {
					stockResultsGrid.innerHTML = '<div class="empty">No products matched your search.</div>';
					return;
				}

				stockResultsGrid.innerHTML = rows.map((stock) => {
					const lowClass = stock.current_stock <= 5 ? 'low' : '';
					return '<div class="stock-result">'
						+ '<div class="stock-result-heading"><span class="product">' + escapeHtml(stock.title || 'Untitled product') + '</span>'
						+ '<div class="stock-actions"><button type="button" class="stock-menu-toggle" aria-label="Stock item options" aria-haspopup="menu" aria-expanded="false"><i class="fas fa-ellipsis-v" aria-hidden="true"></i></button>'
						+ '<div class="stock-menu" role="menu" hidden><button type="button" class="stock-menu-item view-details" role="menuitem" data-stock-id="' + stock.id + '">View details</button><button type="button" class="stock-menu-item edit-product" role="menuitem" data-stock-id="' + stock.id + '">Edit</button></div></div></div>'
						+ '<div class="stock-result-details">'
						+ '<dl><dt>SKU</dt><dd>' + escapeHtml(stock.sku) + '</dd></dl>'
						+ '<dl><dt>Category</dt><dd>' + escapeHtml(stock.category || 'Uncategorised') + '</dd></dl>'
						+ '<dl><dt>Warehouse</dt><dd><span class="warehouse">WH-' + stock.warehouse_id + '</span></dd></dl>'
						+ '<dl><dt>Units</dt><dd><span class="quantity ' + lowClass + '">' + stock.current_stock + '</span></dd></dl>'
						+ '<dl><dt>Price 1</dt><dd>' + formatCurrency(stock.price_1) + '</dd></dl>'
						+ '<dl><dt>Price 2</dt><dd>' + formatCurrency(stock.price_2) + '</dd></dl>'
						+ '<dl><dt>Updated</dt><dd>' + formatDate(stock.updated_at) + '</dd></dl>'
						+ '</div></div>';
				}).join('');
			}

			function restoreOriginal() {
				stockResultsGrid.innerHTML = originalRowsHtml;
				if (stockCount) {
					stockCount.textContent = originalCountText;
				}
				if (stockPagination) {
					stockPagination.hidden = false;
				}
			}

			stockSearchInput.addEventListener('input', () => {
				console.log('Search term:', stockSearchInput.value);
				const term = stockSearchInput.value.trim();

				clearTimeout(searchTimer);

				if (term.length === 0) {
					restoreOriginal();
					return;
				}

				if (term.length < 3) {
					return;
				}

				searchTimer = setTimeout(() => {
					fetch('common_function.php?q=' + encodeURIComponent(term) + '&sort=' + encodeURIComponent(productSort ? productSort.value : 'newest') + '&per_page=' + encodeURIComponent(productsPerPage ? productsPerPage.value : '10'))
						.then((response) => response.json())
						.then((data) => {
							if (!data.success) {
								stockResultsGrid.innerHTML = '<div class="empty">Search is unavailable right now.</div>';
								return;
							}
							renderRows(data.rows);
							if (stockCount) {
								stockCount.textContent = data.rows.length + ' records';
							}
							if (stockPagination) {
								stockPagination.hidden = true;
							}
						})
						.catch(() => {
							stockResultsGrid.innerHTML = '<div class="empty">Search is unavailable right now.</div>';
						});
				}, 250);
			});
		}
		

		const productModal = document.getElementById('product-modal');
		const productModalBody = document.getElementById('product-modal-body');
		const productModalClose = document.getElementById('product-modal-close');
		const addProductModal = document.getElementById('add-product-modal');
		const addProductOpen = document.getElementById('open-add-product-modal');
		const addProductClose = document.getElementById('add-product-modal-close');

		function openAddProductModal() {
			addProductModal.hidden = false;
			document.body.style.overflow = 'hidden';
			document.getElementById('title').focus();
		}

		function closeAddProductModal() {
			addProductModal.hidden = true;
			document.body.style.overflow = '';
		}

		if (addProductOpen) {
			addProductOpen.addEventListener('click', openAddProductModal);
		}
		if (addProductClose) {
			addProductClose.addEventListener('click', closeAddProductModal);
		}
		if (addProductModal) {
			addProductModal.addEventListener('click', (event) => {
				if (event.target === addProductModal) {
					closeAddProductModal();
				}
			});
		}
		if (<?= json_encode(isset($_GET['add_product']) || ($_SERVER['REQUEST_METHOD'] === 'POST' && $messageType === 'error')) ?>) {
			openAddProductModal();
		}

		function formatModalCurrency(value) {
			return value === null || value === undefined ? '-' : '₹' + Number(value).toFixed(2);
		}

		function formatModalDate(value) {
			if (!value) {
				return '-';
			}
			const date = new Date(value.replace(' ', 'T'));
			return date.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
		}

		function escapeModalHtml(value) {
			const div = document.createElement('div');
			div.textContent = value ?? '';
			return div.innerHTML;
		}

		function openProductModal() {
			productModal.hidden = false;
			document.body.style.overflow = 'hidden';
		}

		function closeProductModal() {
			productModal.hidden = true;
			document.body.style.overflow = '';
		}

		function renderProductModal(product) {
			document.getElementById('product-modal-title').textContent = 'Product details';
			const movements = (product.recent_movements || []).map((movement) => (
				'<tr>'
				+ '<td>' + formatModalDate(movement.created_at) + '</td>'
				+ '<td><span class="movement-type ' + escapeModalHtml(movement.movement_type) + '">' + escapeModalHtml(movement.movement_type) + '</span></td>'
				+ '<td>' + movement.quantity + '</td>'
				+ '<td>' + movement.running_stock + '</td>'
				+ '<td>' + escapeModalHtml(movement.reason || '-') + '</td>'
				+ '</tr>'
			)).join('');

			productModalBody.innerHTML = ''
				+ '<div class="product-summary">'
				+ '<div><span class="stat-label">Product</span><span class="stat-value">' + escapeModalHtml(product.title || 'Untitled product') + '</span></div>'
				+ '<div><span class="stat-label">SKU</span><span class="stat-value">' + escapeModalHtml(product.sku) + '</span></div>'
				+ '<div><span class="stat-label">Warehouse</span><span class="stat-value">WH-' + product.warehouse_id + '</span></div>'
				+ '<div><span class="stat-label">Available units</span><span class="stat-value">' + product.current_stock + '</span></div>'
				+ '<div><span class="stat-label">Price 1</span><span class="stat-value">' + formatModalCurrency(product.price_1) + '</span></div>'
				+ '<div><span class="stat-label">Price 2</span><span class="stat-value">' + formatModalCurrency(product.price_2) + '</span></div>'
				+ '</div>'
				+ '<table>'
				+ '<tbody>'
				+ '<tr><th>Brand</th><td>' + escapeModalHtml(product.brand || '-') + '</td></tr>'
				+ '<tr><th>Category</th><td>' + escapeModalHtml(product.category || '-') + '</td></tr>'
				+ '<tr><th>Subcategory</th><td>' + escapeModalHtml(product.subcategory || '-') + '</td></tr>'
				+ '<tr><th>Model</th><td>' + escapeModalHtml(product.model || '-') + '</td></tr>'
				+ '<tr><th>HSN</th><td>' + escapeModalHtml(product.hsn || '-') + '</td></tr>'
				+ '<tr><th>GST</th><td>' + (product.gst !== null ? product.gst + '%' : '-') + '</td></tr>'
				+ '<tr><th>Description</th><td>' + escapeModalHtml(product.description || product.snippet_description || '-') + '</td></tr>'
				+ '<tr><th>Last updated</th><td>' + formatModalDate(product.updated_at) + '</td></tr>'
				+ '</tbody>'
				+ '</table>'
				+ '<h2 style="margin-top: 22px;">Recent movements</h2>'
				+ (movements === '' ? '<div class="empty">No stock movements recorded yet.</div>' : (
					'<div class="movements-table-container"><table class="movements-table"><thead><tr><th>Date</th><th>Type</th><th>Quantity</th><th>Running stock</th><th>Reason</th></tr></thead><tbody>' + movements + '</tbody></table></div>'
				));
		}

		function renderProductEditForm(product) {
			document.getElementById('product-modal-title').textContent = 'Edit product';
			productModalBody.innerHTML = ''
				+ '<form id="edit-product-form">'
				+ '<input type="hidden" name="action" value="update_product">'
				+ '<input type="hidden" name="stock_id" value="' + product.id + '">'
				+ '<div class="field"><label for="edit-title">Product name</label><input id="edit-title" name="title" maxlength="255" required value="' + escapeModalHtml(product.title || '') + '"></div>'
				+ '<div class="field"><label for="edit-sku">SKU</label><input id="edit-sku" name="sku" maxlength="100" required value="' + escapeModalHtml(product.sku || '') + '"></div>'
				+ '<div class="field-row">'
				+ '<div class="field"><label for="edit-brand">Brand</label><select id="edit-brand" name="brand_id" required></select></div>'
				+ '<div class="field"><label for="edit-model">Model</label><select id="edit-model" name="model_id"><option value="">Select model</option></select></div>'
				+ '</div>'
				+ '<div class="field-row">'
				+ '<div class="field"><label for="edit-category">Category</label><select id="edit-category" name="category_id" required></select></div>'
				+ '<div class="field"><label for="edit-subcategory">Subcategory</label><select id="edit-subcategory" name="subcategory_id"><option value="">No subcategory</option></select></div>'
				+ '</div>'
				+ '<div class="field-row">'
				+ '<div class="field"><label for="edit-price-1">Price 1</label><input id="edit-price-1" name="price_1" type="number" min="0" step="0.01" value="' + (product.price_1 ?? '') + '"></div>'
				+ '<div class="field"><label for="edit-price-2">Price 2</label><input id="edit-price-2" name="price_2" type="number" min="0" step="0.01" value="' + (product.price_2 ?? '') + '"></div>'
				+ '</div>'
				+ '<div class="edit-form-message" id="edit-product-message" role="status" aria-live="polite"></div>'
				+ '<div class="edit-form-actions"><button type="button" class="edit-cancel">Cancel</button><button type="submit">Save changes</button></div>'
				+ '</form>';

			const editBrandSelect = document.getElementById('edit-brand');
			const editModelSelect = document.getElementById('edit-model');
			const editCategorySelect = document.getElementById('edit-category');
			const editSubcategorySelect = document.getElementById('edit-subcategory');
			populateSelect(editBrandSelect, brandsForEdit, 'Select brand');
			populateSelect(editCategorySelect, topCategoriesForEdit, 'Select category');
			editBrandSelect.value = product.brand_id ?? '';
			editCategorySelect.value = product.category_id ?? '';
			populateSelect(editModelSelect, modelsByBrand[editBrandSelect.value] || [], 'Select model');
			populateSelect(editSubcategorySelect, subcategoriesByCategory[editCategorySelect.value] || [], 'No subcategory');
			editModelSelect.value = product.model_id ?? '';
			editSubcategorySelect.value = product.subcategory_id ?? '';

			editBrandSelect.addEventListener('change', () => {
				populateSelect(editModelSelect, modelsByBrand[editBrandSelect.value] || [], 'Select model');
			});
			editCategorySelect.addEventListener('change', () => {
				populateSelect(editSubcategorySelect, subcategoriesByCategory[editCategorySelect.value] || [], 'No subcategory');
			});

			const editForm = document.getElementById('edit-product-form');
			editForm.querySelector('.edit-cancel').addEventListener('click', closeProductModal);
			editForm.addEventListener('submit', (event) => {
				event.preventDefault();
				const saveButton = editForm.querySelector('button[type="submit"]');
				const message = document.getElementById('edit-product-message');
				saveButton.disabled = true;
				saveButton.textContent = 'Saving...';
				message.textContent = '';

				fetch('common_function.php', { method: 'POST', body: new FormData(editForm) })
					.then((response) => response.json())
					.then((data) => {
						if (!data.success) {
						throw new Error(data.message || 'Product could not be updated.');
						}
						message.textContent = 'Product updated. Refreshing inventory...';
						window.setTimeout(() => window.location.reload(), 500);
					})
					.catch((error) => {
						message.textContent = error.message || 'Product could not be updated.';
						saveButton.disabled = false;
						saveButton.textContent = 'Save changes';
					});
			});
		}

		document.addEventListener('click', (event) => {
			const menuToggle = event.target.closest('.stock-menu-toggle');
			if (menuToggle) {
				const menu = menuToggle.nextElementSibling;
				const shouldOpen = menu.hidden;
				document.querySelectorAll('.stock-menu').forEach((openMenu) => {
					openMenu.hidden = true;
					openMenu.previousElementSibling.setAttribute('aria-expanded', 'false');
				});
				if (shouldOpen) {
					menu.hidden = false;
					menuToggle.setAttribute('aria-expanded', 'true');
				}
				return;
			}

			document.querySelectorAll('.stock-menu:not([hidden])').forEach((openMenu) => {
				openMenu.hidden = true;
				openMenu.previousElementSibling.setAttribute('aria-expanded', 'false');
			});

			const editTrigger = event.target.closest('.edit-product');
			const trigger = editTrigger || event.target.closest('.view-details');
			if (!trigger) {
				return;
			}

			const stockId = trigger.getAttribute('data-stock-id');
			document.getElementById('product-modal-title').textContent = editTrigger ? 'Edit product' : 'Product details';
			productModalBody.innerHTML = '<div class="empty">Loading&hellip;</div>';
			openProductModal();

			fetch('common_function.php?id=' + encodeURIComponent(stockId))
				.then((response) => response.json())
				.then((data) => {
					if (!data.success) {
						productModalBody.innerHTML = '<div class="empty">' + escapeModalHtml(data.message || 'Product not found.') + '</div>';
						return;
					}
					if (editTrigger) {
						renderProductEditForm(data.product);
					} else {
						renderProductModal(data.product);
					}
				})
				.catch(() => {
					productModalBody.innerHTML = '<div class="empty">Could not load product details.</div>';
				});
		});

		if (productModalClose) {
			productModalClose.addEventListener('click', closeProductModal);
		}

		if (productModal) {
			productModal.addEventListener('click', (event) => {
				if (event.target === productModal) {
					closeProductModal();
				}
			});
		}

		document.addEventListener('keydown', (event) => {
			if (event.key === 'Escape') {
				const openMenu = document.querySelector('.stock-menu:not([hidden])');
				if (openMenu) {
					const menuToggle = openMenu.previousElementSibling;
					openMenu.hidden = true;
					menuToggle.setAttribute('aria-expanded', 'false');
					menuToggle.focus();
				}
				if (productModal && !productModal.hidden) {
					closeProductModal();
				}
				if (addProductModal && !addProductModal.hidden) {
					closeAddProductModal();
				}
			}
		});

		/**@ call stockSearchInput event listener for live search functionality when the page is loaded */
		stockSearchInput.dispatchEvent(new Event('input'));
	</script>
	<?php
	$extraScript = '';
	require __DIR__ . '/footer.php';
	?>
