<?php
require_once __DIR__ . '/settings/routes.php';
require_once __DIR__ . '/auth.php';
require_login();

$connection = Database::getConnection();
$message = '';
$messageType = '';
$purchaseRows = [[]];

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
			'SELECT id, sku, warehouse_id FROM vp_stock WHERE sku LIKE ? ESCAPE \'!\' ORDER BY sku, warehouse_id LIMIT 10'
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
			];
		}
		echo json_encode(['success' => true, 'rows' => $matches], JSON_INVALID_UTF8_SUBSTITUTE);
	} catch (mysqli_sql_exception $exception) {
		error_log('Direct purchase SKU search failed: ' . $exception->getMessage());
		http_response_code(500);
		echo json_encode(['success' => false, 'message' => 'SKU suggestions are temporarily unavailable.']);
	}
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$submittedRows = $_POST['items'] ?? [];
	$purchaseRows = is_array($submittedRows)
		? array_map(static function ($row): array {
			if (!is_array($row)) {
				return [];
			}

			$fields = [];
			foreach (['stock_id', 'warehouse_id', 'sku', 'cost_price', 'quantity', 'expiry_date', 'batch_no'] as $field) {
				$fields[$field] = is_scalar($row[$field] ?? null) ? (string) $row[$field] : '';
			}
			return $fields;
		}, array_values($submittedRows))
		: [];
	$validatedRows = [];
	$validationError = '';

	if (!auth_verify_csrf($_POST['csrf_token'] ?? null)) {
		$validationError = 'Your session expired. Refresh the page and submit the purchase again.';
	} elseif (count($purchaseRows) < 1 || count($purchaseRows) > 100) {
		$validationError = 'Add between 1 and 100 purchase rows.';
	} else {
		foreach ($purchaseRows as $row) {
			if (!is_array($row)) {
				$validationError = 'Purchase details are invalid. Review the rows and try again.';
				break;
			}

			$stockId = filter_var($row['stock_id'] ?? '', FILTER_VALIDATE_INT);
			$warehouseId = filter_var($row['warehouse_id'] ?? '', FILTER_VALIDATE_INT);
			$skuInput = trim($row['sku'] ?? '');
			$costPrice = trim($row['cost_price'] ?? '');
			$quantityInput = trim($row['quantity'] ?? '');
			$expiryDateInput = trim($row['expiry_date'] ?? '');
			$batchNo = trim($row['batch_no'] ?? '');
			$quantity = ctype_digit($quantityInput) ? (int) $quantityInput : 0;
			$parsedExpiryDate = $expiryDateInput === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $expiryDateInput);
			$validExpiryDate = $expiryDateInput === '' || ($parsedExpiryDate !== false && $parsedExpiryDate->format('Y-m-d') === $expiryDateInput);
			$validCostPrice = preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $costPrice) === 1;

			if ($stockId === false || $stockId < 1 || $warehouseId === false || $warehouseId < 1 || $skuInput === '' || strlen($skuInput) > 100 || $quantity < 1 || $quantity > 2147483647 || !$validCostPrice || !$validExpiryDate || strlen($batchNo) > 100) {
				$validationError = 'Enter a SKU, a valid cost per item, a positive quantity, a valid expiry date, and a batch number up to 100 characters.';
				break;
			}

			$validatedRows[] = [
				'stock_id' => $stockId,
				'warehouse_id' => $warehouseId,
				'sku' => $skuInput,
				'cost_price' => $costPrice,
				'quantity' => $quantity,
				'expiry_date' => $expiryDateInput === '' ? null : $expiryDateInput,
				'batch_no' => $batchNo === '' ? null : $batchNo,
			];
		}
	}

	if ($validationError !== '') {
		$message = $validationError;
		$messageType = 'error';
	} else {
		mysqli_begin_transaction($connection);

		try {
			$purchaseReference = 'DP-' . strtoupper(bin2hex(random_bytes(8)));
			foreach ($validatedRows as $purchaseRow) {
				$lockStatement = mysqli_prepare(
					$connection,
					'SELECT id, sku, brand, warehouse_id, current_stock FROM vp_stock WHERE id = ? AND sku = ? AND warehouse_id = ? FOR UPDATE'
				);
				mysqli_stmt_bind_param($lockStatement, 'isi', $purchaseRow['stock_id'], $purchaseRow['sku'], $purchaseRow['warehouse_id']);
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
				$quantity = $purchaseRow['quantity'];
				if ($currentStock > 2147483647 - $quantity) {
					throw new RuntimeException('The purchase quantity exceeds the maximum stock value for SKU ' . $sku . '.');
				}
				$newStock = $currentStock + $quantity;

				$locationStatement = mysqli_prepare(
					$connection,
					'SELECT location FROM vp_stock_movements WHERE vp_stock_id = ? ORDER BY id DESC LIMIT 1'
				);
				mysqli_stmt_bind_param($locationStatement, 'i', $stockId);
				mysqli_stmt_execute($locationStatement);
				$locationRow = mysqli_fetch_assoc(mysqli_stmt_get_result($locationStatement));
				$location = $locationRow['location'] ?? 'Unspecified';
				$expiryDate = $purchaseRow['expiry_date'];
				$batchNo = $purchaseRow['batch_no'];
				$costPrice = $purchaseRow['cost_price'];

				$movementStatement = mysqli_prepare(
					$connection,
					'INSERT INTO vp_stock_movements (vp_stock_id, sku, brand, warehouse_id, location, movement_type, quantity, running_stock, ref_type, ref_id, reason, expiry_date, batch_no, cost_price) VALUES (?, ?, ?, ?, ?, \'IN\', ?, ?, \'DIRECT_PURCHASE\', ?, \'Direct purchase\', ?, ?, ?)'
				);
				mysqli_stmt_bind_param(
					$movementStatement,
					'issisiissss',
					$stockId,
					$sku,
					$brand,
					$warehouseId,
					$location,
					$quantity,
					$newStock,
					$purchaseReference,
					$expiryDate,
					$batchNo,
					$costPrice
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
			header('Location: direct_purchase.php?saved=1&ref=' . urlencode($purchaseReference));
			exit;
		} catch (Throwable $exception) {
			mysqli_rollback($connection);
			if ($exception instanceof mysqli_sql_exception) {
				error_log('Direct purchase failed: ' . $exception->getMessage());
				$message = 'Purchase could not be saved because of a database error.';
			} else {
				$message = $exception->getMessage();
			}
			$messageType = 'error';
		}
	}
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
	$purchaseReference = $_GET['ref'] ?? '';
	$message = 'Purchase saved successfully.' . ($purchaseReference !== '' ? ' Invoice reference: ' . $purchaseReference : '');
	$messageType = 'success';
}

$pageTitle = 'Direct Purchase | Inventory Management';
$heading = 'Direct Purchase';
$subtitle = 'Record a multi-item purchase';
$bodyClass = 'page-direct-purchase';

require __DIR__ . '/header.php';
?>
		<section class="panel">
			<div class="panel-header"><h2>Purchase items</h2></div>
			<div class="panel-body">
					<form method="post" action="direct_purchase.php" id="purchase-form">
						<input type="hidden" name="csrf_token" value="<?= output(auth_csrf_token()) ?>">
						<div class="purchase-rows" id="purchase-rows">
							<?php foreach ($purchaseRows as $index => $purchaseRow): ?>
								<div class="purchase-row" data-purchase-row>
									<div class="field purchase-sku-field">
										<label for="purchase-sku-<?= (int) $index ?>">SKU</label>
										<input id="purchase-sku-<?= (int) $index ?>" class="purchase-sku-input" name="items[<?= (int) $index ?>][sku]" type="text" maxlength="100" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="purchase-suggestions-<?= (int) $index ?>" placeholder="Type at least 2 characters" required value="<?= output((string) ($purchaseRow['sku'] ?? '')) ?>">
										<input class="purchase-stock-id" name="items[<?= (int) $index ?>][stock_id]" type="hidden" value="<?= output((string) ($purchaseRow['stock_id'] ?? '')) ?>">
										<input class="purchase-warehouse-id" name="items[<?= (int) $index ?>][warehouse_id]" type="hidden" value="<?= output((string) ($purchaseRow['warehouse_id'] ?? '')) ?>">
										<small class="purchase-selection"><?= !empty($purchaseRow['warehouse_id']) ? 'Selected warehouse: WH-' . (int) $purchaseRow['warehouse_id'] : '' ?></small>
										<div class="purchase-suggestions" id="purchase-suggestions-<?= (int) $index ?>" role="listbox" aria-live="polite" hidden></div>
									</div>
									<div class="field">
										<label for="purchase-cost-<?= (int) $index ?>">Cost / Item</label>
										<input id="purchase-cost-<?= (int) $index ?>" name="items[<?= (int) $index ?>][cost_price]" type="number" min="0" max="99999999.99" step="0.01" required value="<?= output((string) ($purchaseRow['cost_price'] ?? '')) ?>">
									</div>
									<div class="field">
										<label for="purchase-quantity-<?= (int) $index ?>">Quantity</label>
										<input id="purchase-quantity-<?= (int) $index ?>" name="items[<?= (int) $index ?>][quantity]" type="number" min="1" step="1" required value="<?= output((string) ($purchaseRow['quantity'] ?? '')) ?>">
									</div>
									<div class="field">
										<label for="purchase-expiry-<?= (int) $index ?>">Expiry date</label>
										<input id="purchase-expiry-<?= (int) $index ?>" name="items[<?= (int) $index ?>][expiry_date]" type="date" value="<?= output((string) ($purchaseRow['expiry_date'] ?? '')) ?>">
									</div>
									<div class="field">
										<label for="purchase-batch-<?= (int) $index ?>">Batch number</label>
										<input id="purchase-batch-<?= (int) $index ?>" name="items[<?= (int) $index ?>][batch_no]" maxlength="100" value="<?= output((string) ($purchaseRow['batch_no'] ?? '')) ?>">
									</div>
									<button class="purchase-remove" type="button" aria-label="Remove purchase row">Remove</button>
								</div>
							<?php endforeach; ?>
						</div>

						<template id="purchase-row-template">
							<div class="purchase-row" data-purchase-row>
								<div class="field purchase-sku-field">
									<label for="purchase-sku-__INDEX__">SKU</label>
									<input id="purchase-sku-__INDEX__" class="purchase-sku-input" name="items[__INDEX__][sku]" type="text" maxlength="100" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="purchase-suggestions-__INDEX__" placeholder="Type at least 2 characters" required>
									<input class="purchase-stock-id" name="items[__INDEX__][stock_id]" type="hidden">
									<input class="purchase-warehouse-id" name="items[__INDEX__][warehouse_id]" type="hidden">
									<small class="purchase-selection"></small>
									<div class="purchase-suggestions" id="purchase-suggestions-__INDEX__" role="listbox" aria-live="polite" hidden></div>
								</div>
								<div class="field"><label for="purchase-cost-__INDEX__">Cost / Item</label><input id="purchase-cost-__INDEX__" name="items[__INDEX__][cost_price]" type="number" min="0" max="99999999.99" step="0.01" required></div>
								<div class="field"><label for="purchase-quantity-__INDEX__">Quantity</label><input id="purchase-quantity-__INDEX__" name="items[__INDEX__][quantity]" type="number" min="1" step="1" required></div>
								<div class="field"><label for="purchase-expiry-__INDEX__">Expiry date</label><input id="purchase-expiry-__INDEX__" name="items[__INDEX__][expiry_date]" type="date"></div>
								<div class="field"><label for="purchase-batch-__INDEX__">Batch number</label><input id="purchase-batch-__INDEX__" name="items[__INDEX__][batch_no]" maxlength="100"></div>
								<button class="purchase-remove" type="button" aria-label="Remove purchase row">Remove</button>
							</div>
						</template>

						<div class="purchase-actions">
							<button class="purchase-add" id="purchase-add-row" type="button">Add Row</button>
						</div>
						<section class="invoice-summary" aria-labelledby="invoice-summary-title">
							<h2 id="invoice-summary-title">Invoice summary</h2>
							<div><span>Total</span><strong id="purchase-total" aria-live="polite">₹0.00</strong></div>
						</section>
						<button class="purchase-save" type="submit">Save purchase</button>
					</form>
			</div>
		</section>
	<script>
		const purchaseRows = document.getElementById('purchase-rows');
		const purchaseRowTemplate = document.getElementById('purchase-row-template');
		const purchaseTotal = document.getElementById('purchase-total');
		const purchaseAddButton = document.getElementById('purchase-add-row');
		let nextPurchaseRowIndex = purchaseRows ? purchaseRows.querySelectorAll('[data-purchase-row]').length : 0;

		function updatePurchaseTotal() {
			if (!purchaseRows || !purchaseTotal) return;
			let totalCents = 0n;
			purchaseRows.querySelectorAll('[data-purchase-row]').forEach((row) => {
				const cost = row.querySelector('[name$="[cost_price]"]').value;
				const quantity = Number(row.querySelector('[name$="[quantity]"]').value) || 0;
				if (!/^\d+(?:\.\d{0,2})?$/.test(cost) || quantity <= 0) return;
				const [whole, fraction = ''] = cost.split('.');
				const unitCents = BigInt(whole) * 100n + BigInt((fraction + '00').slice(0, 2));
				totalCents += unitCents * BigInt(Math.trunc(quantity));
			});
			const wholeRupees = new Intl.NumberFormat('en-IN').format(totalCents / 100n);
			const paise = String(totalCents % 100n).padStart(2, '0');
			purchaseTotal.textContent = '₹' + wholeRupees + '.' + paise;
		}

		if (purchaseRows && purchaseRowTemplate && purchaseAddButton) {
			purchaseAddButton.addEventListener('click', () => {
				if (purchaseRows.querySelectorAll('[data-purchase-row]').length >= 100) return;
				const rowHtml = purchaseRowTemplate.innerHTML.replace(/__INDEX__/g, String(nextPurchaseRowIndex++));
				purchaseRows.insertAdjacentHTML('beforeend', rowHtml);
				purchaseRows.lastElementChild.querySelector('.purchase-sku-input').focus();
				purchaseAddButton.disabled = purchaseRows.querySelectorAll('[data-purchase-row]').length >= 100;
				updatePurchaseTotal();
			});

			purchaseRows.addEventListener('input', updatePurchaseTotal);
			purchaseRows.addEventListener('change', updatePurchaseTotal);
			purchaseRows.addEventListener('input', (event) => {
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
						const response = await fetch('direct_purchase.php?action=search_sku&q=' + encodeURIComponent(query), {
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
								option.textContent = match.sku + ' — WH-' + match.warehouse_id;
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
			purchaseRows.addEventListener('click', (event) => {
				const suggestion = event.target.closest('.purchase-suggestion');
				if (suggestion) {
					const row = suggestion.closest('[data-purchase-row]');
					const skuInput = row.querySelector('.purchase-sku-input');
					row.querySelector('.purchase-stock-id').value = suggestion.dataset.stockId;
					row.querySelector('.purchase-warehouse-id').value = suggestion.dataset.warehouseId;
					skuInput.value = suggestion.dataset.sku;
					row.querySelector('.purchase-selection').textContent = 'Selected warehouse: WH-' + suggestion.dataset.warehouseId;
					row.querySelector('.purchase-suggestions').hidden = true;
					skuInput.setAttribute('aria-expanded', 'false');
					return;
				}

				const removeButton = event.target.closest('.purchase-remove');
				if (!removeButton) return;
				const rows = purchaseRows.querySelectorAll('[data-purchase-row]');
				if (rows.length === 1) {
					rows[0].querySelectorAll('input').forEach((input) => { input.value = ''; });
					rows[0].querySelector('.purchase-selection').textContent = '';
					rows[0].querySelector('.purchase-suggestions').hidden = true;
				} else {
					removeButton.closest('[data-purchase-row]').remove();
				}
				purchaseAddButton.disabled = purchaseRows.querySelectorAll('[data-purchase-row]').length >= 100;
				updatePurchaseTotal();
			});
			updatePurchaseTotal();
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
