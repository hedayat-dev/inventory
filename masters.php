<?php
require_once __DIR__ . '/settings/routes.php';
require_once __DIR__ . '/auth.php';
require_login();

$connection = Database::getConnection();

function output(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$entity = $_POST['entity'] ?? '';
	$operation = $_POST['operation'] ?? '';
	$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
	$name = trim($_POST['name'] ?? '');
	$parentId = filter_input(INPUT_POST, 'parent_id', FILTER_VALIDATE_INT) ?: null;
	$brandId = filter_input(INPUT_POST, 'brand_id', FILTER_VALIDATE_INT) ?: null;

	$redirectMessage = '';
	$redirectType = 'success';

	try {
		if ($operation === 'delete') {
			if (!$id) {
				throw new RuntimeException('Nothing selected to delete.');
			}
			$table = match ($entity) {
				'category', 'subcategory' => 'vp_categories',
				'brand' => 'vp_brands',
				'model' => 'vp_models',
				default => null,
			};
			if (!$table) {
				throw new RuntimeException('Unknown record type.');
			}
			$deleteStatement = mysqli_prepare($connection, "DELETE FROM `$table` WHERE id = ?");
			mysqli_stmt_bind_param($deleteStatement, 'i', $id);
			mysqli_stmt_execute($deleteStatement);
			$redirectMessage = 'Record deleted successfully.';
		} elseif ($operation === 'add' || $operation === 'update') {
			if ($name === '') {
				throw new RuntimeException('Please enter a name.');
			}

			switch ($entity) {
				case 'category':
					if ($operation === 'add') {
						$statement = mysqli_prepare($connection, 'INSERT INTO vp_categories (name, parent_id) VALUES (?, NULL)');
						mysqli_stmt_bind_param($statement, 's', $name);
					} else {
						$statement = mysqli_prepare($connection, 'UPDATE vp_categories SET name = ? WHERE id = ?');
						mysqli_stmt_bind_param($statement, 'si', $name, $id);
					}
					mysqli_stmt_execute($statement);
					break;

				case 'subcategory':
					if ($parentId === null) {
						throw new RuntimeException('Please select a parent category.');
					}
					if ($operation === 'add') {
						$statement = mysqli_prepare($connection, 'INSERT INTO vp_categories (name, parent_id) VALUES (?, ?)');
						mysqli_stmt_bind_param($statement, 'si', $name, $parentId);
					} else {
						$statement = mysqli_prepare($connection, 'UPDATE vp_categories SET name = ?, parent_id = ? WHERE id = ?');
						mysqli_stmt_bind_param($statement, 'sii', $name, $parentId, $id);
					}
					mysqli_stmt_execute($statement);
					break;

				case 'brand':
					if ($operation === 'add') {
						$statement = mysqli_prepare($connection, 'INSERT INTO vp_brands (name) VALUES (?)');
						mysqli_stmt_bind_param($statement, 's', $name);
					} else {
						$statement = mysqli_prepare($connection, 'UPDATE vp_brands SET name = ? WHERE id = ?');
						mysqli_stmt_bind_param($statement, 'si', $name, $id);
					}
					mysqli_stmt_execute($statement);
					break;

				case 'model':
					if ($brandId === null) {
						throw new RuntimeException('Please select a brand.');
					}
					if ($operation === 'add') {
						$statement = mysqli_prepare($connection, 'INSERT INTO vp_models (name, brand_id) VALUES (?, ?)');
						mysqli_stmt_bind_param($statement, 'si', $name, $brandId);
					} else {
						$statement = mysqli_prepare($connection, 'UPDATE vp_models SET name = ?, brand_id = ? WHERE id = ?');
						mysqli_stmt_bind_param($statement, 'sii', $name, $brandId, $id);
					}
					mysqli_stmt_execute($statement);
					break;

				default:
					throw new RuntimeException('Unknown record type.');
			}

			$redirectMessage = 'Record saved successfully.';
		} else {
			throw new RuntimeException('Unknown action.');
		}
	} catch (Throwable $exception) {
		$redirectMessage = 'Could not save changes. ' . $exception->getMessage();
		$redirectType = 'error';
	}

	header('Location: masters.php?msg=' . urlencode($redirectMessage) . '&type=' . urlencode($redirectType) . '#' . ($entity ?: 'category'));
	exit;
}

$message = trim($_GET['msg'] ?? '');
$messageType = $_GET['type'] ?? 'success';

