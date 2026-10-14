<?php
require_once __DIR__ . '/settings/routes.php';
require_once __DIR__ . '/auth.php';
require_login();

$connection = Database::getConnection();
$message = '';
$messageType = '';

function output(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$categories = [];
try {
	$categoryResult = mysqli_query($connection, 'SELECT id, name, parent_id FROM vp_categories ORDER BY name');
	while ($row = mysqli_fetch_assoc($categoryResult)) {
		$categories[] = $row;
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
$models = [];
try {
	$brandResult = mysqli_query($connection, 'SELECT id, name FROM vp_brands ORDER BY name');
	while ($row = mysqli_fetch_assoc($brandResult)) {
		$brands[] = $row;
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
	$warehouseId = filter_input(INPUT_POST, 'warehouse_id', FILTER_VALIDATE_INT);
	$quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
	$reason = trim($_POST['reason'] ?? '');

	if ($sku === '' || $warehouseId === false || $warehouseId < 1 || $quantity === false || $quantity < 1) {
		$message = 'Please choose a product and enter a valid quantity to stock out.';
		$messageType = 'error';
	} else {
		mysqli_begin_transaction($connection);

		try {
			$lockStatement = mysqli_prepare(
				$connection,
				'SELECT id, brand, current_stock FROM vp_stock WHERE sku = ? AND warehouse_id = ? FOR UPDATE'
			);
			mysqli_stmt_bind_param($lockStatement, 'si', $sku, $warehouseId);
			mysqli_stmt_execute($lockStatement);
			$stockRow = mysqli_fetch_assoc(mysqli_stmt_get_result($lockStatement));

			if (!$stockRow) {
				throw new RuntimeException('That product is not stocked in the selected warehouse.');
			}

			if ($quantity > (int) $stockRow['current_stock']) {
				throw new RuntimeException('Not enough units in stock. Available: ' . (int) $stockRow['current_stock'] . '.');
			}

			$stockId = (int) $stockRow['id'];
			$brand = $stockRow['brand'];
			$newStock = (int) $stockRow['current_stock'] - $quantity;

			$locationStatement = mysqli_prepare(
				$connection,
				'SELECT location FROM vp_stock_movements WHERE vp_stock_id = ? ORDER BY id DESC LIMIT 1'
			);
			mysqli_stmt_bind_param($locationStatement, 'i', $stockId);
			mysqli_stmt_execute($locationStatement);
			$locationRow = mysqli_fetch_assoc(mysqli_stmt_get_result($locationStatement));
			$location = $locationRow['location'] ?? 'Unspecified';

			$movementStatement = mysqli_prepare(
				$connection,
				'INSERT INTO vp_stock_movements (vp_stock_id, sku, brand, warehouse_id, location, movement_type, quantity, running_stock, reason) VALUES (?, ?, ?, ?, ?, \'OUT\', ?, ?, ?)'
			);
			mysqli_stmt_bind_param($movementStatement, 'issisiis', $stockId, $sku, $brand, $warehouseId, $location, $quantity, $newStock, $reason);
			mysqli_stmt_execute($movementStatement);
			$movementId = mysqli_insert_id($connection);

			$updateStockStatement = mysqli_prepare(
				$connection,
				'UPDATE vp_stock SET current_stock = ?, last_trans_id = ? WHERE id = ?'
			);
			mysqli_stmt_bind_param($updateStockStatement, 'iii', $newStock, $movementId, $stockId);
			mysqli_stmt_execute($updateStockStatement);

			mysqli_commit($connection);
			header('Location: stockout.php?sku=' . urlencode($sku) . '&warehouse_id=' . $warehouseId . '&out=1');
			exit;
		} catch (Throwable $exception) {
			mysqli_rollback($connection);
			$message = 'The stock out could not be completed. ' . $exception->getMessage();
			$messageType = 'error';
		}
	}
}

if (isset($_GET['out'])) {
	$message = 'Stock out recorded successfully.';
	$messageType = 'success';
}

$searchTerm = trim($_GET['q'] ?? '');
$filterCategoryId = filter_input(INPUT_GET, 'category_id', FILTER_VALIDATE_INT) ?: null;
$filterSubcategoryId = filter_input(INPUT_GET, 'subcategory_id', FILTER_VALIDATE_INT) ?: null;
$filterBrandId = filter_input(INPUT_GET, 'brand_id', FILTER_VALIDATE_INT) ?: null;
$filterModelId = filter_input(INPUT_GET, 'model_id', FILTER_VALIDATE_INT) ?: null;
$selectedSku = trim($_GET['sku'] ?? '');
$selectedWarehouseId = filter_input(INPUT_GET, 'warehouse_id', FILTER_VALIDATE_INT);

$hasFilters = $searchTerm !== '' || $filterCategoryId || $filterSubcategoryId || $filterBrandId || $filterModelId;
$searchResults = [];
$searchError = '';
if ($hasFilters) {
	try {
		$conditions = [];
		$paramTypes = '';
		$paramValues = [];

		if ($searchTerm !== '') {
			$likeTerm = '%' . $searchTerm . '%';
			$conditions[] = '(s.sku LIKE ? OR p.title LIKE ?)';
			$paramTypes .= 'ss';
			$paramValues[] = $likeTerm;
			$paramValues[] = $likeTerm;
		}
		if ($filterCategoryId) {
			$conditions[] = 'p.category_id = ?';
			$paramTypes .= 'i';
			$paramValues[] = $filterCategoryId;
		}
		if ($filterSubcategoryId) {
			$conditions[] = 'p.subcategory_id = ?';
			$paramTypes .= 'i';
			$paramValues[] = $filterSubcategoryId;
		}
		if ($filterBrandId) {
			$conditions[] = 'p.brand_id = ?';
			$paramTypes .= 'i';
			$paramValues[] = $filterBrandId;
		}
		if ($filterModelId) {
			$conditions[] = 'p.model_id = ?';
			$paramTypes .= 'i';
			$paramValues[] = $filterModelId;
		}

		$whereClause = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';
		$searchStatement = mysqli_prepare(
			$connection,
			"SELECT s.id, s.sku, s.brand, s.warehouse_id, s.current_stock, p.title, p.category
			 FROM vp_stock s
			 LEFT JOIN vp_products p ON p.sku = s.sku
			 $whereClause
			 ORDER BY p.title, s.warehouse_id
			 LIMIT 30"
		);
		if (count($paramValues) > 0) {
			mysqli_stmt_bind_param($searchStatement, $paramTypes, ...$paramValues);
		}
		mysqli_stmt_execute($searchStatement);
		$searchResult = mysqli_stmt_get_result($searchStatement);
		while ($row = mysqli_fetch_assoc($searchResult)) {
			$searchResults[] = $row;
		}
		if (count($searchResults) === 0) {
			$searchError = 'No products matched your search.';
		}
	} catch (mysqli_sql_exception $exception) {
		$searchError = 'Search is unavailable. Import tables.sql and try again.';
	}
}

$selectedProduct = null;
if ($selectedSku !== '' && $selectedWarehouseId !== false) {
	try {
		$productStatement = mysqli_prepare(
			$connection,
			'SELECT s.id, s.sku, s.brand, s.warehouse_id, s.current_stock, p.title, p.category, p.price_1, p.price_2
			 FROM vp_stock s
			 LEFT JOIN vp_products p ON p.sku = s.sku
			 WHERE s.sku = ? AND s.warehouse_id = ?'
		);
		mysqli_stmt_bind_param($productStatement, 'si', $selectedSku, $selectedWarehouseId);
		mysqli_stmt_execute($productStatement);
		$selectedProduct = mysqli_fetch_assoc(mysqli_stmt_get_result($productStatement));
	} catch (mysqli_sql_exception $exception) {
		$selectedProduct = null;
	}
}

$historyRows = [];
$historyError = '';
if ($selectedSku !== '') {
	try {
		$historyStatement = mysqli_prepare(
			$connection,
			'SELECT * FROM vp_stock_movements WHERE sku = ? ORDER BY created_at DESC, id DESC LIMIT 50'
		);
		mysqli_stmt_bind_param($historyStatement, 's', $selectedSku);
		mysqli_stmt_execute($historyStatement);
		$historyResult = mysqli_stmt_get_result($historyStatement);
		while ($row = mysqli_fetch_assoc($historyResult)) {
			$historyRows[] = $row;
		}
	} catch (mysqli_sql_exception $exception) {
		$historyError = 'History is unavailable. Import tables.sql and try again.';
	}
}
$pageTitle = 'Stock movement | Inventory Management';
$heading = 'Stock movement';
$subtitle = 'Search, deduct, and track';
$bodyClass = 'page-stockout';

require __DIR__ . '/header.php';
?>
		<section class="panel">
			<div class="panel-header"><h2>Search product</h2></div>
			<div class="panel-body">
				<form class="search-form" method="get" action="stock_movement.php">
					<div class="flex flex-wrap gap-2">
						<div><select id="filter_category_id" name="category_id">
							<option value="">All categories</option>
							<?php foreach ($topCategories as $categoryOption): ?>
								<option value="<?= (int) $categoryOption['id'] ?>" <?= $filterCategoryId === (int) $categoryOption['id'] ? 'selected' : '' ?>><?= output($categoryOption['name']) ?></option>
							<?php endforeach; ?>
						</select></div>
						<div class="w-28"><select id="filter_subcategory_id"  name="subcategory_id">
							<option value="">All subcategories</option>
							<?php if ($filterCategoryId && isset($subcategoriesByParent[$filterCategoryId])): ?>
								<?php foreach ($subcategoriesByParent[$filterCategoryId] as $subcategoryOption): ?>
									<option value="<?= (int) $subcategoryOption['id'] ?>" <?= $filterSubcategoryId === (int) $subcategoryOption['id'] ? 'selected' : '' ?>><?= output($subcategoryOption['name']) ?></option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select></div>
						<div><select id="filter_brand_id" name="brand_id">
							<option value="">All brands</option>
							<?php foreach ($brands as $brandOption): ?>
								<option value="<?= (int) $brandOption['id'] ?>" <?= $filterBrandId === (int) $brandOption['id'] ? 'selected' : '' ?>><?= output($brandOption['name']) ?></option>
							<?php endforeach; ?>
						</select></div>
						<div><select id="filter_model_id" name="model_id">
							<option value="">All models</option>
							<?php if ($filterBrandId && isset($modelsByBrand[$filterBrandId])): ?>
								<?php foreach ($modelsByBrand[$filterBrandId] as $modelOption): ?>
									<option value="<?= (int) $modelOption['id'] ?>" <?= $filterModelId === (int) $modelOption['id'] ? 'selected' : '' ?>><?= output($modelOption['name']) ?></option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select></div>
						
						<div><input type="text" name="q" class="record-input w-full" placeholder="Search by SKU or product name" value="<?= output($searchTerm) ?>"></div>
						<div><button type="submit" class="icon-button" title="Search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></button></div>
											
					</div>
				</form>

				<?php if ($hasFilters): ?>
					<?php if ($searchError !== ''): ?>
						<div class="empty"><?= output($searchError) ?></div>
					<?php else: ?>
						<div class="stock-results-grid">	
								<?php foreach ($searchResults as $result): ?>
									<article class="stock-result">
										<div class="stock-result-heading">
											<div><span class="product"><?= output($result['title'] ?: 'Untitled product') ?></span><span class="subtle"><?= output($result['brand'] ?: 'No brand') ?></span></div>
											<span class="quantity <?= (int) $result['current_stock'] <= 5 ? 'low' : '' ?>"><?= (int) $result['current_stock'] ?> units</span>
										</div>
										<dl class="stock-result-details">
											<div><dt>SKU</dt><dd><?= output($result['sku']) ?></dd></div>
											<div><dt>Category</dt><dd><?= output($result['category'] ?: 'Uncategorised') ?></dd></div>
											<div><dt>Warehouse</dt><dd><span class="warehouse">WH-<?= (int) $result['warehouse_id'] ?></span></dd></div>
											<div><a class="bg-green-200 hover:bg-green-300 text-green-800 rounded px-4 py-2" href="stock_movement.php?q=<?= urlencode($searchTerm) ?>&category_id=<?= (int) $filterCategoryId ?>&subcategory_id=<?= (int) $filterSubcategoryId ?>&brand_id=<?= (int) $filterBrandId ?>&model_id=<?= (int) $filterModelId ?>&sku=<?= urlencode($result['sku']) ?>&warehouse_id=<?= (int) $result['warehouse_id'] ?>">Stock Movement</a></div>
										</dl>
									</article>
								<?php endforeach; ?>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</section>

		<?php if ($selectedProduct): ?>
			<!-- <section class="panel">
				<div class="panel-header"><h2>Stock movement</h2></div>
				<div class="panel-body">
					<div class="product-summary">
						<div><span class="stat-label">Product</span><span class="stat-value"><?= output($selectedProduct['title'] ?: 'Untitled product') ?></span></div>
						<div><span class="stat-label">SKU</span><span class="stat-value"><?= output($selectedProduct['sku']) ?></span></div>
						<div><span class="stat-label">Warehouse</span><span class="stat-value">WH-<?= (int) $selectedProduct['warehouse_id'] ?></span></div>
						<div><span class="stat-label">Available units</span><span class="stat-value"><?= (int) $selectedProduct['current_stock'] ?></span></div>
						<div><span class="stat-label">Price 1</span><span class="stat-value"><?= $selectedProduct['price_1'] !== null ? '₹' . number_format((float) $selectedProduct['price_1'], 2) : '-' ?></span></div>
						<div><span class="stat-label">Price 2</span><span class="stat-value"><?= $selectedProduct['price_2'] !== null ? '₹' . number_format((float) $selectedProduct['price_2'], 2) : '-' ?></span></div>
					</div>
					<form method="post" action="stockout.php">
						<input type="hidden" name="sku" value="<?= output($selectedProduct['sku']) ?>">
						<input type="hidden" name="warehouse_id" value="<?= (int) $selectedProduct['warehouse_id'] ?>">
						<div class="field-row">
							<div class="field"><label for="quantity">Quantity to remove</label><input id="quantity" name="quantity" type="number" min="1" max="<?= (int) $selectedProduct['current_stock'] ?>" required></div>
							<div class="field"><label for="reason">Reason</label><input id="reason" name="reason" maxlength="255" placeholder="e.g. Sold, damaged, transferred"></div>
						</div>
						<button class="submit" type="submit">Stock out</button>
					</form>
				</div>
			</section> -->

			<section class="panel" id="history">
				<div class="panel-header"><h2>Movement history</h2><span class="count"><?= count($historyRows) ?> records</span></div>
				<?php if ($historyError !== ''): ?>
					<div class="empty"><?= output($historyError) ?></div>
				<?php elseif (count($historyRows) === 0): ?>
					<div class="empty">No stock movements recorded yet for this product.</div>
				<?php else: ?>
					<table>
						<thead><tr><th>Date</th><th>Type</th><th>Warehouse</th><th>Quantity</th><th>Running stock</th><th>Location</th><th>Reason</th></tr></thead>
						<tbody>
							<?php foreach ($historyRows as $movement): ?>
								<tr>
									<td><?= date('M j, Y g:i A', strtotime($movement['created_at'])) ?></td>
									<td><span class="movement-type <?= output($movement['movement_type']) ?>"><?= output($movement['movement_type']) ?></span></td>
									<td><span class="warehouse">WH-<?= (int) $movement['warehouse_id'] ?></span></td>
									<td><?= (int) $movement['quantity'] ?></td>
									<td><?= (int) $movement['running_stock'] ?></td>
									<td><?= output($movement['location'] ?? '-') ?></td>
									<td><?= output($movement['reason'] ?? '-') ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</section>
		<?php endif; ?>
	</main>
	<script>
		const subcategoriesByCategory = <?= json_encode($subcategoriesByParent, JSON_HEX_TAG) ?>;
		const modelsByBrand = <?= json_encode($modelsByBrand, JSON_HEX_TAG) ?>;

		function populateSelect(select, options, placeholder) {
			select.innerHTML = '<option value="">' + placeholder + '</option>';
			options.forEach((option) => {
				const el = document.createElement('option');
				el.value = option.id;
				el.textContent = option.name;
				select.appendChild(el);
			});
		}

		const filterCategorySelect = document.getElementById('filter_category_id');
		const filterSubcategorySelect = document.getElementById('filter_subcategory_id');
		const filterBrandSelect = document.getElementById('filter_brand_id');
		const filterModelSelect = document.getElementById('filter_model_id');

		if (filterCategorySelect && filterSubcategorySelect) {
			filterCategorySelect.addEventListener('change', () => {
				populateSelect(filterSubcategorySelect, subcategoriesByCategory[filterCategorySelect.value] || [], 'All subcategories');
			});
		}

		if (filterBrandSelect && filterModelSelect) {
			filterBrandSelect.addEventListener('change', () => {
				populateSelect(filterModelSelect, modelsByBrand[filterBrandSelect.value] || [], 'All models');
			});
		}
	</script>
	<?php
	$extraScript = '';
	require __DIR__ . '/footer.php';
	?>