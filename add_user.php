<?php
require 'auth_check.php';            // blocks non-logged-in / non-admin
if ($_SESSION['role'] !== 'admin') {
  http_response_code(403);
  exit('Forbidden');
}
require 'secure/db_connection.php';  // your PDO $pdo

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = trim($_POST['username']);
  $p = $_POST['password'];
  $r = $_POST['role'];

  if ($u && $p && in_array($r, ['admin','manager','viewer'])) {
    $hash = password_hash($p, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
      'INSERT INTO users (username, password_hash, role)
       VALUES (?, ?, ?)'
    );
    try {
      $stmt->execute([$u, $hash, $r]);
      $message = "User '{$u}' added successfully.";
    } catch (PDOException $e) {
      $message = "Error: " . $e->getMessage();
    }
  } else {
    $message = 'All fields required, and role must be valid.';
  }
}
?>
<!doctype html>
<html>
<head><title>Add User</title></head>
<body>
  <?php if($message): ?>
    <p><?= htmlspecialchars($message) ?></p>
  <?php endif; ?>

  <form method="post">
    <label>Username:<br>
      <input name="username" required>
    </label><br><br>

    <label>Password:<br>
      <input name="password" type="password" required>
    </label><br><br>

    <label>Role:<br>
      <select name="role">
        <option value="viewer">Viewer</option>
        <option value="manager">Manager</option>
        <option value="admin">Admin</option>
      </select>
    </label><br><br>

    <button type="submit">Create User</button>
  </form>

  <p><a href="dashboard.php">← Back to dashboard</a></p>
</body>
</html>
