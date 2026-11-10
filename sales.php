<?php
require_once __DIR__ . '/settings/routes.php';
require_once __DIR__ . '/auth.php';
require_login();

$connection = Database::getConnection();
$message = '';
$messageType = '';
$saleRows = [[]];

function output(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if (($_GET['action'] ?? '') === 'search_sku') {
	header('Content-Type: application/json; charset=utf-8');
	$query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';

	if (strlen($query) < 2) {
		echo json_encode(['success' => true, 'rows' => []]);
		exit;
	}

	try {
		$searchStatement = mysqli_prepare(
			$connection,
			'SELECT id, sku, warehouse_id, current_stock FROM vp_stock WHERE current_stock > 0 AND sku LIKE ? ESCAPE \'!\' ORDER BY sku, warehouse_id LIMIT 10'
		);
		$searchTerm = strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
		mysqli_stmt_bind_param($searchStatement, 's', $searchTerm);
		mysqli_stmt_execute($searchStatement);
		$searchResult = mysqli_stmt_get_result($searchStatement);
		$matches = [];
		while ($row = mysqli_fetch_assoc($searchResult)) {
			$matches[] = [
				'id' => (int) $row['id'],
				'sku' => $row['sku'],
				'warehouse_id' => (int) $row['warehouse_id'],
				'current_stock' => (int) $row['current_stock'],
			];
		}
		echo json_encode(['success' => true, 'rows' => $matches], JSON_INVALID_UTF8_SUBSTITUTE);
	} catch (mysqli_sql_exception $exception) {
		error_log('Sales SKU search failed: ' . $exception->getMessage());
		http_response_code(500);
		echo json_encode(['success' => false, 'message' => 'SKU suggestions are temporarily unavailable.']);
	}
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$submittedRows = $_POST['items'] ?? [];
	$saleRows = is_array($submittedRows)
		? array_map(static function ($row): array {
			if (!is_array($row)) {
				return [];
			}

			$fields = [];
			foreach (['stock_id', 'warehouse_id', 'sku', 'sale_price', 'quantity'] as $field) {
				$fields[$field] = is_scalar($row[$field] ?? null) ? (string) $row[$field] : '';
			}
			return $fields;
		}, array_values($submittedRows))
		: [];
	$validatedRows = [];
	$validationError = '';

	if (!auth_verify_csrf($_POST['csrf_token'] ?? null)) {
		$validationError = 'Your session expired. Refresh the page and submit the sale again.';
	} elseif (count($saleRows) < 1 || count($saleRows) > 100) {
		$validationError = 'Add between 1 and 100 sale rows.';
	} else {
		foreach ($saleRows as $row) {
			if (!is_array($row)) {
				$validationError = 'Sale details are invalid. Review the rows and try again.';
				break;
			}

			$stockId = filter_var($row['stock_id'] ?? '', FILTER_VALIDATE_INT);
			$warehouseId = filter_var($row['warehouse_id'] ?? '', FILTER_VALIDATE_INT);
			$skuInput = trim($row['sku'] ?? '');
			$salePrice = trim($row['sale_price'] ?? '');
			$quantityInput = trim($row['quantity'] ?? '');
			$quantity = ctype_digit($quantityInput) ? (int) $quantityInput : 0;
			$validSalePrice = preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $salePrice) === 1;

			if ($stockId === false || $stockId < 1 || $warehouseId === false || $warehouseId < 1 || $skuInput === '' || strlen($skuInput) > 100 || $quantity < 1 || $quantity > 2147483647 || !$validSalePrice) {
				$validationError = 'Select a SKU and enter a valid sale price per item and positive quantity.';
				break;
			}

			$validatedRows[] = [
				'stock_id' => $stockId,
				'warehouse_id' => $warehouseId,
				'sku' => $skuInput,
				'sale_price' => $salePrice,
				'quantity' => $quantity,
			];
		}
	}

	if ($validationError !== '') {
		$message = $validationError;
		$messageType = 'error';
	} else {
		mysqli_begin_transaction($connection);

		try {
			$saleReference = 'SL-' . strtoupper(bin2hex(random_bytes(8)));
			foreach ($validatedRows as $saleRow) {
				$lockStatement = mysqli_prepare(
					$connection,
					'SELECT id, sku, brand, warehouse_id, current_stock FROM vp_stock WHERE id = ? AND sku = ? AND warehouse_id = ? FOR UPDATE'
				);
				mysqli_stmt_bind_param($lockStatement, 'isi', $saleRow['stock_id'], $saleRow['sku'], $saleRow['warehouse_id']);
				mysqli_stmt_execute($lockStatement);
				$stockRow = mysqli_fetch_assoc(mysqli_stmt_get_result($lockStatement));

				if (!$stockRow) {
					throw new RuntimeException('A selected SKU is no longer available. Refresh the page and try again.');
				}

				$stockId = (int) $stockRow['id'];
				$sku = $stockRow['sku'];
				$brand = $stockRow['brand'];
				$warehouseId = (int) $stockRow['warehouse_id'];
				$currentStock = (int) $stockRow['current_stock'];
				$quantity = $saleRow['quantity'];
				if ($quantity > $currentStock) {
					throw new RuntimeException('Not enough units in stock for SKU ' . $sku . '. Available: ' . $currentStock . '.');
				}
				$newStock = $currentStock - $quantity;

				$locationStatement = mysqli_prepare(
					$connection,
					'SELECT location FROM vp_stock_movements WHERE vp_stock_id = ? ORDER BY id DESC LIMIT 1'
				);
				mysqli_stmt_bind_param($locationStatement, 'i', $stockId);
				mysqli_stmt_execute($locationStatement);
				$locationRow = mysqli_fetch_assoc(mysqli_stmt_get_result($locationStatement));
				$location = $locationRow['location'] ?? 'Unspecified';
				$salePrice = $saleRow['sale_price'];

				$movementStatement = mysqli_prepare(
					$connection,
					'INSERT INTO vp_stock_movements (vp_stock_id, sku, brand, warehouse_id, location, movement_type, quantity, running_stock, ref_type, ref_id, reason, sale_price) VALUES (?, ?, ?, ?, ?, \'OUT\', ?, ?, \'SALE\', ?, \'Sale\', ?)'
				);
				mysqli_stmt_bind_param(
					$movementStatement,
					'issisiiss',
					$stockId,
					$sku,
					$brand,
					$warehouseId,
					$location,
					$quantity,
					$newStock,
					$saleReference,
					$salePrice
				);
				mysqli_stmt_execute($movementStatement);
				$movementId = mysqli_insert_id($connection);

				$updateStockStatement = mysqli_prepare(
					$connection,
					'UPDATE vp_stock SET current_stock = ?, last_trans_id = ? WHERE id = ?'
				);
				mysqli_stmt_bind_param($updateStockStatement, 'iii', $newStock, $movementId, $stockId);
				mysqli_stmt_execute($updateStockStatement);
			}

			mysqli_commit($connection);
			header('Location: sales.php?saved=1&ref=' . urlencode($saleReference));
			exit;
		} catch (Throwable $exception) {
			mysqli_rollback($connection);
			if ($exception instanceof mysqli_sql_exception) {
				error_log('Sale could not be saved: ' . $exception->getMessage());
				$message = 'Sale could not be saved because of a database error.';
			} else {
				$message = $exception->getMessage();
			}
			$messageType = 'error';
		}
	}
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
	$saleReference = $_GET['ref'] ?? '';
	$message = 'Sale saved successfully.' . ($saleReference !== '' ? ' Invoice reference: ' . $saleReference : '');
	$messageType = 'success';
}