$topCategories = [];
$subcategories = [];
try {
	$categoryResult = mysqli_query($connection, 'SELECT id, name, parent_id FROM vp_categories ORDER BY name');
	while ($row = mysqli_fetch_assoc($categoryResult)) {
		if ($row['parent_id'] === null) {
			$topCategories[] = $row;
		} else {
			$subcategories[] = $row;
		}
	}
} catch (mysqli_sql_exception $exception) {
	// tables not installed yet
}
$categoriesById = [];
foreach ($topCategories as $categoryRow) {
	$categoriesById[(int) $categoryRow['id']] = $categoryRow;
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
	// tables not installed yet
}
$brandsById = [];
foreach ($brands as $brandRow) {
	$brandsById[(int) $brandRow['id']] = $brandRow;
}

$pageTitle = 'Master Management | Inventory Management';
$heading = 'Master Management';
$subtitle = 'Categories, subcategories, brands &amp; models';
$activeMasterLink = true;
$bodyClass = 'page-masters';

require __DIR__ . '/header.php';
?>
		<div class="grid-2" id="category">
			<section class="panel">
				<div class="panel-header"><h2>Categories</h2><span class="count"><?= count($topCategories) ?> records</span></div>
				<div class="panel-body overflow-x-auto">
					<form class="add-form" method="post" action="masters.php#category">
						<input type="hidden" name="entity" value="category">
						<input type="hidden" name="operation" value="add">
						<div class="flex w-full min-w-0 flex-row items-center gap-2">
						<input type="text" name="name" placeholder="New category name" required maxlength="150">
						<button type="submit">Add</button>
						</div>
					</form>
					<?php if (count($topCategories) === 0): ?>
						<div class="empty">No categories yet. Add one above.</div>
					<?php else: ?>
						<table class="records-table">
							<thead><tr><th>Category</th></tr></thead>
							<tbody>
								<?php foreach ($topCategories as $categoryRow): ?>
									<tr>
										<td class="record-cell">
											<form class="row-form" method="post" action="masters.php#category">
												<input type="hidden" name="entity" value="category">
												<input type="hidden" name="id" value="<?= (int) $categoryRow['id'] ?>">
												<div class="flex flex-row w-full min-w-0  items-center gap-2">
												<div class=""> <input type="text" class="record-input w-24" name="name" value="<?= output($categoryRow['name']) ?>" required maxlength="150"></div>
												<div class=""><button type="submit" class="icon-button" name="operation" value="update" aria-label="Save category" title="Save"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i></button></div>
												<div class=""><button type="submit" class="icon-button delete" name="operation" value="delete" aria-label="Delete category" title="Delete" onclick="return confirm('Delete this category and its subcategories?');"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></div>
												</div>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</section>

			<section class="panel" id="subcategory">
				<div class="panel-header"><h2>Subcategories</h2><span class="count"><?= count($subcategories) ?> records</span></div>
				<div class="panel-body overflow-x-auto">
					<form class="add-form" method="post" action="masters.php#subcategory">
						<input type="hidden" name="entity" value="subcategory">
						<input type="hidden" name="operation" value="add">
						<div class="flex w-full min-w-0 flex-row items-center gap-2">
						<div><select name="parent_id" required>
							<option value="">Select category</option>
							<?php foreach ($topCategories as $categoryOption): ?>
								<option value="<?= (int) $categoryOption['id'] ?>"><?= output($categoryOption['name']) ?></option>
							<?php endforeach; ?>
						</select></div>
						<div ><input class="record-input w-24" type="text" name="name" placeholder="New subcategory name" required maxlength="150"></div>
						<div><button type="submit">Add</button></div>
						</div>
					</form>
					<?php if (count($subcategories) === 0): ?>
						<div class="empty">No subcategories yet. Add one above.</div>
					<?php else: ?>
						<table class="records-table">
							<thead><tr><th>Subcategory</th></tr></thead>
							<tbody>
								<?php foreach ($subcategories as $subcategoryRow): ?>
									<tr>
										<td>
											<form class="row-form" method="post" action="masters.php#subcategory">
												<input type="hidden" name="entity" value="subcategory">
												<input type="hidden" name="id" value="<?= (int) $subcategoryRow['id'] ?>">
												<div class="flex w-full min-w-0 flex-row items-center gap-2">
												<div><select name="parent_id" required>
													<?php foreach ($topCategories as $categoryOption): ?>
														<option value="<?= (int) $categoryOption['id'] ?>" <?= (int) $subcategoryRow['parent_id'] === (int) $categoryOption['id'] ? 'selected' : '' ?>><?= output($categoryOption['name']) ?></option>
													<?php endforeach; ?>
												</select></div>
												<div><input type="text" name="name" value="<?= output($subcategoryRow['name']) ?>" required maxlength="150"></div>
												<div><button type="submit" class="icon-button" name="operation" value="update" aria-label="Save subcategory" title="Save"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i></button></div>
												<div><button type="submit" class="icon-button delete" name="operation" value="delete" aria-label="Delete subcategory" title="Delete" onclick="return confirm('Delete this subcategory?');"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></div>
												</div>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</section>
		

			
			<section class="panel">
				<div class="panel-header"><h2>Brands</h2><span class="count"><?= count($brands) ?> records</span></div>
				<div class="panel-body">
					<form class="add-form" method="post" action="masters.php#brand">
						<input type="hidden" name="entity" value="brand">
						<input type="hidden" name="operation" value="add">
						<div class="flex w-full min-w-0 flex-row items-center gap-2">
						<div><input type="text" name="name" placeholder="New brand name" required maxlength="150"></div>
						<div><button type="submit">Add</button></div>
						</div>
					</form>
					<?php if (count($brands) === 0): ?>
						<div class="empty">No brands yet. Add one above.</div>
					<?php else: ?>
						<table class="records-table">
							<thead><tr><th>Brand</th></tr></thead>
							<tbody>
								<?php foreach ($brands as $brandRow): ?>
									<tr>
										<td>
											<form class="row-form" method="post" action="masters.php#brand">
												<input type="hidden" name="entity" value="brand">
												<input type="hidden" name="id" value="<?= (int) $brandRow['id'] ?>">
												<div class="flex w-full min-w-0 flex-row items-center gap-2">
												<div><input type="text" name="name" value="<?= output($brandRow['name']) ?>" required maxlength="150"></div>
												<div><button type="submit" class="icon-button" name="operation" value="update" aria-label="Save brand" title="Save"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i></button></div>
												<div><button type="submit" class="icon-button delete" name="operation" value="delete" aria-label="Delete brand" title="Delete" onclick="return confirm('Delete this brand and its models?');"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></div>
												</div>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</section>

			<section class="panel" id="model">
				<div class="panel-header"><h2>Models</h2><span class="count"><?= count($models) ?> records</span></div>
				<div class="panel-body h-96 overflow-x-auto overflow-y-auto">
					<form class="add-form" method="post" action="masters.php#model">
						<input type="hidden" name="entity" value="model">
						<input type="hidden" name="operation" value="add">
						<div class="flex w-full min-w-0 flex-row items-center gap-2">
						<div><select name="brand_id" required>
							<option value="">Select brand</option>
							<?php foreach ($brands as $brandOption): ?>
								<option value="<?= (int) $brandOption['id'] ?>"><?= output($brandOption['name']) ?></option>
							<?php endforeach; ?>
						</select></div>
						<div><input type="text" name="name" placeholder="New model name" required maxlength="150"></div>
						<div><button type="submit">Add</button></div>
						</div>
					</form>
					<?php if (count($models) === 0): ?>
						<div class="empty">No models yet. Add one above.</div>
					<?php else: ?>
						<table class="records-table ">
							<thead><tr><th>Model</th></tr></thead>
							<tbody>
								<?php foreach ($models as $modelRow): ?>
									<tr>
										<td>
											<form class="row-form" method="post" action="masters.php#model">
												<input type="hidden" name="entity" value="model">
												<input type="hidden" name="id" value="<?= (int) $modelRow['id'] ?>">
												<div class="flex w-full min-w-0 flex-row items-center gap-2">
												<div><select name="brand_id" required>
													<?php foreach ($brands as $brandOption): ?>
														<option value="<?= (int) $brandOption['id'] ?>" <?= (int) $modelRow['brand_id'] === (int) $brandOption['id'] ? 'selected' : '' ?>><?= output($brandOption['name']) ?></option>
													<?php endforeach; ?>
												</select></div>
												<div><input type="text" name="name" value="<?= output($modelRow['name']) ?>" required maxlength="150"></div>
												<div><button type="submit" class="icon-button" name="operation" value="update" aria-label="Save model" title="Save"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i></button></div>
												<div><button type="submit" class="icon-button delete" name="operation" value="delete" aria-label="Delete model" title="Delete" onclick="return confirm('Delete this model?');"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></div>
												</div>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</section>
			
		</div>
	<?php
	$extraScript = '';
	require __DIR__ . '/footer.php';
	?>
