<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/functions.php';

verify_csrf();
require_login();
require_role(['admin','manager']);

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $name=trim($_POST['name']??'');
    $email=trim($_POST['email']??'');
    $role=$_POST['role']??'borrower';
    $pw=$_POST['password']??'';
    if ($name=='' || $email=='' || $pw=='') $errors[]='Alle velden zijn verplicht.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[]='Ongeldig e-mailadres.';
    if (!in_array($role, ['admin','manager','borrower'], true)) $errors[]='Ongeldige rol.';
    if (!$errors) {
        $hash=password_hash($pw, PASSWORD_DEFAULT);
        $ins=$db->prepare("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)");
        try { $ins->execute([$name,$email,$hash,$role]); header('Location: '.BASEDIR.'/users.php?ok=1'); exit; }
        catch (Exception $e){ $errors[]='Kan gebruiker niet opslaan: '.htmlspecialchars($e->getMessage()); }
    }
}

$users = $db->query("SELECT id,name,email,role,created_at FROM users ORDER BY created_at DESC")->fetchAll();

include __DIR__ . '/partials_header.php';
?>
<div class="row">
  <div class="col-lg-7">
    <div class="card p-3 mb-4">
      <h3 class="mb-3">Gebruikers</h3>
      <?php if (isset($_GET['ok'])): ?><div class="alert alert-success">Gebruiker aangemaakt.</div><?php endif; ?>
      <table class="table table-hover">
        <thead><tr><th>Naam</th><th>E‑mail</th><th>Rol</th><th>Aangemaakt</th></tr></thead>
        <tbody>
          <?php foreach($users as $u): ?>
            <tr>
              <td><?=h($u['name'])?></td>
              <td><?=h($u['email'])?></td>
              <td><span class="badge bg-secondary"><?=h($u['role'])?></span></td>
              <td><small><?=h($u['created_at'])?></small></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card p-3">
      <h3 class="mb-3">Nieuwe gebruiker</h3>
      <?php if ($errors): ?><div class="alert alert-danger"><?php echo implode('<br>', array_map('htmlspecialchars',$errors)); ?></div><?php endif; ?>
      <form method="post">
        <?php csrf_field(); ?>
        <div class="mb-3"><label class="form-label">Naam</label><input class="form-control" name="name" required></div>
        <div class="mb-3"><label class="form-label">E‑mail</label><input class="form-control" type="email" name="email" required></div>
        <div class="mb-3">
          <label class="form-label">Rol</label>
          <select class="form-select" name="role">
            <option value="borrower">borrower (alleen eigen lening)</option>
            <option value="manager">manager (alles beheren)</option>
            <option value="admin">admin</option>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Wachtwoord</label><input class="form-control" type="password" name="password" required></div>
        <button class="btn btn-primary">Aanmaken</button>
      </form>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials_footer.php'; ?>