$pageTitle = 'Sales | Inventory Management';
$heading = 'Sales';
$subtitle = 'Record a multi-item sale';
$bodyClass = 'page-sales';

require __DIR__ . '/header.php';
?>
		<section class="panel">
			<div class="panel-header"><h2>Sale items</h2></div>
			<div class="panel-body">
					<form method="post" action="sales.php" id="sale-form">
						<input type="hidden" name="csrf_token" value="<?= output(auth_csrf_token()) ?>">
						<div class="purchase-rows" id="sale-rows">
							<?php foreach ($saleRows as $index => $saleRow): ?>
								<div class="purchase-row" data-purchase-row>
									<div class="field purchase-sku-field">
										<label for="sale-sku-<?= (int) $index ?>">SKU</label>
										<input id="sale-sku-<?= (int) $index ?>" class="purchase-sku-input" name="items[<?= (int) $index ?>][sku]" type="text" maxlength="100" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="sale-suggestions-<?= (int) $index ?>" placeholder="Type at least 2 characters" required value="<?= output((string) ($saleRow['sku'] ?? '')) ?>">
										<input class="purchase-stock-id" name="items[<?= (int) $index ?>][stock_id]" type="hidden" value="<?= output((string) ($saleRow['stock_id'] ?? '')) ?>">
										<input class="purchase-warehouse-id" name="items[<?= (int) $index ?>][warehouse_id]" type="hidden" value="<?= output((string) ($saleRow['warehouse_id'] ?? '')) ?>">
										<small class="purchase-selection"><?= !empty($saleRow['warehouse_id']) ? 'Selected warehouse: WH-' . (int) $saleRow['warehouse_id'] : '' ?></small>
										<div class="purchase-suggestions" id="sale-suggestions-<?= (int) $index ?>" role="listbox" aria-live="polite" hidden></div>
									</div>
									<div class="field">
										<label for="sale-price-<?= (int) $index ?>">Sale price / Item</label>
										<input id="sale-price-<?= (int) $index ?>" name="items[<?= (int) $index ?>][sale_price]" type="number" min="0" max="99999999.99" step="0.01" required value="<?= output((string) ($saleRow['sale_price'] ?? '')) ?>">
									</div>
									<div class="field">
										<label for="sale-quantity-<?= (int) $index ?>">Quantity</label>
										<input id="sale-quantity-<?= (int) $index ?>" name="items[<?= (int) $index ?>][quantity]" type="number" min="1" step="1" required value="<?= output((string) ($saleRow['quantity'] ?? '')) ?>">
									</div>
									<button class="purchase-remove" type="button" aria-label="Remove sale row">Remove</button>
								</div>
							<?php endforeach; ?>
						</div>

						<template id="sale-row-template">
							<div class="purchase-row" data-purchase-row>
								<div class="field purchase-sku-field">
									<label for="sale-sku-__INDEX__">SKU</label>
									<input id="sale-sku-__INDEX__" class="purchase-sku-input" name="items[__INDEX__][sku]" type="text" maxlength="100" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="sale-suggestions-__INDEX__" placeholder="Type at least 2 characters" required>
									<input class="purchase-stock-id" name="items[__INDEX__][stock_id]" type="hidden">
									<input class="purchase-warehouse-id" name="items[__INDEX__][warehouse_id]" type="hidden">
									<small class="purchase-selection"></small>
									<div class="purchase-suggestions" id="sale-suggestions-__INDEX__" role="listbox" aria-live="polite" hidden></div>
								</div>
								<div class="field"><label for="sale-price-__INDEX__">Sale price / Item</label><input id="sale-price-__INDEX__" name="items[__INDEX__][sale_price]" type="number" min="0" max="99999999.99" step="0.01" required></div>
								<div class="field"><label for="sale-quantity-__INDEX__">Quantity</label><input id="sale-quantity-__INDEX__" name="items[__INDEX__][quantity]" type="number" min="1" step="1" required></div>
								<button class="purchase-remove" type="button" aria-label="Remove sale row">Remove</button>
							</div>
						</template>

						<div class="purchase-actions">
							<button class="purchase-add" id="purchase-add-row" type="button">Add Row</button>
						</div>
						<section class="invoice-summary" aria-labelledby="invoice-summary-title">
							<h2 id="invoice-summary-title">Invoice summary</h2>
							<div><span>Sale total</span><strong id="sale-total" aria-live="polite">₹0.00</strong></div>
						</section>
						<button class="purchase-save" type="submit">Save sale</button>
					</form>
			</div>
		</section>
	<script>
		const saleRows = document.getElementById('sale-rows');
		const saleRowTemplate = document.getElementById('sale-row-template');
		const saleTotal = document.getElementById('sale-total');
		const saleAddButton = document.getElementById('purchase-add-row');
		let nextSaleRowIndex = saleRows ? saleRows.querySelectorAll('[data-purchase-row]').length : 0;

		function updateSaleTotal() {
			if (!saleRows || !saleTotal) return;
			let totalCents = 0n;
			saleRows.querySelectorAll('[data-purchase-row]').forEach((row) => {
				const cost = row.querySelector('[name$="[sale_price]"]').value;
				const quantity = Number(row.querySelector('[name$="[quantity]"]').value) || 0;
				if (!/^\d+(?:\.\d{0,2})?$/.test(cost) || quantity <= 0) return;
				const [whole, fraction = ''] = cost.split('.');
				const unitCents = BigInt(whole) * 100n + BigInt((fraction + '00').slice(0, 2));
				totalCents += unitCents * BigInt(Math.trunc(quantity));
			});
			const wholeRupees = new Intl.NumberFormat('en-IN').format(totalCents / 100n);
			const paise = String(totalCents % 100n).padStart(2, '0');
			saleTotal.textContent = '₹' + wholeRupees + '.' + paise;
		}

		if (saleRows && saleRowTemplate && saleAddButton) {
			saleAddButton.addEventListener('click', () => {
				if (saleRows.querySelectorAll('[data-purchase-row]').length >= 100) return;
				const rowHtml = saleRowTemplate.innerHTML.replace(/__INDEX__/g, String(nextSaleRowIndex++));
				saleRows.insertAdjacentHTML('beforeend', rowHtml);
				saleRows.lastElementChild.querySelector('.purchase-sku-input').focus();
				saleAddButton.disabled = saleRows.querySelectorAll('[data-purchase-row]').length >= 100;
				updateSaleTotal();
			});

			saleRows.addEventListener('input', updateSaleTotal);
			saleRows.addEventListener('change', updateSaleTotal);
			saleRows.addEventListener('input', (event) => {
				const skuInput = event.target.closest('.purchase-sku-input');
				if (!skuInput) return;

				const row = skuInput.closest('[data-purchase-row]');
				const suggestions = row.querySelector('.purchase-suggestions');
				row.querySelector('.purchase-stock-id').value = '';
				row.querySelector('.purchase-warehouse-id').value = '';
				row.querySelector('.purchase-selection').textContent = '';
				suggestions.replaceChildren();
				suggestions.hidden = true;
				skuInput.setAttribute('aria-expanded', 'false');
				window.clearTimeout(row.skuSearchTimer);
				if (row.skuSearchController) row.skuSearchController.abort();

				const query = skuInput.value.trim();
				if (query.length < 2) return;

				row.skuSearchTimer = window.setTimeout(async () => {
					const controller = new AbortController();
					row.skuSearchController = controller;
					try {
						const response = await fetch('sales.php?action=search_sku&q=' + encodeURIComponent(query), {
							headers: { Accept: 'application/json' },
							signal: controller.signal
						});
						const data = await response.json();
						if (!response.ok || !data.success) {
							throw new Error(data.message || 'SKU suggestions are temporarily unavailable.');
						}
						if (skuInput.value.trim() !== query) return;

						if (data.rows.length === 0) {
							const empty = document.createElement('div');
							empty.className = 'purchase-suggestion-empty';
							empty.textContent = 'No matching SKU found.';
							suggestions.appendChild(empty);
						} else {
							data.rows.forEach((match) => {
								const option = document.createElement('button');
								option.type = 'button';
								option.className = 'purchase-suggestion';
								option.setAttribute('role', 'option');
								option.dataset.stockId = match.id;
								option.dataset.sku = match.sku;
								option.dataset.warehouseId = match.warehouse_id;
								option.dataset.currentStock = match.current_stock;
								option.textContent = match.sku + ' — WH-' + match.warehouse_id + ' (' + match.current_stock + ' available)';
								suggestions.appendChild(option);
							});
						}
						suggestions.hidden = false;
						skuInput.setAttribute('aria-expanded', 'true');
					} catch (error) {
						if (error.name === 'AbortError') return;
						const message = document.createElement('div');
						message.className = 'purchase-suggestion-empty';
						message.textContent = error.message || 'SKU suggestions are temporarily unavailable.';
						suggestions.appendChild(message);
						suggestions.hidden = false;
						skuInput.setAttribute('aria-expanded', 'true');
					}
				}, 250);
			});
			saleRows.addEventListener('click', (event) => {
				const suggestion = event.target.closest('.purchase-suggestion');
				if (suggestion) {
					const row = suggestion.closest('[data-purchase-row]');
					const skuInput = row.querySelector('.purchase-sku-input');
					row.querySelector('.purchase-stock-id').value = suggestion.dataset.stockId;
					row.querySelector('.purchase-warehouse-id').value = suggestion.dataset.warehouseId;
					skuInput.value = suggestion.dataset.sku;
					row.querySelector('.purchase-selection').textContent = 'Selected warehouse: WH-' + suggestion.dataset.warehouseId + ' · Available: ' + suggestion.dataset.currentStock;
					row.querySelector('.purchase-suggestions').hidden = true;
					skuInput.setAttribute('aria-expanded', 'false');
					return;
				}

				const removeButton = event.target.closest('.purchase-remove');
				if (!removeButton) return;
				const rows = saleRows.querySelectorAll('[data-purchase-row]');
				if (rows.length === 1) {
					rows[0].querySelectorAll('input').forEach((input) => { input.value = ''; });
					rows[0].querySelector('.purchase-selection').textContent = '';
					rows[0].querySelector('.purchase-suggestions').hidden = true;
				} else {
					removeButton.closest('[data-purchase-row]').remove();
				}
				saleAddButton.disabled = saleRows.querySelectorAll('[data-purchase-row]').length >= 100;
				updateSaleTotal();
			});
			updateSaleTotal();
		}

		document.addEventListener('click', (event) => {
			if (event.target.closest('.purchase-sku-field')) return;
			document.querySelectorAll('.purchase-sku-field .purchase-suggestions:not([hidden])').forEach((suggestions) => {
				suggestions.hidden = true;
				suggestions.parentElement.querySelector('.purchase-sku-input').setAttribute('aria-expanded', 'false');
			});
		});
	</script>
	<?php
	$extraScript = '';
	require __DIR__ . '/footer.php';
	?>
