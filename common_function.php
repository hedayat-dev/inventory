<?php
require_once __DIR__ . '/settings/routes.php';

/**
 * Reusable product search against vp_stock/vp_products, shared by pages and AJAX endpoints.
 */
class ProductSearch
{
	private $connection;

	public function __construct($connection)
	{
		$this->connection = $connection;
	}

	public function skuExists(string $sku): bool
	{
		$statement = mysqli_prepare(
			$this->connection,
			'SELECT EXISTS(SELECT 1 FROM vp_products WHERE sku = ?) OR EXISTS(SELECT 1 FROM vp_stock WHERE sku = ?) AS sku_exists'
		);
		mysqli_stmt_bind_param($statement, 'ss', $sku, $sku);
		mysqli_stmt_execute($statement);
		return (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($statement))['sku_exists'];
	}

	public function suggestSku(string $base): string
	{
		for ($suffix = 1; $suffix <= 10000; $suffix++) {
			$tail = $suffix === 1 ? '' : '-' . $suffix;
			$candidate = substr($base, 0, 100 - strlen($tail)) . $tail;
			if (!$this->skuExists($candidate)) {
				return $candidate;
			}
		}

		return '';
	}

	public function search(string $term, int $limit = 20, string $sort = 'newest'): array
	{
		$results = [];

		if (trim($term) === '') {
			return $results;
		}

		$likeTerm = '%' . trim($term) . '%';
		$sortDirection = $sort === 'oldest' ? 'ASC' : 'DESC';
		$statement = mysqli_prepare(
			$this->connection,
			'SELECT s.id, s.sku, s.brand, s.warehouse_id, s.current_stock, s.updated_at, p.title, p.category, p.price_1, p.price_2
			 FROM vp_stock s
			 LEFT JOIN vp_products p ON p.sku = s.sku
			 WHERE s.sku LIKE ? OR p.title LIKE ?
			 ORDER BY COALESCE(p.created_at, s.updated_at) ' . $sortDirection . ', p.id ' . $sortDirection . ', s.id ' . $sortDirection . '
			 LIMIT ?'
		);
        //echo $likeTerm;
		mysqli_stmt_bind_param($statement, 'ssi', $likeTerm, $likeTerm, $limit);
		mysqli_stmt_execute($statement);
		$result = mysqli_stmt_get_result($statement);
		while ($row = mysqli_fetch_assoc($result)) {
			$row['id'] = (int) $row['id'];
			$row['warehouse_id'] = (int) $row['warehouse_id'];
			$row['current_stock'] = (int) $row['current_stock'];
			$row['price_1'] = $row['price_1'] !== null ? (float) $row['price_1'] : null;
			$row['price_2'] = $row['price_2'] !== null ? (float) $row['price_2'] : null;
			$results[] = $row;
		}

		return $results;
	}

	public function getDetails(int $stockId): ?array
	{
		$statement = mysqli_prepare(
			$this->connection,
			'SELECT s.id, s.sku, s.brand, s.warehouse_id, s.current_stock, s.last_trans_id, s.updated_at,
			        p.id AS product_id, p.category_id, p.subcategory_id, p.brand_id, p.model_id,
			        p.title, p.description, p.category, p.hsn, p.gst, p.snippet_description, p.price_1, p.price_2,
			        m.name AS model, sc.name AS subcategory
			 FROM vp_stock s
			 LEFT JOIN vp_products p ON p.sku = s.sku
			 LEFT JOIN vp_models m ON m.id = p.model_id
			 LEFT JOIN vp_categories sc ON sc.id = p.subcategory_id
			 WHERE s.id = ?'
		);
		mysqli_stmt_bind_param($statement, 'i', $stockId);
		mysqli_stmt_execute($statement);
		$row = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

		if (!$row) {
			return null;
		}

		$row['id'] = (int) $row['id'];
		$row['product_id'] = $row['product_id'] !== null ? (int) $row['product_id'] : null;
		$row['warehouse_id'] = (int) $row['warehouse_id'];
		$row['current_stock'] = (int) $row['current_stock'];
		$row['category_id'] = $row['category_id'] !== null ? (int) $row['category_id'] : null;
		$row['subcategory_id'] = $row['subcategory_id'] !== null ? (int) $row['subcategory_id'] : null;
		$row['brand_id'] = $row['brand_id'] !== null ? (int) $row['brand_id'] : null;
		$row['model_id'] = $row['model_id'] !== null ? (int) $row['model_id'] : null;
		$row['price_1'] = $row['price_1'] !== null ? (float) $row['price_1'] : null;
		$row['price_2'] = $row['price_2'] !== null ? (float) $row['price_2'] : null;

		$historyStatement = mysqli_prepare(
			$this->connection,
			'SELECT movement_type, quantity, running_stock, location, reason, created_at
			 FROM vp_stock_movements
			 WHERE vp_stock_id = ?
			 ORDER BY id DESC
			 LIMIT 5'
		);
		mysqli_stmt_bind_param($historyStatement, 'i', $stockId);
		mysqli_stmt_execute($historyStatement);
		$historyResult = mysqli_stmt_get_result($historyStatement);
		$row['recent_movements'] = [];
		while ($movement = mysqli_fetch_assoc($historyResult)) {
			$movement['quantity'] = (int) $movement['quantity'];
			$movement['running_stock'] = (int) $movement['running_stock'];
			$row['recent_movements'][] = $movement;
		}

		return $row;
	}
}

