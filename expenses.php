<?php
$pageTitle='Expenses';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/finance_helpers.php';

if (is_super_admin()) redirect('dashboard.php');

$cid = current_company_id();
$bid = current_branch_id();

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    verify_csrf();

    $id = (int)($_POST['id'] ?? 0);
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $date = $_POST['expense_date'] ?? date('Y-m-d');

    if($id){
        $stmt = db()->prepare("UPDATE expenses SET category=?, description=?, amount=?, expense_date=? WHERE id=? AND company_id=? AND branch_id=?");
        $stmt->bind_param('ssdsiii', $category, $description, $amount, $date, $id, $cid, $bid);
        $stmt->execute();
        $stmt->close();
        flash('success', 'Expense updated.');
    } else {
        $stmt = db()->prepare("INSERT INTO expenses(company_id, branch_id, category, description, amount, expense_date) VALUES(?,?,?,?,?,?)");
        $stmt->bind_param('iissds', $cid, $bid, $category, $description, $amount, $date);
        $stmt->execute();
        $expenseId = $stmt->insert_id;
        $stmt->close();

        finance_post_expense(db(), [
            'entry_date'         => $date,
            'reference_no'       => 'EXP-' . $expenseId,
            'memo'               => 'Automatic posting from expense - ' . $category,
            'source_id'          => $expenseId,
            'branch_id'          => $_SESSION['branch_id'] ?? $bid ?? null,
            'company_id'         => $_SESSION['company_id'] ?? $cid ?? null,
            'created_by'         => $_SESSION['user']['id'] ?? null,
            'amount'             => $amount,
            'expense_account_id' => function_exists('finance_account_id') ? finance_account_id(db(), '6000') : null,
        ]);

        flash('success', 'Expense added.');
    }

    redirect('expenses.php');
}

if(isset($_GET['delete'])){
    db()->query("DELETE FROM expenses WHERE id=".(int)$_GET['delete']." AND company_id=$cid AND branch_id=$bid");
    flash('success','Expense deleted.');
    redirect('expenses.php');
}

$edit = isset($_GET['edit']) ? db()->query("SELECT * FROM expenses WHERE id=".(int)$_GET['edit']." AND company_id=$cid AND branch_id=$bid")->fetch_assoc() : null;
$from = $_GET['from'] ?? '';
$to   = $_GET['to'] ?? '';
$where = "WHERE company_id=$cid AND branch_id=$bid";
if($from) $where .= " AND expense_date>='".db()->real_escape_string($from)."'";
if($to)   $where .= " AND expense_date<='".db()->real_escape_string($to)."'";
$rows = db()->query("SELECT * FROM expenses $where ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);
?>
<div class="row g-4">
  <div class="col-lg-4">
    <div class="card-soft p-4">
      <h5><?php echo $edit ? 'Edit' : 'Add'; ?> Expense</h5>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">
        <div class="mb-2"><input name="category" class="form-control" placeholder="Category e.g. material, transport" required value="<?php echo e($edit['category'] ?? ''); ?>"></div>
        <div class="mb-2"><textarea name="description" class="form-control" placeholder="Description" required><?php echo e($edit['description'] ?? ''); ?></textarea></div>
        <div class="row g-2"><div class="col"><input type="number" step="0.01" name="amount" class="form-control" placeholder="Amount" required value="<?php echo e($edit['amount'] ?? ''); ?>"></div><div class="col"><input type="date" name="expense_date" class="form-control" required value="<?php echo e($edit['expense_date'] ?? date('Y-m-d')); ?>"></div></div>
        <button class="btn btn-primary mt-3 w-100"><?php echo $edit ? 'Update' : 'Save'; ?> Expense</button>
      </form>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card-soft p-4">
      <form class="row g-2 mb-3">
        <div class="col"><input type="date" name="from" class="form-control" value="<?php echo e($from); ?>"></div>
        <div class="col"><input type="date" name="to" class="form-control" value="<?php echo e($to); ?>"></div>
        <div class="col-auto"><button class="btn btn-outline-secondary">Filter</button></div>
      </form>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Amount</th><th></th></tr></thead>
          <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td><?php echo e($r['expense_date']); ?></td>
                <td><?php echo e($r['category']); ?></td>
                <td><?php echo e($r['description']); ?></td>
                <td><?php echo money($r['amount']); ?></td>
                <td class="text-end"><a href="?edit=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a> <a onclick="return confirm('Delete expense?')" href="?delete=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-danger">Delete</a></td>
              </tr>
            <?php endforeach; if(!$rows): ?>
              <tr><td colspan="5" class="text-center text-secondary">No expenses found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
