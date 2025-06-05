<?php session_start(); ?>
<!doctype html>
<html>
<head><title>Sign In</title></head>
<body>
  <?php if(!empty($_SESSION['login_error'])): ?>
    <p style="color:red;"><?= $_SESSION['login_error'] ?></p>
    <?php unset($_SESSION['login_error']); endif; ?>

  <form action="authenticate.php" method="post">
    <label>Username<br>
      <input name="username" required autofocus>
    </label><br><br>
    <label>Password<br>
      <input name="password" type="password" required>
    </label><br><br>
    <button type="submit">Sign In</button>
  </form>
</body>
</html>