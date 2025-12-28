<?php
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/csrf.php';
verify_csrf();

$stmt = $db->query("SELECT COUNT(*) as c FROM users");
$count = (int)$stmt->fetch()['c'];

if ($count > 0) {
    header('Location: '. BASE_PATH . '/login.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    if ($name === '' || $email === '' || $pass === '') $errors[] = "Alle velden zijn verplicht.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Ongeldig e-mailadres.";

    if (!$errors) {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $ins = $db->prepare("INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,'admin')");
        try {
            $ins->execute([$name, $email, $hash]);
            header('Location: '.BASEDIR.' /login.php?setup=1');
            exit;
        } catch (Exception $e) {
            $errors[] = "Kon admin niet aanmaken: " . htmlspecialchars($e->getMessage());
        }
    }
}

include __DIR__ . '/partials_header.php';
?>
<div class="card p-4">
  <h1>Eerste gebruiker (admin) aanmaken</h1>
  <?php if ($errors): ?>
    <div class="alert alert-danger"><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div>
  <?php endif; ?>
  <form method="post">
    <?php csrf_field(); ?>
    <div class="mb-3">
      <label class="form-label">Naam</label>
      <input class="form-control" name="name" required>
    </div>
    <div class="mb-3">
      <label class="form-label">E‑mail</label>
      <input class="form-control" name="email" type="email" required>
    </div>
    <div class="mb-3">
      <label class="form-label">Wachtwoord</label>
      <input class="form-control" name="password" type="password" required>
    </div>
    <button class="btn btn-primary">Admin aanmaken</button>
  </form>
</div>
<?php include __DIR__ . '/partials_footer.php'; ?>