// Act as an AJAX endpoint only when this file is requested directly.
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
	require_once __DIR__ . '/auth.php';
	require_login(true);
	header('Content-Type: application/json');

	$response = ['success' => true, 'rows' => []];

	try {
		$connection = Database::getConnection();
		$search = new ProductSearch($connection);

		if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_product') {
			$stockId = filter_var($_POST['stock_id'] ?? '', FILTER_VALIDATE_INT);
			$sku = trim($_POST['sku'] ?? '');
			$title = trim($_POST['title'] ?? '');
			$categoryId = filter_var($_POST['category_id'] ?? '', FILTER_VALIDATE_INT);
			$subcategoryInput = trim($_POST['subcategory_id'] ?? '');
			$subcategoryId = $subcategoryInput === '' ? null : filter_var($subcategoryInput, FILTER_VALIDATE_INT);
			$brandId = filter_var($_POST['brand_id'] ?? '', FILTER_VALIDATE_INT);
			$modelInput = trim($_POST['model_id'] ?? '');
			$modelId = $modelInput === '' ? null : filter_var($modelInput, FILTER_VALIDATE_INT);
			$price1 = trim($_POST['price_1'] ?? '');
			$price2 = trim($_POST['price_2'] ?? '');
			$validPrice = static fn(string $price): bool => $price === '' || (is_numeric($price) && (float) $price >= 0);

			if ($stockId === false || $stockId < 1 || $sku === '' || strlen($sku) > 100 || $title === '' || strlen($title) > 255 || $categoryId === false || $categoryId < 1 || $brandId === false || $brandId < 1 || $subcategoryId === false || $modelId === false || !$validPrice($price1) || !$validPrice($price2)) {
				http_response_code(400);
				$response = ['success' => false, 'message' => 'Please complete the required fields with valid values.'];
			} else {
				$details = $search->getDetails($stockId);
				if ($details === null || $details['product_id'] === null) {
					http_response_code(404);
					$response = ['success' => false, 'message' => 'Product not found.'];
				} else {
					$categoryCheck = mysqli_prepare($connection, 'SELECT EXISTS(SELECT 1 FROM vp_categories WHERE id = ? AND parent_id IS NULL) AS valid_category');
					mysqli_stmt_bind_param($categoryCheck, 'i', $categoryId);
					mysqli_stmt_execute($categoryCheck);
					$categoryValid = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($categoryCheck))['valid_category'];

					$subcategoryValid = true;
					if ($subcategoryId !== null) {
						$subcategoryCheck = mysqli_prepare($connection, 'SELECT EXISTS(SELECT 1 FROM vp_categories WHERE id = ? AND parent_id = ?) AS valid_subcategory');
						mysqli_stmt_bind_param($subcategoryCheck, 'ii', $subcategoryId, $categoryId);
						mysqli_stmt_execute($subcategoryCheck);
						$subcategoryValid = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($subcategoryCheck))['valid_subcategory'];
					}

					$brandCheck = mysqli_prepare($connection, 'SELECT name FROM vp_brands WHERE id = ?');
					mysqli_stmt_bind_param($brandCheck, 'i', $brandId);
					mysqli_stmt_execute($brandCheck);
					$brandRow = mysqli_fetch_assoc(mysqli_stmt_get_result($brandCheck));
					$productId = $details['product_id'];
					$duplicateSku = false;
					if ($sku !== $details['sku']) {
						$skuCheck = mysqli_prepare($connection, 'SELECT EXISTS(SELECT 1 FROM vp_stock WHERE sku = ?) OR EXISTS(SELECT 1 FROM vp_products WHERE sku = ?) AS duplicate_sku');
						mysqli_stmt_bind_param($skuCheck, 'ss', $sku, $sku);
						mysqli_stmt_execute($skuCheck);
						$duplicateSku = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($skuCheck))['duplicate_sku'];
					}
					$modelValid = true;
					if ($modelId !== null) {
						$modelCheck = mysqli_prepare($connection, 'SELECT EXISTS(SELECT 1 FROM vp_models WHERE id = ? AND brand_id = ?) AS valid_model');
						mysqli_stmt_bind_param($modelCheck, 'ii', $modelId, $brandId);
						mysqli_stmt_execute($modelCheck);
						$modelValid = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($modelCheck))['valid_model'];
					}

					if ($duplicateSku) {
						http_response_code(409);
						$response = ['success' => false, 'message' => 'That SKU is already assigned to another product.'];
					} elseif (!$categoryValid || !$subcategoryValid || !$brandRow || !$modelValid) {
						http_response_code(400);
						$response = ['success' => false, 'message' => 'Choose a valid brand, model, and category combination.'];
					} else {
						$brand = $brandRow['name'];
						$categoryLookupId = $subcategoryId ?? $categoryId;
						$categoryNameStatement = mysqli_prepare($connection, 'SELECT name FROM vp_categories WHERE id = ?');
						mysqli_stmt_bind_param($categoryNameStatement, 'i', $categoryLookupId);
						mysqli_stmt_execute($categoryNameStatement);
						$categoryRow = mysqli_fetch_assoc(mysqli_stmt_get_result($categoryNameStatement));
						$category = $categoryRow['name'];

						mysqli_begin_transaction($connection);
						try {
							$productStatement = mysqli_prepare(
								$connection,
								'UPDATE vp_products SET sku = ?, title = ?, brand = ?, category = ?, price_1 = NULLIF(?, \'\'), price_2 = NULLIF(?, \'\'), category_id = ?, subcategory_id = ?, brand_id = ?, model_id = ? WHERE id = ?'
							);
							mysqli_stmt_bind_param($productStatement, 'ssssssiiiii', $sku, $title, $brand, $category, $price1, $price2, $categoryId, $subcategoryId, $brandId, $modelId, $productId);
							mysqli_stmt_execute($productStatement);

							$oldSku = $details['sku'];
							$stockStatement = mysqli_prepare($connection, 'UPDATE vp_stock SET sku = ?, brand = ? WHERE sku = ?');
							mysqli_stmt_bind_param($stockStatement, 'sss', $sku, $brand, $oldSku);
							mysqli_stmt_execute($stockStatement);

							mysqli_commit($connection);
							$response = ['success' => true, 'product' => $search->getDetails($stockId)];
						} catch (Throwable $exception) {
							mysqli_rollback($connection);
							throw $exception;
						}
					}
				}
			}
		} else {
			if (array_key_exists('sku_suggest', $_GET)) {
				$base = strtoupper(trim($_GET['sku_suggest']));
				$base = trim(preg_replace('/[^A-Z0-9]+/', '-', $base), '-');
				if ($base === '') {
					http_response_code(400);
					$response = ['success' => false, 'message' => 'Enter a product name to generate an SKU.'];
				} else {
					$sku = $search->suggestSku(substr($base, 0, 100));
					$response = $sku === ''
						? ['success' => false, 'message' => 'Could not generate an available SKU.']
						: ['success' => true, 'sku' => $sku];
				}
			} elseif (array_key_exists('sku_check', $_GET)) {
				$sku = trim($_GET['sku_check']);
				$available = $sku !== '' && strlen($sku) <= 100 && !$search->skuExists($sku);
				$response = ['success' => true, 'available' => $available];
			} else {
				$stockId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
				if ($stockId) {
					$details = $search->getDetails($stockId);
					if ($details === null) {
						http_response_code(404);
						$response = ['success' => false, 'message' => 'Product not found.'];
					} else {
						$response = ['success' => true, 'product' => $details];
					}
				} else {
					$term = trim($_GET['q'] ?? '');
					$sort = ($_GET['sort'] ?? 'newest') === 'oldest' ? 'oldest' : 'newest';
					$pageSizeOptions = [10, 25, 50, 100, 200, 500];
					$requestedPageSize = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT);
					$limit = in_array($requestedPageSize, $pageSizeOptions, true) ? $requestedPageSize : 10;
					$response['rows'] = $search->search($term, $limit, $sort);
				}
			}
		}
	} catch (Throwable $exception) {
		http_response_code(500);
		$response = ['success' => false, 'message' => 'Request failed. Please try again.'];
	}

	echo json_encode($response);
	exit;
}
