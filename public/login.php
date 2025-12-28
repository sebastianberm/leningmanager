<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/csrf.php';
verify_csrf();

if (is_logged_in()) { header('Location: '.BASE_PATH.'/'); exit; }
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    $q = $db->prepare("SELECT * FROM users WHERE email=?");
    $q->execute([$email]);
    $user = $q->fetch();
    if ($user && password_verify($pass, $user['password_hash'])) {
        $_SESSION['user'] = ['id'=>$user['id'], 'name'=>$user['name'], 'email'=>$user['email'], 'role'=>$user['role']];
        header('Location: '.BASEDIR.'/');
        exit;
    } else {
        $errors[] = "Onjuiste login.";
    }
}

include __DIR__ . '/partials_header.php';
?>
<div class="row justify-content-center">
  <div class="col-md-5">
    <div class="card p-4">
      <h2 class="mb-3">Inloggen</h2>
      <?php if (isset($_GET['setup'])): ?>
        <div class="alert alert-success">Admin aangemaakt. Log nu in.</div>
      <?php endif; ?>
      <?php if ($errors): ?><div class="alert alert-danger"><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div><?php endif; ?>
      <form method="post">
        <?php csrf_field(); ?>
        <div class="mb-3">
          <label class="form-label">E‑mail</label>
          <input class="form-control" name="email" type="email" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Wachtwoord</label>
          <input class="form-control" name="password" type="password" required>
        </div>
        <button class="btn btn-primary w-100">Inloggen</button>
      </form>
    </div>
  </div>
</div>
<?php include __DIR__ . '/partials_footer.php'; ?>
