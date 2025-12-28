<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/functions.php';
verify_csrf();
require_login();

$u = current_user();
$is_staff = in_array($u['role'], ['admin','manager'], true);

$errors=[];
if ($is_staff && $_SERVER['REQUEST_METHOD']==='POST') {
    $name=trim($_POST['name']??'');
    $principal=(float)($_POST['principal']??0);
    $rate=(float)($_POST['rate']??0);
    $start_date=trim($_POST['start_date']??'');
    $term=(int)($_POST['term_months']??0);
    $type=$_POST['type']??'annuity';
    $borrower_id = $_POST['borrower_id'] ? (int)$_POST['borrower_id'] : null;
    if ($name=='' || $principal<=0 || $term<=0 || $start_date=='') $errors[]='Vul alle velden correct in.';
    if (!in_array($type, ['annuity','linear'], true)) $errors[]='Ongeldig type.';
    if (!$errors) {
        $ins=$db->prepare("INSERT INTO loans(owner_id, borrower_id, name, principal, rate, start_date, term_months, type) VALUES(?,?,?,?,?,?,?,?)");
        $ins->execute([$u['id'], $borrower_id, $name, $principal, $rate, $start_date, $term, $type]);
        header('Location: '.BASE_PATH .'/loans.php?ok=1'); exit;
    }
}

if ($is_staff) {
    $loans = $db->query("SELECT l.*, b.name AS borrower_name FROM loans l LEFT JOIN users b ON b.id = l.borrower_id ORDER BY l.created_at DESC")->fetchAll();
    $borrowers = $db->query("SELECT id,name FROM users WHERE role='borrower' ORDER BY name ASC")->fetchAll();
} else {
    // borrower: only their loans
    $stmt = $db->prepare("SELECT l.*, b.name AS borrower_name FROM loans l LEFT JOIN users b ON b.id = l.borrower_id WHERE borrower_id=? ORDER BY l.created_at DESC");
    $stmt->execute([$u['id']]);
    $loans = $stmt->fetchAll();
    $borrowers = [];
}

include __DIR__ . '/partials_header.php';
?>
<div class="row">
  <div class="col-lg-7">
    <div class="card p-3 mb-4">
      <h1 class="mb-3">Leningen</h1>
      <?php if (isset($_GET['ok'])): ?><div class="alert alert-success">Lening opgeslagen.</div><?php endif; ?>
      <table class="table table-hover">
        <thead><tr><th>Naam</th><th>Hoofdsom</th><th>Rente</th><th>Looptijd</th><th>Borrower</th><th></th></tr></thead>
        <tbody>
          <?php foreach($loans as $l): ?>
          <tr>
            <td><?=h($l['name'])?></td>
            <td><?=money_fmt($l['principal'])?></td>
            <td><?=h($l['rate'])?>%</td>
            <td><?=h($l['term_months'])?> mnd</td>
            <td><?=h($l['borrower_name'] ?? '—')?></td>
            <td><a class="btn btn-sm btn-secondary" href="<?= BASE_PATH ?>/loan.php?id=<?=$l['id']?>">Details</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if($is_staff): ?>
  <div class="col-lg-5">
    <div class="card p-3">
      <h3 class="mb-3">Nieuwe lening</h3>
      <?php if ($errors): ?><div class="alert alert-danger"><?php echo implode('<br>', array_map('htmlspecialchars',$errors)); ?></div><?php endif; ?>
      <form method="post">
        <?php csrf_field(); ?>
        <div class="mb-3"><label class="form-label">Naam</label><input class="form-control" name="name" placeholder="Naam van lening"></div>
        <div class="mb-3"><label class="form-label">Hoofdsom (€)</label><input class="form-control" name="principal" type="number" step="0.01"></div>
        <div class="mb-3"><label class="form-label">Rente (% per jaar)</label><input class="form-control" name="rate" type="number" step="0.0001"></div>
        <div class="mb-3"><label class="form-label">Startdatum</label><input class="form-control" name="start_date" type="date"></div>
        <div class="mb-3"><label class="form-label">Looptijd (maanden)</label><input class="form-control" name="term_months" type="number"></div>
        <div class="mb-3">
          <label class="form-label">Type</label>
          <select class="form-select" name="type">
            <option value="annuity">Annuïtair</option>
            <option value="linear">Lineair</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Borrower (optioneel)</label>
          <select class="form-select" name="borrower_id">
            <option value="">—</option>
            <?php foreach($borrowers as $b): ?>
              <option value="<?=$b['id']?>"><?=h($b['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-primary">Opslaan</button>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/partials_footer.php'; ?>
